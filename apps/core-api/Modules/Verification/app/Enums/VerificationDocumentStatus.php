<?php

declare(strict_types=1);

namespace Modules\Verification\Enums;

enum VerificationDocumentStatus: string
{
    case Quarantined = 'quarantined';
    case Available = 'available';
    case Rejected = 'rejected';
    case Retired = 'retired';

    public function isReviewable(VerificationDocumentScanStatus $scanStatus): bool
    {
        return $this === self::Available && $scanStatus === VerificationDocumentScanStatus::Clean;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
