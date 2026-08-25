<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Writes outbox rows inside the caller's transaction (ADR 0004).
 *
 * The entire value of the outbox is that the row and the state change share one
 * transaction. An implementation that opens its own connection, defers the
 * write, or retries independently reintroduces exactly the dual-write failure
 * the pattern exists to remove.
 */
interface OutboxRecorder
{
    /**
     * Record one event for post-commit delivery.
     *
     * Must be called inside an active transaction. Implementations reject a
     * call made outside one rather than writing an orphan row that would be
     * published for a change that never committed.
     *
     * @return Identifier the event_id, which is also the consumer idempotency key
     */
    public function record(DomainEvent $event, TransactionContext $context): Identifier;

    /**
     * Record several events, preserving order.
     *
     * @param  list<DomainEvent>  $events
     * @return list<Identifier>
     */
    public function recordAll(array $events, TransactionContext $context): array;
}
