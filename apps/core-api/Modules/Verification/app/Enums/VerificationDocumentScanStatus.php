<?php

declare(strict_types=1);

namespace Modules\Verification\Enums;

enum VerificationDocumentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
