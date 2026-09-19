<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use DateTimeImmutable;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Platform\Support\Identifier;

/**
 * Cross-module applicant view. No National ID, syndicate, ciphertext, HMAC,
 * or key-version fields.
 */
final readonly class DoctorApplicantProjection
{
    public function __construct(
        public Identifier $doctorId,
        public Identifier $userId,
        public DoctorVerificationStatus $verificationStatus,
        public DoctorPublicStatus $publicStatus,
        public int $version,
        public ?DateTimeImmutable $approvedAt,
        public ?DateTimeImmutable $suspendedAt,
    ) {}
}
