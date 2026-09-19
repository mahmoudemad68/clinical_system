<?php

declare(strict_types=1);

namespace Modules\Verification\Enums;

enum VerificationCaseStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::PendingReview;
    }

    public function isDecided(): bool
    {
        return $this === self::Approved
            || $this === self::Rejected
            || $this === self::ChangesRequested;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::PendingReview,
            self::PendingReview => in_array($target, [self::Approved, self::Rejected, self::ChangesRequested], true),
            self::ChangesRequested, self::Approved, self::Rejected => false,
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
