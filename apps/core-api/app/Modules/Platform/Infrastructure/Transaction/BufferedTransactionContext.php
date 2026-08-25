<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Transaction;

use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * Buffers domain events recorded during a transaction until the runner flushes
 * them to the outbox, inside the same transaction, immediately before commit.
 *
 * Buffering rather than writing on each call gives deterministic ordering and
 * means a port that fails late cannot leave a partial set of events behind for
 * a change that never committed.
 */
final class BufferedTransactionContext implements TransactionContext
{
    /** @var list<DomainEvent> */
    private array $events = [];

    public function __construct(private readonly Identifier $correlationId) {}

    public function correlationId(): Identifier
    {
        return $this->correlationId;
    }

    public function recordEvent(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    public function recordedEvents(): array
    {
        return $this->events;
    }
}
