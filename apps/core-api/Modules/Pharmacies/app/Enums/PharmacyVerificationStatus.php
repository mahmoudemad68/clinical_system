<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Enums;

enum PharmacyVerificationStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /**
     * Verification status is never an inventory, POS, catalog, or clinical grant.
     */
    public function confersBusinessCapability(): bool
    {
        return false;
    }

    public function allowsNewVerificationCase(): bool
    {
        return match ($this) {
            self::Draft, self::ChangesRequested, self::Rejected => true,
            self::PendingReview, self::Approved, self::Suspended => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
