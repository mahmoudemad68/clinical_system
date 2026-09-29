<?php

declare(strict_types=1);

namespace Tests\Support\ProfileClaim;

use DateTimeImmutable;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Platform\Support\Identifier;

/**
 * Forces the PC-017 duplicate_match branch. Unique HMAC indexes make a live
 * count>1 unreachable; this double is test-only.
 */
final class DuplicateMatchPatientIdentityRegistry implements PatientIdentityRegistry
{
    public function __construct(private readonly PatientIdentityRegistry $inner) {}

    public function findClaimCandidate(string $blindIndex): ?Identifier
    {
        return $this->inner->findClaimCandidate($blindIndex);
    }

    public function countAuthoritativeMatches(array $blindIndexes): int
    {
        return 2;
    }

    public function attachAccount(Identifier $candidateId, Identifier $userId, Identifier $proof): void
    {
        $this->inner->attachAccount($candidateId, $userId, $proof);
    }

    public function consumeUnexpiredCredential(Identifier $patientId, string $credentialHash, DateTimeImmutable $now): bool
    {
        return $this->inner->consumeUnexpiredCredential($patientId, $credentialHash, $now);
    }

    public function isClaimLocked(Identifier $userId, string $nationalIdHmac, DateTimeImmutable $now): bool
    {
        return $this->inner->isClaimLocked($userId, $nationalIdHmac, $now);
    }

    public function recordCredentialFailure(Identifier $userId, string $nationalIdHmac, DateTimeImmutable $now): string
    {
        return $this->inner->recordCredentialFailure($userId, $nationalIdHmac, $now);
    }
}
