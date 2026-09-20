<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeZone;

final class PharmacyOrganizationProjector
{
    public function project(
        PharmacyOrganizationRecord $organization,
        PharmacyBranchRecord $branch,
        PharmacyMembershipRecord $membership,
    ): PharmacyOrganizationProjection {
        $utc = new DateTimeZone('UTC');

        return new PharmacyOrganizationProjection(
            $organization->id->value,
            $organization->publicName,
            $organization->verificationStatus->value,
            $organization->status->value,
            $organization->version,
            $organization->createdAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $organization->updatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $branch->id->value,
            $branch->publicName,
            $branch->countryCode,
            $branch->status->value,
            $branch->version,
            $membership->id->value,
            $membership->role->value,
            $membership->status->value,
        );
    }
}
