<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicLocationPortDto;
use Modules\Clinics\Support\ClinicLocationRecord;
use Modules\Platform\Support\Identifier;

/**
 * Narrow Phase-03 port. Does not implement schedules, appointments, or public
 * geographic search.
 */
final class GetClinicLocation
{
    public function __construct(
        private readonly PostgresClinicStore $store,
    ) {}

    public function handle(Identifier $locationId): ?ClinicLocationPortDto
    {
        $row = $this->store->findLocationById($locationId, false);
        if (! $row instanceof ClinicLocationRecord) {
            return null;
        }

        return new ClinicLocationPortDto(
            $row->id,
            $row->doctorId,
            $row->status,
            $row->version,
            $row->countryCode,
        );
    }
}
