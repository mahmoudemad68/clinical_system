<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinics\Enums\ClinicLocationStatus;

/**
 * Authenticated private location projection. Owner responses may include
 * decrypted address and coordinates. Staff responses omit those fields.
 * Never placed in events, logs, metrics, cache keys, URLs, or audit metadata.
 *
 * @phpstan-type ProjectionArray array{
 *     location_id: string,
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
final readonly class ClinicLocationPrivateProjection
{
    public function __construct(
        public string $locationId,
        public string $publicName,
        public string $countryCode,
        public ClinicLocationStatus $status,
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
            'location_id' => $this->locationId,
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
