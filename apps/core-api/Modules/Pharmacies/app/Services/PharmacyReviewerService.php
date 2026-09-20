<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Pharmacies\Support\PharmacyReviewerProjection;
use Modules\Platform\Support\Identifier;

/**
 * Narrow reviewer-facing organization/branch projection. Verification and
 * Admin must consume this instead of querying pharmacy tables.
 */
final class PharmacyReviewerService
{
    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
    ) {}

    public function findById(Identifier $organizationId): ?PharmacyReviewerProjection
    {
        $mapped = $this->findByIds([$organizationId]);

        return $mapped[$organizationId->value] ?? null;
    }

    /**
     * @param  list<Identifier>  $organizationIds
     * @return array<string, PharmacyReviewerProjection>
     */
    public function findByIds(array $organizationIds): array
    {
        $ids = [];
        foreach ($organizationIds as $id) {
            $ids[$id->value] = $id->value;
        }
        if ($ids === []) {
            return [];
        }

        $organizations = $this->store->findOrganizationsByIds(array_values($ids));
        $branches = $this->store->findInitialBranchesByOrganizationIds(array_values($ids));

        $out = [];
        foreach ($organizations as $organization) {
            $branch = $branches[$organization->id->value] ?? null;
            if (! $branch instanceof PharmacyBranchRecord) {
                continue;
            }
            $out[$organization->id->value] = $this->project($organization, $branch);
        }

        return $out;
    }

    private function project(
        PharmacyOrganizationRecord $organization,
        PharmacyBranchRecord $branch,
    ): PharmacyReviewerProjection {
        return new PharmacyReviewerProjection(
            $organization->id->value,
            $organization->publicName,
            $organization->verificationStatus->value,
            $organization->status->value,
            $organization->version,
            $branch->id->value,
            $branch->publicName,
            $branch->countryCode,
            $branch->status->value,
        );
    }
}
