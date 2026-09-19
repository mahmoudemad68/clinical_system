<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\ApplicantType;
use Modules\Verification\Enums\VerificationCaseStatus;
use Modules\Verification\Enums\VerificationCaseType;

final readonly class VerificationCaseRecord
{
    public function __construct(
        public Identifier $id,
        public ApplicantType $applicantType,
        public Identifier $applicantId,
        public VerificationCaseType $caseType,
        public VerificationCaseStatus $status,
        public ?DateTimeImmutable $submittedAt,
        public ?Identifier $assignedReviewerId,
        public ?DateTimeImmutable $decidedAt,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
