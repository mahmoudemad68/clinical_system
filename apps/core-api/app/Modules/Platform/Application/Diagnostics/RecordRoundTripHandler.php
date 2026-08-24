<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Diagnostics;

use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\DiagnosticsRepository;
use App\Modules\Platform\Domain\Contracts\IdentityGenerator;
use App\Modules\Platform\Domain\Contracts\OutboxRecorder;
use App\Modules\Platform\Domain\Contracts\TransactionContext;
use App\Modules\Platform\Domain\Contracts\TransactionRunner;
use App\Modules\Platform\Domain\Events\DiagnosticsRoundTripRecorded;

/**
 * The Phase 00 foundation slice, in one place.
 *
 * This is the reference implementation later coordinators copy, so the ordering
 * is the point of the class rather than an implementation detail:
 *
 *   1. one bounded transaction;
 *   2. the state change and the outbox row inside it, linked;
 *   3. commit;
 *   4. nothing external, realtime, or provider-bound anywhere inside.
 *
 * The event is recorded directly through OutboxRecorder rather than buffered on
 * the context, because this slice needs the event_id *during* the transaction
 * to set the foreign key. Buffering would force a post-commit update, which
 * would leave a window where the row exists with a null link — exactly the kind
 * of "almost atomic" the outbox pattern exists to eliminate.
 *
 * The handler returns typed data, never a framework model and never an HTTP
 * response. Idempotency is applied by the caller around this handler so the
 * transaction stays short: comparing request hashes and serializing a response
 * inside the transaction would lengthen every lock it holds.
 */
final class RecordRoundTripHandler
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly OutboxRecorder $outbox,
        private readonly DiagnosticsRepository $diagnostics,
        private readonly IdentityGenerator $identities,
        private readonly Clock $clock,
    ) {
    }

    public function handle(RecordRoundTripCommand $command): RoundTripResult
    {
        $diagnosticsId = $this->identities->next();
        $recordedAt = $this->clock->now();

        $event = new DiagnosticsRoundTripRecorded(
            $diagnosticsId,
            $command->label,
            $command->echoDelayMs,
            $recordedAt,
        );

        return $this->transactions->run(
            function (TransactionContext $context) use ($command, $diagnosticsId, $recordedAt, $event): RoundTripResult {
                $outboxEventId = $this->outbox->record($event, $context);

                $this->diagnostics->record(
                    $diagnosticsId,
                    $command->label,
                    $command->echoDelayMs,
                    $outboxEventId,
                    $context->correlationId(),
                    $recordedAt,
                );

                return new RoundTripResult(
                    diagnosticsId: $diagnosticsId,
                    outboxEventId: $outboxEventId,
                    committedAt: $recordedAt,
                    idempotentReplay: false,
                );
            },
        );
    }
}
