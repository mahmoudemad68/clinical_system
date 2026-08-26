<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapters;

use App\Modules\Identity\Domain\Contracts\PatientIdentityRegistry;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

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
        // Phase 02 implements attachment inside Patients. A call here is a
        // programming error, not a client-visible existence signal.
        throw new \RuntimeException('Patient identity registry is not available in Phase 01.');
    }
}
