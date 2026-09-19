<?php

declare(strict_types=1);

namespace Modules\Verification\Enums;

enum VerificationUploadState: string
{
    case Requested = 'requested';
    case Uploading = 'uploading';
    case Quarantined = 'quarantined';
    case Validating = 'validating';
    case Scanning = 'scanning';
    case Available = 'available';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return $this === self::Available || $this === self::Rejected;
    }

    public function isProcessable(): bool
    {
        return in_array($this, [self::Quarantined, self::Validating, self::Scanning], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
