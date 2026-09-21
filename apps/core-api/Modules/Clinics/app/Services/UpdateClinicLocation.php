<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Clinics\Enums\ClinicLocationChangeType;
use Modules\Clinics\Events\ClinicLocationChanged;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicCoordinates;
use Modules\Clinics\Support\ClinicLocationPrivateProjection;
use Modules\Clinics\Support\ClinicLocationProjector;
use Modules\Clinics\Support\ClinicLocationRecord;
use Modules\Clinics\Support\ClinicLocationRowFactory;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Support\Identifier;

/**
 * Optimistic-concurrency location update. Stale expected_version never
 * last-write-wins.
 *
 * Listed in ApprovedCoordinators.
 */
final class UpdateClinicLocation
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresClinicStore $store,
        private readonly ClinicLocationRowFactory $rows,
        private readonly ClinicLocationProjector $projector,
        private readonly ClinicOwnerGuard $guard,
        private readonly AppendAuditEvent $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, Identifier $locationId, array $input): ClinicLocationPrivateProjection
    {
        $this->guard->requireOwnedLocation($actor, $locationId, Capabilities::CLINICS_LOCATION_WRITE);

        $expectedVersion = (int) $input['expected_version'];
        $hasCoords = array_key_exists('latitude', $input) || array_key_exists('longitude', $input);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $locationId, $input, $expectedVersion, $hasCoords): ClinicLocationPrivateProjection {
            $resolved = $this->guard->requireOwnedLocation(
                $actor,
                $locationId,
                Capabilities::CLINICS_LOCATION_WRITE,
                true,
            );
            /** @var ClinicLocationRecord $location */
            $location = $resolved['location'];
            if ($location->version !== $expectedVersion) {
                throw new VersionConflict;
            }

            $now = $this->clock->now();
            $latitude = array_key_exists('latitude', $input) ? (float) $input['latitude'] : $location->latitude;
            $longitude = array_key_exists('longitude', $input) ? (float) $input['longitude'] : $location->longitude;
            if ($hasCoords || array_key_exists('latitude', $input) || array_key_exists('longitude', $input)) {
                ClinicCoordinates::assertEgyptServiceArea($latitude, $longitude);
            }

            $country = array_key_exists('country_code', $input)
                ? strtoupper(trim((string) $input['country_code']))
                : $location->countryCode;
            if ($country !== 'EG') {
                throw new InvalidValueObject('Country is not available.');
            }

            $attributes = [
                'version' => $expectedVersion + 1,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
                'country_code' => $country,
            ];
            if (array_key_exists('public_name', $input)) {
                $attributes['public_name'] = (string) $input['public_name'];
            }
            if (array_key_exists('address', $input)) {
                $attributes = [...$attributes, ...$this->rows->encryptAddress((string) $input['address'])];
            }

            $affected = $hasCoords || array_key_exists('latitude', $input) || array_key_exists('longitude', $input)
                ? $this->store->updateLocationGeography($locationId, $expectedVersion, $attributes, $longitude, $latitude)
                : $this->store->updateLocation($locationId, $expectedVersion, $attributes);

            if ($affected !== 1) {
                throw new VersionConflict;
            }

            $this->audit->append(
                $tx,
                'clinic.location_updated',
                'clinic_location',
                $locationId,
                ['reason_code' => 'owner_update', 'version' => $expectedVersion + 1],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new ClinicLocationChanged(
                $locationId,
                $location->doctorId,
                $expectedVersion + 1,
                ClinicLocationChangeType::Updated,
                $now,
            ));

            $fresh = $this->store->findLocationById($locationId, true);
            assert($fresh !== null);

            return $this->projector->owner($fresh);
        });
    }
}
