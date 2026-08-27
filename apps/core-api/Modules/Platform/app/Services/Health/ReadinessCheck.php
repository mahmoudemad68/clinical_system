<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Health;

/**
 * One dependency check result.
 *
 * `name` is drawn from a small fixed set, which is what makes it safe to use as
 * a metric label: the phase file bounds metric labels precisely so an unbounded
 * value cannot explode cardinality.
 */
final readonly class ReadinessCheck
{
    public function __construct(
        public string $name,
        public bool $critical,
        public CheckStatus $status,
        public int $durationMs,
    ) {}

    /**
     * @return array{name: string, critical: bool, status: string, duration_ms: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'critical' => $this->critical,
            'status' => $this->status->value,
            'duration_ms' => $this->durationMs,
        ];
    }
}
