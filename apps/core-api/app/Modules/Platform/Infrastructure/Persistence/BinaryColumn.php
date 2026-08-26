<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

/**
 * PostgreSQL bytea values cannot travel as raw PHP strings through PDO: the
 * driver encodes parameters as UTF-8 text, so a HMAC/ciphertext byte is
 * rejected as an invalid UTF-8 sequence.
 *
 * Bind as the PostgreSQL hex format (`\x` + hex). Read back to raw bytes.
 */
final class BinaryColumn
{
    public static function bind(string $binary): string
    {
        return '\\x'.bin2hex($binary);
    }

    public static function asString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            $value = is_string($contents) ? $contents : '';
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));

            return $decoded === false ? $value : $decoded;
        }

        return $value;
    }
}
