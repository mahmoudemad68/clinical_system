<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Identifier;

/**
 * The unit of work a coordinator hands to every port it calls.
 *
 * Passing this explicitly, rather than relying on an ambient transaction, makes
 * the requirement visible in each port's signature: a port that needs to
 * participate in the caller's transaction must accept the context, and a port
 * that does not accept one cannot silently commit on its own.
 */
interface TransactionContext
{
    /**
     * Correlation identifier of the request or job that opened this transaction.
     *
     * Copied onto every outbox row so an effect can be traced to its cause.
     */
    public function correlationId(): Identifier;

    /**
     * Record a domain event to be written to the outbox before commit.
     *
     * Events are buffered rather than written immediately so that ordering is
     * deterministic and a late-failing port cannot leave a partial event set.
     * They are flushed by the runner inside the same transaction.
     */
    public function recordEvent(DomainEvent $event): void;

    /**
     * Events recorded so far, in the order they were recorded.
     *
     * @return list<DomainEvent>
     */
    public function recordedEvents(): array;
}
