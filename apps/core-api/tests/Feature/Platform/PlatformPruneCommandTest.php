<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Time\FrozenClock;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function platformPruneNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-28 12:00:00', new DateTimeZone('UTC'));
}

function insertPlatformPruneOutbox(
    string $status,
    DateTimeImmutable $createdAt,
    ?DateTimeImmutable $processedAt,
): string {
    $ids = app(IdentityGenerator::class);
    $eventId = $ids->next()->value;

    DB::table('outbox_events')->insert([
        'event_id' => $eventId,
        'event_type' => 'platform.diagnostics_round_trip_recorded',
        'schema_version' => 1,
        'aggregate_type' => 'Diagnostics',
        'aggregate_id' => $ids->next()->value,
        'occurred_at' => $createdAt->format('Y-m-d H:i:s.uP'),
        'correlation_id' => $ids->next()->value,
        'classification' => 'internal',
        'payload' => json_encode(['label' => 'prune-test'], JSON_THROW_ON_ERROR),
        'status' => $status,
        'attempts' => 0,
        'available_at' => $createdAt->format('Y-m-d H:i:s.uP'),
        'processed_at' => $processedAt?->format('Y-m-d H:i:s.uP'),
        'created_at' => $createdAt->format('Y-m-d H:i:s.uP'),
    ]);

    return $eventId;
}

it('deletes expired processed outbox, idempotency, and diagnostics while keeping live and dead-letter rows', function () {
    $now = platformPruneNow();
    app()->instance(Clock::class, new FrozenClock($now));
    $days = (int) config('platform.outbox.retention_days', 7);
    $old = $now->modify(sprintf('-%d days', $days + 1));
    $recent = $now->modify('-1 day');

    $oldProcessed = insertPlatformPruneOutbox('PROCESSED', $old, $old);
    $recentProcessed = insertPlatformPruneOutbox('PROCESSED', $recent, $recent);
    $deadLetter = insertPlatformPruneOutbox('DEAD_LETTER', $old, null);
    $pending = insertPlatformPruneOutbox('PENDING', $old, null);

    $ids = app(IdentityGenerator::class);
    DB::table('idempotency_keys')->insert([
        [
            'key_hash' => str_repeat('a', 64),
            'operation_id' => 'platform.prune.expired',
            'request_hash' => str_repeat('b', 64),
            'state' => 'SUCCEEDED',
            'status_code' => 200,
            'response_reference' => $ids->next()->value,
            'safe_error_class' => null,
            'created_at' => $old->format('Y-m-d H:i:s.uP'),
            'updated_at' => $old->format('Y-m-d H:i:s.uP'),
            'expires_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s.uP'),
        ],
        [
            'key_hash' => str_repeat('c', 64),
            'operation_id' => 'platform.prune.live',
            'request_hash' => str_repeat('d', 64),
            'state' => 'SUCCEEDED',
            'status_code' => 200,
            'response_reference' => $ids->next()->value,
            'safe_error_class' => null,
            'created_at' => $recent->format('Y-m-d H:i:s.uP'),
            'updated_at' => $recent->format('Y-m-d H:i:s.uP'),
            'expires_at' => $now->modify('+1 day')->format('Y-m-d H:i:s.uP'),
        ],
    ]);

    $oldDiag = $ids->next()->value;
    $liveDiag = $ids->next()->value;
    DB::table('platform_diagnostics')->insert([
        [
            'id' => $oldDiag,
            'label' => 'prune-old',
            'echo_delay_ms' => 0,
            'correlation_id' => $ids->next()->value,
            'recorded_at' => $old->format('Y-m-d H:i:s.uP'),
            'consumed_count' => 0,
        ],
        [
            'id' => $liveDiag,
            'label' => 'prune-live',
            'echo_delay_ms' => 0,
            'correlation_id' => $ids->next()->value,
            'recorded_at' => $recent->format('Y-m-d H:i:s.uP'),
            'consumed_count' => 0,
        ],
    ]);

    $this->artisan('platform:prune', ['--dry-run' => true])->assertSuccessful();
    $this->assertDatabaseHas('outbox_events', ['event_id' => $oldProcessed]);

    $this->artisan('platform:prune')->assertSuccessful();

    $this->assertDatabaseMissing('outbox_events', ['event_id' => $oldProcessed]);
    $this->assertDatabaseHas('outbox_events', ['event_id' => $recentProcessed]);
    $this->assertDatabaseHas('outbox_events', ['event_id' => $deadLetter]);
    $this->assertDatabaseHas('outbox_events', ['event_id' => $pending]);
    $this->assertDatabaseMissing('idempotency_keys', ['key_hash' => str_repeat('a', 64)]);
    $this->assertDatabaseHas('idempotency_keys', ['key_hash' => str_repeat('c', 64)]);
    $this->assertDatabaseMissing('platform_diagnostics', ['id' => $oldDiag]);
    $this->assertDatabaseHas('platform_diagnostics', ['id' => $liveDiag]);

    $this->artisan('platform:prune')->assertSuccessful();
    $this->assertDatabaseHas('outbox_events', ['event_id' => $recentProcessed]);
    $this->assertDatabaseHas('outbox_events', ['event_id' => $deadLetter]);
    $this->assertDatabaseHas('idempotency_keys', ['key_hash' => str_repeat('c', 64)]);
    $this->assertDatabaseHas('platform_diagnostics', ['id' => $liveDiag]);
});

it('registers platform prune, access prune, and failed-job prune on the scheduler', function () {
    $events = collect(app(Schedule::class)->events());

    $platform = $events->first(fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'platform:prune'));
    $access = $events->first(fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'access:prune-expired'));
    $failed = $events->first(fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'queue:prune-failed'));
    $auth = $events->first(fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'auth:prune-expired'));

    expect($platform)->not->toBeNull()
        ->and($platform->expression)->toBe('0 0 * * *')
        ->and($platform->withoutOverlapping)->toBeTrue()
        ->and($platform->onOneServer)->toBeTrue()
        ->and($access)->not->toBeNull()
        ->and($access->expression)->toBe('0 * * * *')
        ->and($access->withoutOverlapping)->toBeTrue()
        ->and($access->onOneServer)->toBeTrue()
        ->and($failed)->not->toBeNull()
        ->and($failed->expression)->toBe('0 0 * * *')
        ->and($auth)->not->toBeNull()
        ->and($auth->withoutOverlapping)->toBeTrue()
        ->and($auth->onOneServer)->toBeTrue();
});
