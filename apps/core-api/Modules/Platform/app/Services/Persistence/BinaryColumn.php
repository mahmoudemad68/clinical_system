<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Persistence;

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
            $hex = substr($value, 2);
            // PDO may already return raw bytea. Ciphertext/HMAC that happens
            // to start with `\x` is not hex. PHP 8 emits a warning (ErrorException
            // under Laravel) instead of returning false, so validate first.
            if ($hex !== '' && strlen($hex) % 2 === 0 && ctype_xdigit($hex)) {
                $decoded = hex2bin($hex);

                return $decoded === false ? $value : $decoded;
            }
        }

        return $value;
    }
}
