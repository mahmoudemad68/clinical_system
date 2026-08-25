<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Application\Outbox\OutboxConsumer;
use App\Modules\Platform\Application\Outbox\RetryPolicy;
use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Infrastructure\Outbox\DiagnosticsRoundTripConsumer;
use App\Modules\Platform\Infrastructure\Outbox\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

/**
 * The post-commit half of ADR 0004, against real PostgreSQL.
 *
 * SKIP LOCKED, RETURNING, and lease expiry are all database behaviour, so
 * these cannot be meaningfully faked. The claim semantics are the entire point.
 */
final class OutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- exactly once

    #[Test]
    public function a_forced_duplicate_delivery_produces_exactly_one_effect(): void
    {
        // Phase 00 end-to-end gate: a committed synthetic event reaches a test
        // consumer exactly once in effect despite forced duplicate delivery.
        $diagnosticsId = $this->seedDiagnostics();
        $eventId = $this->seedEvent($diagnosticsId);

        $dispatcher = $this->dispatcher();
        $dispatcher->dispatchBatch();

        $row = DB::table('platform_diagnostics')->where('id', $diagnosticsId)->first();
        $this->assertSame(1, (int) $row->consumed_count);
        $firstConsumedAt = $row->consumed_at;
        $this->assertNotNull($firstConsumedAt);

        // Force a redelivery of the same event by returning it to the pool,
        // simulating an at-least-once duplicate.
        DB::table('outbox_events')->where('event_id', $eventId)->update([
            'status' => 'PENDING',
            'processed_at' => null,
        ]);

        $dispatcher->dispatchBatch();

        $after = DB::table('platform_diagnostics')->where('id', $diagnosticsId)->first();

        // Delivered twice, applied once.
        $this->assertSame(1, (int) $after->consumed_count, 'The effect must not be applied twice.');
        $this->assertSame($firstConsumedAt, $after->consumed_at, 'The original effect must not be overwritten.');
    }

    #[Test]
    public function a_processed_row_is_not_claimed_again(): void
    {
        $diagnosticsId = $this->seedDiagnostics();
        $this->seedEvent($diagnosticsId);

        $dispatcher = $this->dispatcher();

        $this->assertSame(1, $dispatcher->dispatchBatch());
        // Second pass finds nothing due: the row is PROCESSED.
        $this->assertSame(0, $dispatcher->dispatchBatch());
    }

    // -------------------------------------------------------------- claim

    #[Test]
    public function two_workers_claim_disjoint_row_sets(): void
    {
        // SKIP LOCKED is what allows horizontal scaling of the worker pool
        // without coordination. If both workers claimed the same rows, every
        // effect would double.
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->seedEvent($this->seedDiagnostics());
        }

        $workerA = $this->dispatcher('worker-a', batchSize: 3);
        $workerB = $this->dispatcher('worker-b', batchSize: 3);

        $workerA->dispatchBatch();
        $workerB->dispatchBatch();

        $processed = DB::table('outbox_events')->where('status', 'PROCESSED')->count();
        $this->assertSame(6, $processed, 'Every row should be processed exactly once across both workers.');

        // No row was consumed twice.
        $doubled = DB::table('platform_diagnostics')->where('consumed_count', '>', 1)->count();
        $this->assertSame(0, $doubled);
    }

    #[Test]
    public function a_row_scheduled_for_the_future_is_not_claimed(): void
    {
        $eventId = $this->seedEvent($this->seedDiagnostics());

        DB::table('outbox_events')->where('event_id', $eventId)->update([
            'status' => 'FAILED',
            'attempts' => 1,
            'available_at' => now()->addHour(),
        ]);

        $this->assertSame(0, $this->dispatcher()->dispatchBatch(), 'Backoff must be respected.');
    }

    // ---------------------------------------------------- lease recovery

    #[Test]
    public function rows_left_by_a_dead_worker_are_recovered_after_the_lease_expires(): void
    {
        // The kill-a-worker scenario. Without lease recovery these rows would
        // stay CLAIMED forever and their effects would never happen.
        $diagnosticsId = $this->seedDiagnostics();
        $eventId = $this->seedEvent($diagnosticsId);

        // Simulate a worker that claimed the row and then died.
        DB::table('outbox_events')->where('event_id', $eventId)->update([
            'status' => 'CLAIMED',
            'claimed_by' => 'dead-worker',
            'claimed_at' => now()->subMinutes(10),
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        $survivor = $this->dispatcher('survivor');

        // Nothing is claimable while the row is held.
        $this->assertSame(0, $survivor->dispatchBatch());

        $recovered = $survivor->recoverExpiredLeases();
        $this->assertSame(1, $recovered);

        $this->assertSame(1, $survivor->dispatchBatch());
        $this->assertSame(
            1,
            (int) DB::table('platform_diagnostics')->where('id', $diagnosticsId)->value('consumed_count'),
        );
    }

    #[Test]
    public function a_live_lease_is_not_recovered(): void
    {
        // Recovering a lease that has not expired would let two workers process
        // the same row concurrently, which is worse than a delayed delivery.
        $eventId = $this->seedEvent($this->seedDiagnostics());

        DB::table('outbox_events')->where('event_id', $eventId)->update([
            'status' => 'CLAIMED',
            'claimed_by' => 'busy-worker',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        $this->assertSame(0, $this->dispatcher('other')->recoverExpiredLeases());
    }

    // ------------------------------------------------- failure handling

    #[Test]
    public function a_failing_consumer_schedules_a_retry_with_backoff(): void
    {
        $eventId = $this->seedEvent($this->seedDiagnostics());

        $dispatcher = $this->dispatcherWith($this->alwaysFailingConsumer());
        $dispatcher->dispatchBatch();

        $row = DB::table('outbox_events')->where('event_id', $eventId)->first();

        $this->assertSame('FAILED', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->last_error_class);
        // A stable label, never the exception message.
        $this->assertSame('runtime_exception', $row->last_error_class);
        // The claim was released so another worker can take it when due.
        $this->assertNull($row->claimed_by);
        $this->assertNull($row->lease_expires_at);
    }

    #[Test]
    public function an_exhausted_row_moves_to_dead_letter_and_is_not_discarded(): void
    {
        $eventId = $this->seedEvent($this->seedDiagnostics());

        // One attempt from exhaustion.
        DB::table('outbox_events')->where('event_id', $eventId)->update(['attempts' => 2]);

        $dispatcher = $this->dispatcherWith(
            $this->alwaysFailingConsumer(),
            new RetryPolicy(baseSeconds: 1, maxSeconds: 10, maxAttempts: 3),
        );
        $dispatcher->dispatchBatch();

        $row = DB::table('outbox_events')->where('event_id', $eventId)->first();

        $this->assertSame('DEAD_LETTER', $row->status);
        $this->assertSame(3, (int) $row->attempts);

        // Still present. An exhausted event is operator-visible, never deleted.
        $this->assertNotNull($row);
        $this->assertSame(0, $this->dispatcher()->dispatchBatch(), 'Dead-lettered rows are not re-claimed.');
    }

    #[Test]
    public function an_event_with_no_registered_consumer_is_dead_lettered(): void
    {
        $eventId = $this->seedEvent($this->seedDiagnostics(), eventType: 'platform.nobody_handles_this');

        $this->dispatcher()->dispatchBatch();

        $row = DB::table('outbox_events')->where('event_id', $eventId)->first();

        // Not retried forever: a missing consumer is a deployment mistake and
        // should surface, not spin.
        $this->assertSame('DEAD_LETTER', $row->status);
        $this->assertSame('no_consumer_registered', $row->last_error_class);
    }

    #[Test]
    public function an_unsupported_schema_version_is_rejected_safely(): void
    {
        // Consumers accept current and previous compatible versions and reject
        // unknown incompatible ones rather than guessing at the payload.
        $eventId = $this->seedEvent($this->seedDiagnostics(), schemaVersion: 99);

        $this->dispatcher()->dispatchBatch();

        $row = DB::table('outbox_events')->where('event_id', $eventId)->first();

        $this->assertSame('DEAD_LETTER', $row->status);
        $this->assertSame('unsupported_schema_version', $row->last_error_class);
    }

    // ------------------------------------------------------------ helpers

    private function dispatcher(string $workerId = 'test-worker', int $batchSize = 100): OutboxDispatcher
    {
        $dispatcher = new OutboxDispatcher(
            DB::connection(),
            app(Clock::class),
            new RetryPolicy,
            new NullLogger,
            $workerId,
            $batchSize,
            leaseSeconds: 60,
        );

        $dispatcher->register(new DiagnosticsRoundTripConsumer(DB::connection(), app(Clock::class)));

        return $dispatcher;
    }

    private function dispatcherWith(OutboxConsumer $consumer, ?RetryPolicy $policy = null): OutboxDispatcher
    {
        $dispatcher = new OutboxDispatcher(
            DB::connection(),
            app(Clock::class),
            $policy ?? new RetryPolicy,
            new NullLogger,
            'failing-worker',
            100,
            60,
        );

        $dispatcher->register($consumer);

        return $dispatcher;
    }

    private function alwaysFailingConsumer(): OutboxConsumer
    {
        return new class implements OutboxConsumer
        {
            public function handles(): string
            {
                return 'platform.diagnostics_round_trip_recorded';
            }

            public function supportedVersions(): array
            {
                return [1];
            }

            public function consume(string $eventId, array $payload): void
            {
                throw new RuntimeException('downstream unavailable');
            }
        };
    }

    private function seedDiagnostics(): string
    {
        $id = (string) UuidV7::generate();

        DB::table('platform_diagnostics')->insert([
            'id' => $id,
            'label' => 'outbox-test',
            'echo_delay_ms' => 0,
            'correlation_id' => (string) UuidV7::generate(),
            'recorded_at' => now(),
            'consumed_count' => 0,
        ]);

        return $id;
    }

    private function seedEvent(
        string $diagnosticsId,
        string $eventType = 'platform.diagnostics_round_trip_recorded',
        int $schemaVersion = 1,
    ): string {
        $eventId = (string) UuidV7::generate();

        DB::table('outbox_events')->insert([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'aggregate_type' => 'Diagnostics',
            'aggregate_id' => $diagnosticsId,
            'occurred_at' => now(),
            'correlation_id' => (string) UuidV7::generate(),
            'classification' => 'internal',
            'payload' => json_encode([
                'diagnostics_id' => $diagnosticsId,
                'label' => 'outbox-test',
                'echo_delay_ms' => 0,
                'recorded_at' => now()->toRfc3339String(),
            ], JSON_THROW_ON_ERROR),
            'status' => 'PENDING',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
        ]);

        return $eventId;
    }
}
