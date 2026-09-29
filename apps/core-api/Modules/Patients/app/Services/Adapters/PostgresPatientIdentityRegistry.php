<?php

declare(strict_types=1);

namespace Modules\Patients\Services\Adapters;

use DateTimeImmutable;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Patients\Enums\PatientStatus;
use Modules\Patients\Services\Persistence\PostgresPatientClaimStore;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Phase 02 adapter for the Phase 01 claim boundary.
 *
 * findClaimCandidate returns an unlinked active profile only. attachAccount
 * uses a dedicated ownership write. Credential consume and abuse counters
 * live in Patients-owned tables.
 */
final class PostgresPatientIdentityRegistry implements PatientIdentityRegistry
{
    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly PostgresPatientClaimStore $claims,
        private readonly Clock $clock,
        private readonly IdentityGenerator $ids,
    ) {}

    public function findClaimCandidate(string $blindIndex): ?Identifier
    {
        $row = $this->store->findAuthoritativeByHmacs([$blindIndex], false);
        if (! $row instanceof PatientProfileRecord) {
            return null;
        }

        if ($row->userId !== null || $row->status !== PatientStatus::Active) {
            return null;
        }

        return $row->id;
    }

    public function countAuthoritativeMatches(array $blindIndexes): int
    {
        return $this->store->countAuthoritativeByHmacs($blindIndexes);
    }

    public function attachAccount(Identifier $candidateId, Identifier $userId, Identifier $proof): void
    {
        $row = $this->store->findById($candidateId, true);
        if (! $row instanceof PatientProfileRecord || $row->userId !== null) {
            throw new DuplicateIdentity;
        }

        $affected = $this->store->attachAccount(
            $row->id,
            $userId,
            $row->version,
            $this->clock->now(),
        );

        if ($affected !== 1) {
            throw new DuplicateIdentity;
        }
    }

    public function consumeUnexpiredCredential(Identifier $patientId, string $credentialHash, DateTimeImmutable $now): bool
    {
        return $this->claims->consumeUnexpired($patientId, $credentialHash, $now) === 1;
    }

    public function isClaimLocked(Identifier $userId, string $nationalIdHmac, DateTimeImmutable $now): bool
    {
        $row = $this->claims->lockRow($userId, $nationalIdHmac);
        if ($row === null) {
            return false;
        }

        if (isset($row->locked_at) && (string) $row->locked_at !== '') {
            return true;
        }

        if (isset($row->cooldown_until) && (string) $row->cooldown_until !== '') {
            return new DateTimeImmutable((string) $row->cooldown_until) > $now;
        }

        return false;
    }

    public function recordCredentialFailure(Identifier $userId, string $nationalIdHmac, DateTimeImmutable $now): string
    {
        $this->claims->insertFailure($this->ids->next(), $userId, $nationalIdHmac, $now);

        $hourly = (int) config('identity.profile_claim.credential_failures_per_hour', 5);
        $daily = (int) config('identity.profile_claim.credential_failures_per_24h', 15);
        $cooldownMinutes = (int) config('identity.profile_claim.credential_hourly_cooldown_minutes', 15);

        $lastDay = $this->claims->countFailuresSince($userId, $nationalIdHmac, $now->modify('-24 hours'));
        $lastHour = $this->claims->countFailuresSince($userId, $nationalIdHmac, $now->modify('-1 hour'));

        $lockedAt = $lastDay >= $daily ? $now : null;
        $cooldownUntil = $lastHour >= $hourly ? $now->modify(sprintf('+%d minutes', $cooldownMinutes)) : null;

        $this->claims->upsertLock($this->ids->next(), $userId, $nationalIdHmac, $cooldownUntil, $lockedAt, $now);

        if ($lockedAt instanceof DateTimeImmutable) {
            return 'locked';
        }

        if ($cooldownUntil instanceof DateTimeImmutable) {
            return 'cooldown';
        }

        return 'recorded';
    }
}
