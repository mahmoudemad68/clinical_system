<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Platform\Support\Identifier;

/**
 * Testable result of one Phase-01 subject-erasure run.
 *
 * @phpstan-type DeletedCounts array<string, int>
 */
final readonly class SubjectErasureReport
{
    /**
     * @param  list<SubjectHoldingPlan>  $plan
     * @param  DeletedCounts  $deletedCounts
     */
    public function __construct(
        public Identifier $subjectId,
        public bool $alreadyErased,
        public array $plan,
        public array $deletedCounts,
    ) {}

    /**
     * @return array{
     *     subject_id: string,
     *     already_erased: bool,
     *     plan: list<array{holding: string, action: string, notes: string}>,
     *     deleted_counts: DeletedCounts
     * }
     */
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId->value,
            'already_erased' => $this->alreadyErased,
            'plan' => array_map(
                static fn (SubjectHoldingPlan $plan): array => $plan->toArray(),
                $this->plan,
            ),
            'deleted_counts' => $this->deletedCounts,
        ];
    }
}
