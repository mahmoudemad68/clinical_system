<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationProjection;
use Modules\Pharmacies\Support\PharmacyOrganizationProjector;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;

final class GetOwnPharmacyOrganization
{
    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly PharmacyOrganizationProjector $projector,
        private readonly Authorize $authorize,
    ) {}

    public function handle(ActorContext $actor): PharmacyOrganizationProjection
    {
        if ($actor->accountType !== AccountType::Pharmacy || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::PHARMACIES_ORGANIZATION_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $membership = $this->store->findOwnerMembershipByUserId($actor->userId, false);
        if (! $membership instanceof PharmacyMembershipRecord) {
            throw new AuthorizationDenied;
        }

        $organization = $this->store->findOrganizationById($membership->organizationId, false);
        $branch = $this->store->findInitialBranch($membership->organizationId, false);
        if (! $organization instanceof PharmacyOrganizationRecord || ! $branch instanceof PharmacyBranchRecord) {
            throw new AuthorizationDenied;
        }

        return $this->projector->project($organization, $branch, $membership);
    }
}
