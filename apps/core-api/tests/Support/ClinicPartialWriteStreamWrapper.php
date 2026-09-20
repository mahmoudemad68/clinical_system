<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stream wrapper that writes at most one byte per fwrite, or fails immediately.
 */
final class ClinicPartialWriteStreamWrapper
{
    public $context;

    private string $mode = 'ok';

    private string $buffer = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        unset($mode, $options, $opened_path);
        $this->mode = str_contains($path, 'fail') ? 'fail' : 'ok';

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->mode === 'fail') {
            return 0;
        }

        if ($data === '') {
            return 0;
        }

        $this->buffer .= $data[0];

        return 1;
    }

    public function stream_read(int $count): string
    {
        unset($count);

        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void {}
}
