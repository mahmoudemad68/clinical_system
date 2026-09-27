<?php

declare(strict_types=1);

namespace Tests\Support\ThreatModel;

final readonly class Phase02CompletenessResult
{
    /**
     * @param  list<string>  $issues
     * @param  array{MITIGATED: int, PARTIAL: int, OPEN: int, NOT_APPLICABLE: int, TOTAL: int}  $statusCounts
     */
    public function __construct(
        public array $issues,
        public array $statusCounts,
        public int $httpCount,
        public int $doctorIpcCount,
        public int $pharmacyIpcCount,
        public int $actorCount,
        public int $assetCount,
    ) {}

    public function passed(): bool
    {
        return $this->issues === [];
    }
}
