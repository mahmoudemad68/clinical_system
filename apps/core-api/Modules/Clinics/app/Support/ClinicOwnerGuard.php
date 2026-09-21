<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Doctors\Services\PracticeOwnerEligibilityService;
use Modules\Doctors\Support\PracticeOwnerEligibility;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Support\Identifier;

/**
 * Server-derived actor and ownership checks. A capability name in
 * /me/capabilities is never treated as proof of clinic ownership.
 */
final class ClinicOwnerGuard
{
    public function __construct(
        private readonly PracticeOwnerEligibilityService $owners,
        private readonly PostgresClinicStore $store,
        private readonly Authorize $authorize,
    ) {}

    public function requireApprovedPrivilegedDoctor(ActorContext $actor, string $capability, bool $lock = false): PracticeOwnerEligibility
    {
        if ($actor->accountType !== AccountType::Doctor || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        if (! $actor->assuranceLevel->satisfiesPrivilegedSession()) {
            throw new AuthorizationDenied;
        }

        $decision = $this->authorize->decide($actor, $capability);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $owner = $this->owners->findByUserId($actor->userId, $lock);
        if (! $owner instanceof PracticeOwnerEligibility
            || $owner->verificationStatus !== DoctorVerificationStatus::Approved) {
            throw new AuthorizationDenied;
        }

        return $owner;
    }

    public function requireOwnedLocation(
        ActorContext $actor,
        Identifier $locationId,
        string $capability,
        bool $lock = false,
    ): array {
        $owner = $this->requireApprovedPrivilegedDoctor($actor, $capability, $lock);
        $location = $this->store->findLocationById($locationId, $lock);
        if (! $location instanceof ClinicLocationRecord
            || ! $location->doctorId->equals($owner->doctorId)
            || $location->status === ClinicLocationStatus::Closed) {
            throw new AuthorizationDenied;
        }

        return ['owner' => $owner, 'location' => $location];
    }

    public function requireActiveSecretary(ActorContext $actor, string $capability): void
    {
        if ($actor->accountType !== AccountType::Secretary || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, $capability);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }
    }

    public function requireLocationRead(ActorContext $actor, Identifier $locationId): array
    {
        $decision = $this->authorize->decide($actor, Capabilities::CLINICS_LOCATION_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        if (! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $location = $this->store->findLocationById($locationId, false);
        if (! $location instanceof ClinicLocationRecord) {
            throw new AuthorizationDenied;
        }

        if ($actor->accountType === AccountType::Doctor) {
            $owner = $this->owners->findByUserId($actor->userId, false);
            if ($owner instanceof PracticeOwnerEligibility
                && $owner->verificationStatus === DoctorVerificationStatus::Approved
                && $location->doctorId->equals($owner->doctorId)
                && $actor->assuranceLevel->satisfiesPrivilegedSession()) {
                return ['mode' => 'owner', 'location' => $location, 'owner' => $owner];
            }
        }

        $membership = $this->store->findActiveMembershipForUserAtLocation($actor->userId, $locationId);
        if ($membership !== null) {
            return ['mode' => 'staff', 'location' => $location, 'membership' => $membership];
        }

        throw new AuthorizationDenied;
    }
}
