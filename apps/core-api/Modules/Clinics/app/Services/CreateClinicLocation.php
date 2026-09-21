<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Clinics\Enums\ClinicLocationChangeType;
use Modules\Clinics\Events\ClinicLocationChanged;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicCoordinates;
use Modules\Clinics\Support\ClinicLocationProjector;
use Modules\Clinics\Support\ClinicLocationRowFactory;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * Create an authoritative clinic location for an approved doctor.
 *
 * Listed in ApprovedCoordinators: Clinics writes plus Audit happen in one
 * transaction. Status is server-owned: create becomes active immediately when
 * required fields pass. active means location readiness, not public listing.
 */
final class CreateClinicLocation
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresClinicStore $store,
        private readonly ClinicLocationRowFactory $rows,
        private readonly ClinicLocationProjector $projector,
        private readonly ClinicOwnerGuard $guard,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{location_id: string, status: string, version: int}
     */
    public function handle(ActorContext $actor, array $input): array
    {
        $this->guard->requireApprovedPrivilegedDoctor($actor, Capabilities::CLINICS_LOCATION_WRITE);

        $latitude = (float) $input['latitude'];
        $longitude = (float) $input['longitude'];
        ClinicCoordinates::assertEgyptServiceArea($latitude, $longitude);

        $country = strtoupper(trim((string) $input['country_code']));
        if ($country !== 'EG') {
            throw new InvalidValueObject('Country is not available.');
        }

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $input, $latitude, $longitude): array {
            $owner = $this->guard->requireApprovedPrivilegedDoctor(
                $actor,
                Capabilities::CLINICS_LOCATION_WRITE,
                true,
            );

            $locationId = $this->ids->next();
            $now = $this->clock->now();
            $this->store->insertLocation(
                $this->rows->locationAttributes($locationId, $owner->doctorId, $input, $now),
                $longitude,
                $latitude,
            );

            $this->audit->append(
                $tx,
                'clinic.location_created',
                'clinic_location',
                $locationId,
                ['reason_code' => 'owner_create', 'version' => 1],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new ClinicLocationChanged(
                $locationId,
                $owner->doctorId,
                1,
                ClinicLocationChangeType::Created,
                $now,
            ));

            $row = $this->store->findLocationById($locationId, true);
            assert($row !== null);

            return $this->projector->compactCreate($row);
        });
    }
}
