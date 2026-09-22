<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Enums\PharmacyBranchChangeType;
use Modules\Pharmacies\Events\PharmacyBranchChanged;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyBranchPrivateProjection;
use Modules\Pharmacies\Support\PharmacyBranchProjector;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyCoordinates;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;
use Modules\Pharmacies\Support\PharmacyOwnerGuard;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Support\Identifier;

/**
 * Optimistic-concurrency branch update. Stale expected_version never
 * last-write-wins.
 *
 * Listed in ApprovedCoordinators.
 */
final class UpdatePharmacyBranch
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly PharmacyOrganizationRowFactory $rows,
        private readonly PharmacyBranchProjector $projector,
        private readonly PharmacyOwnerGuard $guard,
        private readonly NationalIdProtector $protector,
        private readonly AppendAuditEvent $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(
        ActorContext $actor,
        Identifier $organizationId,
        Identifier $branchId,
        array $input,
    ): PharmacyBranchPrivateProjection {
        $this->guard->requireOwnedBranch($actor, $organizationId, $branchId, Capabilities::PHARMACIES_BRANCH_WRITE);

        $expectedVersion = (int) $input['expected_version'];
        $hasCoords = array_key_exists('latitude', $input) || array_key_exists('longitude', $input);

        return $this->transactions->run(function (TransactionContext $tx) use (
            $actor,
            $organizationId,
            $branchId,
            $input,
            $expectedVersion,
            $hasCoords,
        ): PharmacyBranchPrivateProjection {
            $resolved = $this->guard->requireOwnedBranch(
                $actor,
                $organizationId,
                $branchId,
                Capabilities::PHARMACIES_BRANCH_WRITE,
                true,
            );
            /** @var PharmacyBranchRecord $branch */
            $branch = $resolved['branch'];
            if ($branch->version !== $expectedVersion) {
                throw new VersionConflict;
            }

            $now = $this->clock->now();
            $latitude = array_key_exists('latitude', $input) ? (float) $input['latitude'] : $branch->latitude;
            $longitude = array_key_exists('longitude', $input) ? (float) $input['longitude'] : $branch->longitude;
            if ($hasCoords) {
                PharmacyCoordinates::assertEgyptServiceArea($latitude, $longitude);
            }

            $country = array_key_exists('country_code', $input)
                ? strtoupper(trim((string) $input['country_code']))
                : $branch->countryCode;
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
            if (array_key_exists('phone', $input)) {
                $attributes = [...$attributes, ...$this->rows->encryptPhone($this->protector->phone((string) $input['phone']))];
            }

            $affected = $hasCoords
                ? $this->store->updateBranchGeography($branchId, $expectedVersion, $attributes, $longitude, $latitude)
                : $this->store->updateBranch($branchId, $expectedVersion, $attributes);

            if ($affected !== 1) {
                throw new VersionConflict;
            }

            $this->audit->append(
                $tx,
                'pharmacy.branch_updated',
                'pharmacy_branch',
                $branchId,
                ['reason_code' => 'owner_update', 'organization_id' => $organizationId->value, 'version' => $expectedVersion + 1],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new PharmacyBranchChanged(
                $branchId,
                $organizationId,
                $expectedVersion + 1,
                PharmacyBranchChangeType::Updated,
                $now,
            ));

            $fresh = $this->store->findBranchById($branchId, true);
            assert($fresh !== null);

            return $this->projector->owner($fresh);
        });
    }
}
