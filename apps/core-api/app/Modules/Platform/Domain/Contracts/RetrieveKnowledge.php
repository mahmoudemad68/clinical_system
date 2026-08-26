<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Retrieve knowledge from the AI index. Isolated from generation.
 *
 * Phase 00 is fail-closed. Phase 16 adds the real adapter behind this port.
 */
interface RetrieveKnowledge
{
    /**
     * @param  array{scope: string, version: string, limit: int}  $filter
     * @return list<array{id: string, score: float}>
     */
    public function retrieve(string $queryRef, array $filter): array;
}
