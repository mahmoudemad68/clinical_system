<?php

declare(strict_types=1);

namespace Modules\Patients\Services\Adapters;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Identity\Contracts\PatientIdentityRegistry;
use Modules\Patients\Enums\PatientStatus;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientProfileRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Phase 02 adapter for the Phase 01 claim boundary.
 *
 * findClaimCandidate returns an unlinked active profile only. attachAccount
 * is unused by LinkVerifiedPatientAccount (the ceremony still returns
 * manual_review_required). eraseLinkedProfiles tombstones protected fields.
 */
final class PostgresPatientIdentityRegistry implements PatientIdentityRegistry
{
    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly RandomBytes $random,
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

        $now = $this->clock->now();

        try {
            $affected = $this->store->updateDemographics($row->id, $row->version, [
                'user_id' => $userId->value,
                'version' => $row->version + 1,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateIdentity;
        }

        if ($affected !== 1) {
            throw new DuplicateIdentity;
        }
    }

    public function eraseLinkedProfiles(Identifier $userId): int
    {
        return $this->store->eraseLinkedProfiles(
            $userId,
            $this->random->next(32),
            $this->random->next(32),
            $this->clock->now(),
        );
    }
}
