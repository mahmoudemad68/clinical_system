<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Minimal Pusher-protocol WebSocket client for live Laravel Reverb.
 *
 * Measures actual TCP/WebSocket close. Not a mock and not an HTTP probe.
 */
final class ReverbPusherClient
{
    /** @var resource */
    private $stream;

    public string $socketId = '';

    /**
     * @param  resource  $stream
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function connect(
        string $host,
        int $port,
        string $appKey,
        string $origin,
        float $timeoutSeconds = 5.0,
    ): self {
        $remote = 'tcp://'.$host.':'.$port;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        if (! is_resource($stream)) {
            throw new RuntimeException('Reverb TCP connect failed: '.$errstr);
        }

        stream_set_blocking($stream, true);

        $key = base64_encode(random_bytes(16));
        $path = '/app/'.rawurlencode($appKey).'?protocol=7&client=php-slo&version=1.0.0&flash=false';
        $handshake = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}:{$port}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."Origin: {$origin}\r\n"
            ."\r\n";

        fwrite($stream, $handshake);

        $response = '';
        $deadline = microtime(true) + $timeoutSeconds;
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = self::readBytes($stream, 1, $deadline);
            if ($chunk === null) {
                fclose($stream);
                throw new RuntimeException('Reverb WebSocket handshake timed out.');
            }
            $response .= $chunk;
            if (strlen($response) > 8192) {
                fclose($stream);
                throw new RuntimeException('Reverb WebSocket handshake overflowed.');
            }
        }

        if (! str_contains($response, ' 101 ')) {
            fclose($stream);
            $snippet = trim(strtok($response, "\r\n") ?: $response);
            throw new RuntimeException('Reverb WebSocket upgrade refused: '.$snippet);
        }

        $client = new self($stream);
        $established = $client->waitForEvent('pusher:connection_established', $timeoutSeconds);
        $data = $established['data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        $socketId = (string) ($data['socket_id'] ?? '');
        if ($socketId === '') {
            $client->close();
            throw new RuntimeException('Reverb did not return a socket_id.');
        }
        $client->socketId = $socketId;

        return $client;
    }

    public function subscribePrivate(string $channel, string $auth, float $timeoutSeconds = 5.0): void
    {
        $this->sendJson([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => $channel,
                'auth' => $auth,
            ],
        ]);

        $this->waitForEvent('pusher_internal:subscription_succeeded', $timeoutSeconds, $channel);
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForEvent(string $event, float $timeoutSeconds, ?string $channel = null): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $frame = $this->readFrame($deadline);
            if ($frame === null) {
                throw new RuntimeException('Timed out waiting for '.$event);
            }
            if ($frame['opcode'] === 8) {
                throw new RuntimeException('WebSocket closed while waiting for '.$event);
            }
            if ($frame['opcode'] === 9) {
                $this->writeFrame(10, $frame['payload']);

                continue;
            }
            if ($frame['opcode'] !== 1) {
                continue;
            }

            $decoded = json_decode($frame['payload'], true);
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['event'] ?? '') === 'pusher:error') {
                throw new RuntimeException('Reverb pusher:error '.$frame['payload']);
            }
            if (($decoded['event'] ?? '') !== $event) {
                continue;
            }
            if ($channel !== null && ($decoded['channel'] ?? '') !== $channel) {
                continue;
            }

            return $decoded;
        }
    }

    public function waitUntilClosed(float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if (! is_resource($this->stream) || feof($this->stream)) {
                return true;
            }
            $frame = $this->readFrame($deadline);
            if ($frame === null) {
                return ! is_resource($this->stream) || feof($this->stream);
            }
            if ($frame['opcode'] === 8) {
                $this->close();

                return true;
            }
            if ($frame['opcode'] === 9) {
                $this->writeFrame(10, $frame['payload']);
            }
        }
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream) && ! feof($this->stream);
    }

    public function close(): void
    {
        if (! is_resource($this->stream)) {
            return;
        }

        try {
            $this->writeFrame(8, '');
        } catch (RuntimeException) {
            // Already gone.
        }

        fclose($this->stream);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendJson(array $payload): void
    {
        $this->writeFrame(1, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{opcode: int, payload: string}|null
     */
    private function readFrame(float $deadline): ?array
    {
        $header = self::readBytes($this->stream, 2, $deadline);
        if ($header === null) {
            return null;
        }

        $opcode = ord($header[0]) & 0x0F;
        $masked = (ord($header[1]) & 0x80) !== 0;
        $length = ord($header[1]) & 0x7F;

        if ($length === 126) {
            $ext = self::readBytes($this->stream, 2, $deadline);
            if ($ext === null) {
                return null;
            }
            $unpacked = unpack('n', $ext);
            $length = is_array($unpacked) ? (int) $unpacked[1] : 0;
        } elseif ($length === 127) {
            $ext = self::readBytes($this->stream, 8, $deadline);
            if ($ext === null) {
                return null;
            }
            $unpacked = unpack('J', $ext);
            $length = is_array($unpacked) ? (int) $unpacked[1] : 0;
        }

        $mask = '';
        if ($masked) {
            $maskBytes = self::readBytes($this->stream, 4, $deadline);
            if ($maskBytes === null) {
                return null;
            }
            $mask = $maskBytes;
        }

        $payload = $length === 0 ? '' : self::readBytes($this->stream, $length, $deadline);
        if ($payload === null) {
            return null;
        }

        if ($masked) {
            $decoded = '';
            for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        return ['opcode' => $opcode, 'payload' => $payload];
    }

    private function writeFrame(int $opcode, string $payload): void
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('WebSocket is closed.');
        }

        $len = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));
        if ($len < 126) {
            $header .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $header .= chr(0x80 | 126).pack('n', $len);
        } else {
            $header .= chr(0x80 | 127).pack('J', $len);
        }

        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        $written = fwrite($this->stream, $header.$mask.$masked);
        if ($written === false) {
            throw new RuntimeException('WebSocket write failed.');
        }
    }

    /**
     * @param  resource  $stream
     */
    private static function readBytes($stream, int $needed, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $needed) {
            $timeout = $deadline - microtime(true);
            if ($timeout <= 0) {
                return $buf === '' ? null : null;
            }

            $read = [$stream];
            $write = null;
            $except = null;
            $seconds = (int) $timeout;
            $micros = (int) round(($timeout - $seconds) * 1_000_000);
            if ($micros >= 1_000_000) {
                $seconds++;
                $micros -= 1_000_000;
            }

            $selected = @stream_select($read, $write, $except, $seconds, max(0, $micros));
            if ($selected === false || $selected === 0) {
                return null;
            }
            if (feof($stream)) {
                return null;
            }

            $chunk = fread($stream, $needed - strlen($buf));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
