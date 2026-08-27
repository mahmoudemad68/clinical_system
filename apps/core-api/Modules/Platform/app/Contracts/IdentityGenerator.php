<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use Modules\Platform\Support\Identifier;

/**
 * Generates UUIDv7 identifiers (ADR 0005).
 *
 * Centralized so that:
 *   - tests can inject a deterministic sequence;
 *   - a move to database-side generation is one adapter change;
 *   - no caller reaches for Str::uuid(), which is v4 and fragments the index
 *     on the highest-volume tables.
 */
interface IdentityGenerator
{
    /**
     * A new UUIDv7.
     *
     * Successive calls are non-decreasing: the millisecond timestamp occupies
     * the high bits. Ordering is a performance property only. No invariant may
     * depend on it, because clock adjustment can violate it.
     */
    public function next(): Identifier;
}
