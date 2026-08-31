<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Identity\Enums\SubjectHoldingAction;

/**
 * One enumerated Phase-01 holding and the technical action erasure applies.
 */
final readonly class SubjectHoldingPlan
{
    public function __construct(
        public string $holding,
        public SubjectHoldingAction $action,
        public string $notes,
    ) {}

    /**
     * @return array{holding: string, action: string, notes: string}
     */
    public function toArray(): array
    {
        return [
            'holding' => $this->holding,
            'action' => $this->action->value,
            'notes' => $this->notes,
        ];
    }
}
