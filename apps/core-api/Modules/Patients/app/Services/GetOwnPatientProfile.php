<?php

declare(strict_types=1);

namespace Modules\Patients\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Support\ActorContext;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientProfileProjection;
use Modules\Patients\Support\PatientProfileProjector;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Exceptions\AuthorizationDenied;

final class GetOwnPatientProfile
{
    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly PatientProfileProjector $projector,
        private readonly Authorize $authorize,
    ) {}

    public function handle(ActorContext $actor): PatientProfileProjection
    {
        $decision = $this->authorize->decide($actor, Capabilities::PATIENTS_PROFILE_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $row = $this->store->findByUserId($actor->userId, false);
        if (! $row instanceof PatientProfileRecord) {
            throw new AuthorizationDenied;
        }

        return $this->projector->project($row);
    }
}
