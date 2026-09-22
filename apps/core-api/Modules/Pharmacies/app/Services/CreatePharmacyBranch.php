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
use Modules\Pharmacies\Support\PharmacyBranchProjector;
use Modules\Pharmacies\Support\PharmacyCoordinates;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;
use Modules\Pharmacies\Support\PharmacyOwnerGuard;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\Identifier;

/**
 * Create an additional pharmacy branch for an approved/active organization.
 *
 * Listed in ApprovedCoordinators: Pharmacies writes plus Audit happen in one
 * transaction. Additional branches inherit the already-approved organization
 * identity. Status is server-owned `active` when required location/contact
 * fields pass. `active` is not a Phase-10 inventory/POS grant and does not
 * open a second pharmacy verification case.
 */
final class CreatePharmacyBranch
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly PharmacyOrganizationRowFactory $rows,
        private readonly PharmacyBranchProjector $projector,
        private readonly PharmacyOwnerGuard $guard,
        private readonly NationalIdProtector $protector,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{branch_id: string, status: string, version: int}
     */
    public function handle(ActorContext $actor, Identifier $organizationId, array $input): array
    {
        $this->guard->requireOwnedOrganization($actor, $organizationId, Capabilities::PHARMACIES_BRANCH_WRITE);

        $latitude = (float) $input['latitude'];
        $longitude = (float) $input['longitude'];
        PharmacyCoordinates::assertEgyptServiceArea($latitude, $longitude);

        $country = strtoupper(trim((string) $input['country_code']));
        if ($country !== 'EG') {
            throw new InvalidValueObject('Country is not available.');
        }

        $phone = $this->protector->phone((string) $input['phone']);

        return $this->transactions->run(function (TransactionContext $tx) use (
            $actor,
            $organizationId,
            $input,
            $latitude,
            $longitude,
            $phone,
        ): array {
            $this->guard->requireOwnedOrganization(
                $actor,
                $organizationId,
                Capabilities::PHARMACIES_BRANCH_WRITE,
                true,
            );

            $branchId = $this->ids->next();
            $now = $this->clock->now();
            $this->store->insertBranch(
                $this->rows->additionalBranchAttributes($branchId, $organizationId, $phone, $input, $now),
                $longitude,
                $latitude,
            );

            $this->audit->append(
                $tx,
                'pharmacy.branch_created',
                'pharmacy_branch',
                $branchId,
                ['reason_code' => 'owner_create', 'organization_id' => $organizationId->value, 'version' => 1],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new PharmacyBranchChanged(
                $branchId,
                $organizationId,
                1,
                PharmacyBranchChangeType::Created,
                $now,
            ));

            $row = $this->store->findBranchById($branchId, true);
            assert($row !== null);

            return $this->projector->compactCreate($row);
        });
    }
}
