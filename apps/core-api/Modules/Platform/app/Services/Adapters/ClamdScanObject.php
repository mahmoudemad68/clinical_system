<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Adapters;

use Modules\Platform\Contracts\ScanObject;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\BoundedSocketWriter;
use Modules\Platform\Support\ScanVerdict;
use RuntimeException;

/**
 * clamd INSTREAM adapter. Operates only on a caller-opened stream.
 *
 * Does not accept URLs. Does not receive storage credentials. Timeouts,
 * protocol errors, empty replies, and unknown responses fail closed.
 */
final class ClamdScanObject implements ScanObject
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutMs,
        private readonly int $maxBytes,
        private readonly string $scannerVersion,
    ) {}

    public function scanStream(mixed $stream, int $sizeBytes): ScanVerdict
    {
        if (! is_resource($stream)) {
            return ScanVerdict::invalid('clamd', $this->scannerVersion);
        }

        if ($sizeBytes < 1 || $sizeBytes > $this->maxBytes) {
            return ScanVerdict::invalid('clamd', $this->scannerVersion);
        }

        $timeoutSeconds = max(1, (int) ceil($this->timeoutMs / 1000));
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://'.$this->host.':'.$this->port,
            $errno,
            $errstr,
            $timeoutSeconds,
        );
        if (! is_resource($socket)) {
            return ScanVerdict::unavailable('clamd', $this->scannerVersion);
        }

        stream_set_timeout($socket, $timeoutSeconds);

        try {
            if (! BoundedSocketWriter::writeAll($socket, "nINSTREAM\n")) {
                return ScanVerdict::unavailable('clamd', $this->scannerVersion);
            }

            $sent = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    return ScanVerdict::unavailable('clamd', $this->scannerVersion);
                }
                if ($chunk === '') {
                    break;
                }
                $sent += strlen($chunk);
                if ($sent > $this->maxBytes || $sent > $sizeBytes) {
                    return ScanVerdict::invalid('clamd', $this->scannerVersion);
                }
                $frame = pack('N', strlen($chunk)).$chunk;
                if (! BoundedSocketWriter::writeAll($socket, $frame)) {
                    return ScanVerdict::unavailable('clamd', $this->scannerVersion);
                }
            }

            if ($sent !== $sizeBytes) {
                return ScanVerdict::invalid('clamd', $this->scannerVersion);
            }

            if (! BoundedSocketWriter::writeAll($socket, pack('N', 0))) {
                return ScanVerdict::unavailable('clamd', $this->scannerVersion);
            }

            $reply = @stream_get_contents($socket);
            if (! is_string($reply) || trim($reply) === '') {
                return ScanVerdict::unavailable('clamd', $this->scannerVersion);
            }

            return $this->interpret($reply);
        } catch (RuntimeException|InvalidValueObject) {
            return ScanVerdict::unavailable('clamd', $this->scannerVersion);
        } finally {
            fclose($socket);
        }
    }

    private function interpret(string $reply): ScanVerdict
    {
        $line = trim($reply);
        if ($line === 'OK' || str_ends_with($line, 'OK')) {
            return ScanVerdict::clean('clamd', $this->scannerVersion);
        }

        if (str_contains($line, 'FOUND')) {
            return ScanVerdict::infected('clamd', $this->scannerVersion);
        }

        if (str_contains($line, 'ERROR') || str_contains($line, 'INSTREAM size limit exceeded')) {
            return ScanVerdict::unavailable('clamd', $this->scannerVersion);
        }

        return ScanVerdict::invalid('clamd', $this->scannerVersion);
    }
}
