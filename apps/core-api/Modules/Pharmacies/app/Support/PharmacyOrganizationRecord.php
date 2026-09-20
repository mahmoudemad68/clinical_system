<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeImmutable;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Pharmacies module.
 */
final readonly class PharmacyOrganizationRecord
{
    public function __construct(
        public Identifier $id,
        public string $legalNameCiphertext,
        public int $legalNameKeyVersion,
        public string $publicName,
        public string $registrationCiphertext,
        public string $registrationLookupHmac,
        public int $registrationKeyVersion,
        public PharmacyVerificationStatus $verificationStatus,
        public PharmacyOrganizationStatus $status,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
