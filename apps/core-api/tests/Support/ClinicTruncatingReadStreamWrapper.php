<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Test-only read wrapper. Observe-path hashing uses the real object; openStream
 * can then truncate or return fread false before the trusted size is reached.
 */
final class ClinicTruncatingReadStreamWrapper
{
    public mixed $context;

    /** @var list<array{inner: resource, limit: int, fail: bool}> */
    private static array $queue = [];

    /** @var resource */
    private mixed $inner;

    private int $limit = 0;

    private int $read = 0;

    private bool $failAfterLimit = false;

    /**
     * @param  resource  $inner
     * @return resource
     */
    public static function wrap($inner, int $limit, bool $failAfterLimit = false)
    {
        if (! in_array('clinictruncate', stream_get_wrappers(), true)) {
            stream_wrapper_register('clinictruncate', self::class);
        }

        self::$queue[] = [
            'inner' => $inner,
            'limit' => max(0, $limit),
            'fail' => $failAfterLimit,
        ];

        $stream = fopen('clinictruncate://download', 'r');
        if (! is_resource($stream)) {
            throw new \RuntimeException('Truncating test stream could not be opened.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        unset($path, $mode, $options, $opened_path);
        $job = array_shift(self::$queue);
        if ($job === null) {
            return false;
        }

        $this->inner = $job['inner'];
        $this->limit = $job['limit'];
        $this->failAfterLimit = $job['fail'];

        return is_resource($this->inner);
    }

    public function stream_read(int $count): string|false
    {
        if ($this->read >= $this->limit) {
            return $this->failAfterLimit ? false : '';
        }

        $want = min($count, $this->limit - $this->read);
        $chunk = fread($this->inner, $want);
        if ($chunk === false) {
            return false;
        }

        $this->read += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->read >= $this->limit || (is_resource($this->inner) && feof($this->inner));
    }

    /**
     * @return array<int|string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        if (is_resource($this->inner)) {
            fclose($this->inner);
        }
    }
}
