<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

use DateTimeImmutable;
use Modules\Patients\Enums\PatientStatus;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Patients module.
 */
final readonly class PatientProfileRecord
{
    public function __construct(
        public Identifier $id,
        public ?Identifier $userId,
        public string $nationalIdCiphertext,
        public string $nationalIdLookupHmac,
        public int $nationalIdKeyVersion,
        public string $fullNameCiphertext,
        public string $gender,
        public ?string $dateOfBirth,
        public ?string $heightCm,
        public ?string $weightKg,
        public ?string $maritalStatus,
        public ?string $bloodType,
        public PatientStatus $status,
        public string $createdByType,
        public Identifier $createdById,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
