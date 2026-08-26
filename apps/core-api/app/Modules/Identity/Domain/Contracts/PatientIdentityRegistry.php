<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Phase 02 Patients adapter. Phase 01 ships a stub that never confirms
 * candidate existence to a client.
 */
interface PatientIdentityRegistry
{
    public function findClaimCandidate(string $blindIndex): ?Identifier;

    public function attachAccount(Identifier $candidateId, Identifier $userId, Identifier $proof): void;
}
