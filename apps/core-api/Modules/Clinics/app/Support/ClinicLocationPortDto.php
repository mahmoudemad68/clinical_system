<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Platform\Support\Identifier;

/**
 * Narrow Phase-03 port DTO. No Eloquent, address, or coordinates.
 */
final readonly class ClinicLocationPortDto
{
    public function __construct(
        public Identifier $locationId,
        public Identifier $doctorId,
        public ClinicLocationStatus $status,
        public int $version,
        public string $countryCode,
    ) {}

    public function exists(): bool
    {
        return true;
    }

    public function isActive(): bool
    {
        return $this->status->isLocationReady();
    }
}
