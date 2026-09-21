<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Doctors\Support\SpecialtyProjection;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;

/**
 * Authenticated Doctor HTTP projection of the Doctors-owned active specialty
 * catalogue. Ownership stays on ListSpecialties. Non-doctor actors cannot
 * obtain Doctor business privileges through this read.
 */
final class ListDoctorSpecialties
{
    public function __construct(
        private readonly ListSpecialties $specialties,
        private readonly Authorize $authorize,
    ) {}

    /**
     * @return list<SpecialtyProjection>
     */
    public function handle(ActorContext $actor): array
    {
        if ($actor->accountType !== AccountType::Doctor || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::DOCTORS_SPECIALTIES_READ);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        return $this->specialties->handle();
    }
}
