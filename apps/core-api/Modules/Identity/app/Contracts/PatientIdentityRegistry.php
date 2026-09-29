<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;

/**
 * Phase 02 Patients adapter for the Phase 01 claim boundary.
 *
 * Identity never queries patient_profiles (or claim) tables directly.
 */
interface PatientIdentityRegistry
{
    public function findClaimCandidate(string $blindIndex): ?Identifier;

    /**
     * Authoritative (status <> merged) rows matching any of the HMACs.
     *
     * @param  list<string>  $blindIndexes
     */
    public function countAuthoritativeMatches(array $blindIndexes): int;

    public function attachAccount(Identifier $candidateId, Identifier $userId, Identifier $proof): void;

    public function consumeUnexpiredCredential(Identifier $patientId, string $credentialHash, DateTimeImmutable $now): bool;

    public function isClaimLocked(Identifier $userId, string $nationalIdHmac, DateTimeImmutable $now): bool;

    /**
     * Record a failed credential attempt. External clients still see generic pending.
     *
     * @return 'recorded'|'cooldown'|'locked'
     */
    public function recordCredentialFailure(Identifier $userId, string $nationalIdHmac, DateTimeImmutable $now): string;
}
