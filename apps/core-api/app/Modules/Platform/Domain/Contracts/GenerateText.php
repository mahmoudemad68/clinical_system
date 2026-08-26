<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Generate text from a model provider. Isolated from retrieval.
 *
 * Phase 00 is fail-closed. Phase 16 adds the real adapter behind this port.
 */
interface GenerateText
{
    /**
     * @param  array{deadline_ms: int, schema_version: int}  $options
     */
    public function generate(string $promptRef, array $options): string;
}
