<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationCaseType;

/**
 * Closed reviewer-queue filters. Unknown assignment, case type, or status deny.
 */
final readonly class ReviewerQueueFilters
{
    public const ASSIGNMENT_UNASSIGNED = 'unassigned';

    public const ASSIGNMENT_MINE = 'mine';

    public const ASSIGNMENT_ALL = 'all';

    public const ORDERING = ['submitted_at', 'id'];

    public function __construct(
        public string $assignment,
        public VerificationCaseType $caseType,
        public VerificationCaseStatus $status,
        public int $limit,
    ) {
        if (! in_array($assignment, [self::ASSIGNMENT_UNASSIGNED, self::ASSIGNMENT_MINE, self::ASSIGNMENT_ALL], true)) {
            throw new InvalidValueObject('Queue assignment filter is not allowed.');
        }
        if ($status !== VerificationCaseStatus::PendingReview) {
            throw new InvalidValueObject('Queue status filter is not allowed.');
        }
        if ($limit < 1) {
            throw new InvalidValueObject('Queue page size is not allowed.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function cursorFilterMap(): array
    {
        return [
            'assignment' => $this->assignment,
            'case_type' => $this->caseType->value,
            'status' => $this->status->value,
        ];
    }
}
