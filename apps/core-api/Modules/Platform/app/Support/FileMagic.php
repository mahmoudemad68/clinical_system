<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

/**
 * Magic-byte media detection. Extensions and client Content-Type are ignored.
 */
final class FileMagic
{
    public const PDF = 'application/pdf';

    public const JPEG = 'image/jpeg';

    public const PNG = 'image/png';

    public static function detect(string $prefix): ?string
    {
        if (str_starts_with($prefix, '%PDF-')) {
            return self::PDF;
        }

        if (strlen($prefix) >= 3 && $prefix[0] === "\xFF" && $prefix[1] === "\xD8" && $prefix[2] === "\xFF") {
            return self::JPEG;
        }

        if (str_starts_with($prefix, "\x89PNG\r\n\x1A\n")) {
            return self::PNG;
        }

        return null;
    }
}
