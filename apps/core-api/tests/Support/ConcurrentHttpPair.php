<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Starts two independent PHP processes that each boot Laravel and issue one
 * HTTP request after a shared file barrier. That is two application connections
 * and two PostgreSQL sessions, not sequential calls in one PDO.
 */
final class ConcurrentHttpPair
{
    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{left: array<string, mixed>, right: array<string, mixed>}
     */
    public static function run(array $left, array $right, float $timeoutSeconds = 20.0): array
    {
        $dir = sys_get_temp_dir().'/clinic-race-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create race barrier directory.');
        }

        $go = $dir.'/go';
        $left['ready_path'] = $dir.'/ready-L';
        $right['ready_path'] = $dir.'/ready-R';
        $left['go_path'] = $go;
        $right['go_path'] = $go;

        $leftProc = self::start($left);
        $rightProc = self::start($right);

        try {
            self::waitForFile($left['ready_path'], 10.0);
            self::waitForFile($right['ready_path'], 10.0);
            touch($go);

            return [
                'left' => self::finish($leftProc, $timeoutSeconds),
                'right' => self::finish($rightProc, $timeoutSeconds),
            ];
        } finally {
            self::cleanupDir($dir);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{process: resource, stdout: resource, stderr: resource, stdin: resource}
     */
    private static function start(array $payload): array
    {
        $worker = base_path('tests/Support/bin/auth-race-worker.php');
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $spec, $pipes, base_path());
        if (! is_resource($process)) {
            throw new RuntimeException('proc_open failed for auth race worker.');
        }

        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    /**
     * @param  array{process: resource, stdout: resource, stderr: resource}  $proc
     * @return array<string, mixed>
     */
    private static function finish(array $proc, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';

        while (microtime(true) < $deadline) {
            $stdout .= stream_get_contents($proc['stdout']) ?: '';
            $stderr .= stream_get_contents($proc['stderr']) ?: '';
            $status = proc_get_status($proc['process']);
            if ($status === false || $status['running'] === false) {
                break;
            }
            usleep(5_000);
        }

        $status = proc_get_status($proc['process']);
        if (is_array($status) && $status['running'] === true) {
            proc_terminate($proc['process'], 9);
            $stderr .= 'worker_timeout';
        }

        $stdout .= stream_get_contents($proc['stdout']) ?: '';
        $stderr .= stream_get_contents($proc['stderr']) ?: '';
        fclose($proc['stdout']);
        fclose($proc['stderr']);
        proc_close($proc['process']);

        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'worker_unreadable',
                'stderr' => self::redact($stderr),
                'sqlstate' => null,
            ];
        }

        $decoded['stderr'] = self::redact($stderr);

        return $decoded;
    }

    private static function waitForFile(string $path, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Race worker did not reach the start barrier.');
            }
            usleep(1_000);
        }
    }

    private static function cleanupDir(string $dir): void
    {
        foreach (['go', 'ready-L', 'ready-R'] as $name) {
            $path = $dir.'/'.$name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function redact(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9_\-]{20,}/', '[redacted]', $text) ?? $text;

        return substr($text, 0, 500);
    }
}
