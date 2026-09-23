<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Services\RecordPrivilegedFailure;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Support\Identifier;

/**
 * Privileged Admin-created doctor writes. Same AAL2 + capability gate as
 * other Admin privileged operations. Not a verification bypass.
 */
final class PrivilegedAdminDoctorCreateGuard
{
    public function __construct(
        private readonly Authorize $authorize,
        private readonly RecordPrivilegedFailure $privilegedFailures,
    ) {}

    public function assert(ActorContext $actor, Identifier $objectId, string $objectType = 'doctor_profile'): void
    {
        if ($actor->accountType !== AccountType::Admin || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, Capabilities::DOCTORS_ADMIN_CREATE);
        if ($decision->allowed) {
            return;
        }

        $this->privilegedFailures->authorizationDenied(
            $actor->userId,
            $actor->accountType->value,
            $actor->assuranceLevel->value,
            Capabilities::DOCTORS_ADMIN_CREATE,
            $decision->reasonCode,
            $objectId,
            $objectType,
        );
        throw new AuthorizationDenied;
    }
}
