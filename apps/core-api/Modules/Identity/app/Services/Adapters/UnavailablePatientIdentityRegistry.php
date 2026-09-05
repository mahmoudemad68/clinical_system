<?php

declare(strict_types=1);

namespace Modules\Identity\Services\Adapters;

use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Platform\Support\Identifier;

/**
 * Phase 02 owns patient profiles. Until then, no candidate is ever returned,
 * so claim cannot enumerate or attach.
 */
final class UnavailablePatientIdentityRegistry implements PatientIdentityRegistry
{
    public function findClaimCandidate(string $blindIndex): ?Identifier
    {
        return null;
    }

    public function attachAccount(Identifier $candidateId, Identifier $userId, Identifier $proof): void
    {
        throw new \RuntimeException('Patient identity registry is not available in Phase 01.');
    }
}
