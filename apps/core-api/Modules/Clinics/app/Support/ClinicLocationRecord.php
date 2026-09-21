<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Clinics module.
 */
final readonly class ClinicLocationRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $doctorId,
        public string $publicName,
        public string $addressCiphertext,
        public int $addressKeyVersion,
        public string $countryCode,
        public float $latitude,
        public float $longitude,
        public ClinicLocationStatus $status,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
