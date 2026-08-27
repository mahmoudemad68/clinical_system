<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Transaction;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Contracts\CorrelationScope;
use Modules\Platform\Contracts\OutboxRecorder;
use Modules\Platform\Contracts\TransactionRunner;
use RuntimeException;

/**
 * The PostgreSQL-backed transaction boundary (ADR 0004).
 *
 * Responsibilities, in order:
 *   1. open one transaction;
 *   2. run the coordinator's work, collecting recorded domain events;
 *   3. flush those events to the outbox inside the same transaction;
 *   4. commit, or roll everything back.
 *
 * Two deliberate refusals:
 *
 *   - Nesting is rejected rather than silently turned into a savepoint. A
 *     coordinator that finds itself already inside a transaction has lost
 *     control of its own boundary, and a savepoint would let an inner "commit"
 *     appear to succeed while the outer transaction later rolls back.
 *   - Retry is not handled here. Retrying a whole business transaction is a
 *     decision for the caller who knows whether the work is safe to repeat.
 */
final class DatabaseTransactionRunner implements TransactionRunner
{
    private bool $active = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly OutboxRecorder $outbox,
        private readonly CorrelationScope $correlation,
    ) {}

    public function run(Closure $work): mixed
    {
        if ($this->active) {
            throw new RuntimeException(
                'A transaction is already active. A coordinator owns exactly one transaction boundary; '
                .'nesting would let an inner commit appear to succeed while the outer transaction rolls back.',
            );
        }

        $this->active = true;

        try {
            return $this->connection->transaction(function () use ($work): mixed {
                $context = new BufferedTransactionContext($this->correlation->current());

                $result = $work($context);

                // Events are flushed after the work completes but before the
                // transaction closes, so a failure anywhere in the work leaves
                // no outbox row behind.
                $events = $context->recordedEvents();

                if ($events !== []) {
                    $this->outbox->recordAll($events, $context);
                }

                return $result;
            });
        } finally {
            $this->active = false;
        }
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }
}
