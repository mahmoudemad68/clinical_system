<?php

declare(strict_types=1);

namespace Modules\Doctors\Enums;

enum DoctorVerificationStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function confersClinicalCapability(): bool
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

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::PendingReview,
            self::PendingReview => in_array($target, [self::Approved, self::Rejected, self::ChangesRequested], true),
            self::ChangesRequested, self::Rejected => $target === self::PendingReview,
            self::Approved, self::Suspended => false,
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
