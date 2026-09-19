<?php

declare(strict_types=1);

namespace Modules\Doctors\Services\Adapters;

use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Support\DoctorSubjectHoldings;
use Modules\Identity\Contracts\DoctorSubjectPrivacy;
use Modules\Identity\Support\SubjectHoldingPlan;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Support\Identifier;

final class PostgresDoctorSubjectPrivacy implements DoctorSubjectPrivacy
{
    public function __construct(
        private readonly PostgresDoctorProfileStore $store,
        private readonly RandomBytes $random,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<SubjectHoldingPlan>
     */
    public function holdings(): array
    {
        return DoctorSubjectHoldings::plan();
    }

    public function exportCounts(Identifier $userId): array
    {
        return [
            'doctor_profiles' => $this->store->countLinkedToUser($userId),
            'specialties' => null,
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

        return ['doctor_profiles' => $affected];
    }
}
