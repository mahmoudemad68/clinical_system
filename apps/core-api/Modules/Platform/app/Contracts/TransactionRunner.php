<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use Closure;

/**
 * A bounded transaction boundary owned by an application coordinator.
 *
 * ADR 0004: a coordinator owns the transaction, passes the same context to every
 * module command port it calls, and collects domain events that are written to
 * the outbox before commit. Ports do not open their own transactions; nesting
 * would let an inner "commit" appear to succeed while the outer transaction
 * later rolls back.
 *
 * External, realtime, notification, and analytics work never happens inside the
 * callback. It happens after commit, driven by the outbox (plan.md section 174).
 */
interface TransactionRunner
{
    /**
     * Run the callback inside one transaction and return its result.
     *
     * The callback receives the active TransactionContext. Any throwable rolls
     * the whole transaction back, including every outbox row recorded within it.
     *
     * @template TResult
     *
     * @param  Closure(TransactionContext): TResult  $work
     * @return TResult
     */
    public function run(Closure $work): mixed;

    /**
     * True while a transaction opened by this runner is active.
     *
     * Used by guards that must refuse to perform an external call mid-transaction.
     */
    public function inTransaction(): bool;
}
