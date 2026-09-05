<?php

declare(strict_types=1);

namespace Modules\Patients\Services\Adapters;

use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Patients\Enums\PatientStatus;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Phase 02 adapter for the Phase 01 claim boundary.
 *
 * findClaimCandidate returns an unlinked active profile only. attachAccount
 * is unused by LinkVerifiedPatientAccount (the ceremony still returns
 * manual_review_required) and uses a dedicated ownership write.
 */
final class PostgresPatientIdentityRegistry implements PatientIdentityRegistry
{
    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly Clock $clock,
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
}
