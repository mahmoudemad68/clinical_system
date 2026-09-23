<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use DateTimeImmutable;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorSourceType;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Doctors module.
 */
final readonly class DoctorProfileRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $userId,
        public string $nationalIdCiphertext,
        public string $nationalIdLookupHmac,
        public int $nationalIdKeyVersion,
        public ?string $syndicateNumberCiphertext,
        public ?string $syndicateNumberLookupHmac,
        public ?int $syndicateNumberKeyVersion,
        public Identifier $specialtyId,
        public string $professionalDisplayName,
        public DoctorVerificationStatus $verificationStatus,
        public DoctorPublicStatus $publicStatus,
        public DoctorSourceType $sourceType,
        public Identifier $createdByUserId,
        public int $version,
        public ?DateTimeImmutable $approvedAt,
        public ?DateTimeImmutable $suspendedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
