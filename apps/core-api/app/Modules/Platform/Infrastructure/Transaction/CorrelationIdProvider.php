<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Transaction;

use App\Modules\Platform\Domain\Contracts\CorrelationScope;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Holds the correlation identifier for the current request or job.
 *
 * Request-scoped state under Octane needs care: workers are long-lived, so a
 * value left here would leak into the next request served by the same worker.
 * The Octane request hooks reset this between requests, and a regression test
 * with two synthetic identities proves it (Phase 00 §2.5, gate G-02-05).
 *
 * When nothing has been set, a fresh identifier is generated rather than
 * returning a shared placeholder, so an unset context can never make two
 * unrelated requests look correlated.
 */
final class CorrelationIdProvider implements CorrelationScope
{
    private ?Identifier $current = null;

    public function __construct(private readonly IdentityGenerator $identities)
    {
    }

    public function current(): Identifier
    {
        return $this->current ??= $this->identities->next();
    }

    public function set(Identifier $correlationId): void
    {
        $this->current = $correlationId;
    }

    /**
     * Clear the identifier between requests on a long-lived worker.
     */
    public function reset(): void
    {
        $this->current = null;
    }

    public function hasBeenSet(): bool
    {
        return $this->current !== null;
    }
}
