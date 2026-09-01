<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

use Modules\Identity\Support\SubjectHoldingPlan;
use Modules\Platform\Support\Identifier;

/**
 * Patients-owned subject holdings. Identity never queries Patients tables.
 */
interface PatientSubjectPrivacy
{
    /**
     * @return list<SubjectHoldingPlan>
     */
    public function holdings(): array;

    /**
     * @return array<string, int|null>
     */
    public function exportCounts(Identifier $userId): array;

    /**
     * @return array<string, int>
     */
    public function eraseLinked(Identifier $userId): array;
}
