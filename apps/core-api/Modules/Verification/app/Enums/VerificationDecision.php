<?php

declare(strict_types=1);

namespace Modules\Verification\Enums;

enum VerificationDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';

    public function resultingCaseStatus(): VerificationCaseStatus
    {
        return match ($this) {
            self::Approved => VerificationCaseStatus::Approved,
            self::Rejected => VerificationCaseStatus::Rejected,
            self::ChangesRequested => VerificationCaseStatus::ChangesRequested,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $decision): string => $decision->value, self::cases());
    }
}
