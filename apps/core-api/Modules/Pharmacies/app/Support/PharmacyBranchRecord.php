<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeImmutable;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Pharmacies module.
 */
final readonly class PharmacyBranchRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $organizationId,
        public string $publicName,
        public string $addressCiphertext,
        public int $addressKeyVersion,
        public string $countryCode,
        public float $latitude,
        public float $longitude,
        public string $phoneCiphertext,
        public int $phoneKeyVersion,
        public PharmacyBranchStatus $status,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
