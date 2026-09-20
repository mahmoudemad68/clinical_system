<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services\Adapters;

use Modules\Identity\Contracts\PharmacySubjectPrivacy;
use Modules\Identity\Support\SubjectHoldingPlan;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacySubjectHoldings;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Support\Identifier;

final class PostgresPharmacySubjectPrivacy implements PharmacySubjectPrivacy
{
    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly RandomBytes $random,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<SubjectHoldingPlan>
     */
    public function holdings(): array
    {
        return PharmacySubjectHoldings::plan();
    }

    public function exportCounts(Identifier $userId): array
    {
        return $this->store->countLinkedToUser($userId);
    }

    public function eraseLinked(Identifier $userId): array
    {
        return $this->store->eraseLinked(
            $userId,
            $this->random->next(32),
            $this->random->next(32),
            $this->clock->now(),
        );
    }
}
