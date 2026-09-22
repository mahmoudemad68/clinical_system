<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;

/**
 * Authenticated private branch projection. Owner responses may include
 * decrypted address and coordinates. Operator responses omit those fields.
 * Phone is write-only. Never placed in events, logs, metrics, cache keys,
 * URLs, or audit metadata.
 *
 * @phpstan-type ProjectionArray array{
 *     branch_id: string,
 *     organization_id: string,
 *     public_name: string,
 *     country_code: string,
 *     status: string,
 *     version: int,
 *     created_at: string,
 *     updated_at: string,
 *     address?: string,
 *     latitude?: float,
 *     longitude?: float
 * }
 */
final readonly class PharmacyBranchPrivateProjection
{
    public function __construct(
        public string $branchId,
        public string $organizationId,
        public string $publicName,
        public string $countryCode,
        public PharmacyBranchStatus $status,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $address,
        public ?float $latitude,
        public ?float $longitude,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        $utc = new DateTimeZone('UTC');
        $payload = [
            'branch_id' => $this->branchId,
            'organization_id' => $this->organizationId,
            'public_name' => $this->publicName,
            'country_code' => $this->countryCode,
            'status' => $this->status->value,
            'version' => $this->version,
            'created_at' => $this->createdAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'updated_at' => $this->updatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
        ];

        if ($this->address !== null && $this->latitude !== null && $this->longitude !== null) {
            $payload['address'] = $this->address;
            $payload['latitude'] = $this->latitude;
            $payload['longitude'] = $this->longitude;
        }

        return $payload;
    }
}
