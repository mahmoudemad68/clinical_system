<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Services\Persistence\PostgresSpecialtyStore;
use Modules\Doctors\Support\DoctorProfileProjection;
use Modules\Doctors\Support\DoctorProfileProjector;
use Modules\Doctors\Support\DoctorProfileRecord;
use Modules\Doctors\Support\SpecialtyRecord;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;

final class GetDoctorProfile
{
    public function __construct(
        private readonly PostgresDoctorProfileStore $store,
        private readonly PostgresSpecialtyStore $specialties,
        private readonly DoctorProfileProjector $projector,
        private readonly Authorize $authorize,
    ) {}

    public function handle(ActorContext $actor): DoctorProfileProjection
    {
        if ($actor->accountType !== AccountType::Doctor || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::DOCTORS_PROFILE_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $row = $this->store->findByUserId($actor->userId, false);
        if (! $row instanceof DoctorProfileRecord) {
            throw new AuthorizationDenied;
        }

        $specialty = $this->specialties->findById($row->specialtyId);
        if (! $specialty instanceof SpecialtyRecord) {
            throw new AuthorizationDenied;
        }

        return $this->projector->project($row, $specialty);
    }
}
