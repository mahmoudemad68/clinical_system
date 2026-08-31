<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use Modules\Platform\Support\Identifier;

/**
 * The correlation identifier for the current request or job.
 *
 * A domain-owned port rather than a concrete holder, so the HTTP layer depends
 * on this interface and never on the Infrastructure class implementing it.
 *
 * Request-scoped state on a long-lived Octane worker needs care: a value left
 * behind leaks into the next request served by the same worker. Implementations
 * must support an explicit reset, and the two-synthetic-identity regression
 * test (gate G-02-05) exists to prove it happens.
 */
interface CorrelationScope
{
    /**
     * The current correlation identifier, generating one when unset.
     *
     * Never returns a shared placeholder: an unset scope must not make two
     * unrelated requests appear correlated.
     */
    public function current(): Identifier;

    public function set(Identifier $correlationId): void;

    /**
     * Clear the identifier between requests on a long-lived worker.
     */
    public function reset(): void;

    public function hasBeenSet(): bool;
}
