<?php

declare(strict_types=1);

namespace Modules\Patients\Services\Adapters;

use Modules\Identity\Contracts\PatientSubjectPrivacy;
use Modules\Identity\Support\SubjectHoldingPlan;
use Modules\Patients\Services\Persistence\PostgresPatientProfileStore;
use Modules\Patients\Support\PatientSubjectHoldings;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Support\Identifier;

final class PostgresPatientSubjectPrivacy implements PatientSubjectPrivacy
{
    public function __construct(
        private readonly PostgresPatientProfileStore $store,
        private readonly RandomBytes $random,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<SubjectHoldingPlan>
     */
    public function holdings(): array
    {
        return PatientSubjectHoldings::plan();
    }

    public function exportCounts(Identifier $userId): array
    {
        return [
            'patient_profiles' => $this->store->countLinkedToUser($userId),
            'patient_demographic_revisions' => null,
        ];
    }

    public function eraseLinked(Identifier $userId): array
    {
        $affected = $this->store->eraseLinkedProfiles(
            $userId,
            $this->random->next(32),
            $this->random->next(32),
            $this->clock->now(),
        );

        return ['patient_profiles' => $affected];
    }
}
