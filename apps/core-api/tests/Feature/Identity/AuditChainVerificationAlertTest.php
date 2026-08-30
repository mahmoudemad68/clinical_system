<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\Checkpoint\CreateAuditChainCheckpoint;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Services\Telemetry\AuditChainVerificationTelemetry;
use Modules\Platform\Services\Telemetry\MetricsExposition;
use Modules\Platform\Services\Telemetry\MetricsRenderer;
use Modules\Platform\Services\Time\FrozenClock;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $cache = app(Repository::class);
    $cache->forget(AuditChainVerificationTelemetry::KEY_OK);
    $cache->forget(AuditChainVerificationTelemetry::KEY_LAST_RUN);
    $cache->forget(AuditChainVerificationTelemetry::KEY_LAST_SUCCESS);
    $cache->forget(AuditChainVerificationTelemetry::KEY_FAILURES);
    $cache->forget(AuditChainVerificationTelemetry::KEY_OBSERVED);
});

function rebindAuditAlertClock(DateTimeImmutable $now): FrozenClock
{
    $clock = new FrozenClock($now);
    app()->instance(Clock::class, $clock);
    app()->forgetInstance(AuditChainVerificationTelemetry::class);
    app()->forgetInstance(MetricsRenderer::class);
    app()->forgetInstance(MetricsExposition::class);

    return $clock;
}

/**
 * @return array{secret: string, public: string}
 */
function alertAuditSodiumKeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'secret' => bin2hex(sodium_crypto_sign_secretkey($pair)),
        'public' => bin2hex(sodium_crypto_sign_publickey($pair)),
    ];
}

/**
 * @return array{secret: string, public: string}
 */
function alertAuditConfigureCheckpoints(): array
{
    if (! extension_loaded('sodium')) {
        test()->markTestSkipped('sodium extension is required for audit chain checkpoints');
    }

    $pair = alertAuditSodiumKeyPair();
    Storage::fake('audit_checkpoints');
    config([
        'audit.checkpoint.enabled' => true,
        'audit.checkpoint.required' => true,
        'audit.checkpoint.disk' => 'audit_checkpoints',
        'audit.checkpoint.prefix' => 'checkpoints',
        'audit.checkpoint.key_id' => 'v1',
        'audit.checkpoint.private_key' => $pair['secret'],
        'audit.checkpoint.public_key' => $pair['public'],
        'audit.checkpoint.private_key_file' => '',
        'audit.checkpoint.public_key_file' => '',
    ]);

    return $pair;
}

function alertAuditAppend(string $eventName, Identifier $userId): Identifier
{
    return app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($eventName, $userId): Identifier {
            return app(AppendAuditEvent::class)->append(
                $tx,
                $eventName,
                'user',
                $userId,
                ['reason_code' => 'alert_test'],
                $userId,
                'user',
            );
        },
    );
}

/**
 * @return array{ok: float, last_run: float, last_success: float, failures: float, staleness: float, body: string}
 */
function scrapeAuditVerificationMetrics(): array
{
    $body = (string) test()->get('/metrics')->assertOk()->getContent();
    $read = function (string $name) use ($body): float {
        if (preg_match('/^'.preg_quote($name, '/').'\{[^}]*\} ([0-9eE.+-]+)$/m', $body, $matches) !== 1) {
            throw new RuntimeException($name.' is missing from /metrics');
        }

        return (float) $matches[1];
    };

    return [
        'ok' => $read('clinic_audit_chain_verification_ok'),
        'last_run' => $read('clinic_audit_chain_verification_last_run_timestamp_seconds'),
        'last_success' => $read('clinic_audit_chain_verification_last_success_timestamp_seconds'),
        'failures' => $read('clinic_audit_chain_verification_failures_total'),
        'staleness' => $read('clinic_audit_chain_verification_staleness_seconds'),
        'body' => $body,
    ];
}

it('exports a healthy signal after a passing verification', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $id = alertAuditAppend('test.audit.alert_healthy', $userId);
    $rowHash = bin2hex(BinaryColumn::asString(
        DB::table('audit_events')->where('id', $id->value)->value('row_hash'),
    ));

    $this->artisan('audit:verify-chain')->assertSuccessful();
    $metrics = scrapeAuditVerificationMetrics();

    expect($metrics['ok'])->toBe(1.0)
        ->and($metrics['last_run'])->toBeGreaterThan(0)
        ->and($metrics['last_success'])->toBe($metrics['last_run'])
        ->and($metrics['failures'])->toBe(0.0)
        ->and($metrics['staleness'])->toBeLessThan(60)
        ->and($metrics['body'])->not->toContain($rowHash)
        ->and($metrics['body'])->not->toContain('patient_id')
        ->and($metrics['body'])->not->toContain('row_hash');
});

it('exports a failing signal after database chain corruption', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $id = alertAuditAppend('test.audit.alert_corrupt', $userId);

    $this->artisan('audit:verify-chain')->assertSuccessful();

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $id->value)->update(['event_name' => 'test.audit.alert_tampered']);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $this->artisan('audit:verify-chain')->assertFailed();
    $metrics = scrapeAuditVerificationMetrics();

    expect($metrics['ok'])->toBe(0.0)
        ->and($metrics['last_run'])->toBeGreaterThan(0)
        ->and($metrics['failures'])->toBeGreaterThanOrEqual(1.0);
});

it('exports a failing signal after an external checkpoint signature failure', function () {
    $keys = alertAuditConfigureCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    alertAuditAppend('test.audit.alert_checkpoint_sig', $userId);
    app(CreateAuditChainCheckpoint::class)->create();

    $files = Storage::disk('audit_checkpoints')->allFiles('checkpoints');
    expect($files)->not->toBeEmpty();
    $path = (string) $files[array_key_last($files)];
    $envelope = json_decode((string) Storage::disk('audit_checkpoints')->get($path), true, 8, JSON_THROW_ON_ERROR);
    $signature = base64_decode((string) $envelope['signature'], true);
    expect($signature)->toBeString();
    $last = strlen((string) $signature) - 1;
    $signature[$last] = chr(ord($signature[$last]) ^ 0xFF);
    $envelope['signature'] = base64_encode((string) $signature);
    Storage::disk('audit_checkpoints')->put($path, json_encode($envelope, JSON_THROW_ON_ERROR));

    $this->artisan('audit:verify-chain')->assertFailed();
    $metrics = scrapeAuditVerificationMetrics();

    expect($metrics['ok'])->toBe(0.0)
        ->and($metrics['failures'])->toBeGreaterThanOrEqual(1.0)
        ->and($metrics['body'])->not->toContain($keys['secret'])
        ->and($metrics['body'])->not->toContain($keys['public'])
        ->and($metrics['body'])->not->toContain((string) $envelope['signature']);
});

it('exports a failing signal when a required checkpoint is missing', function () {
    alertAuditConfigureCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    alertAuditAppend('test.audit.alert_checkpoint_missing', $userId);

    $this->artisan('audit:verify-chain')->assertFailed();
    $metrics = scrapeAuditVerificationMetrics();

    expect($metrics['ok'])->toBe(0.0)
        ->and($metrics['last_run'])->toBeGreaterThan(0)
        ->and($metrics['failures'])->toBeGreaterThanOrEqual(1.0);
});

it('returns health to success after a failure without resetting the failure counter', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    $id = alertAuditAppend('test.audit.alert_recover', $userId);

    $this->artisan('audit:verify-chain')->assertSuccessful();

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $id->value)->update(['event_name' => 'test.audit.alert_recover_bad']);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $this->artisan('audit:verify-chain')->assertFailed();
    $afterFailure = scrapeAuditVerificationMetrics();

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $id->value)->update(['event_name' => 'test.audit.alert_recover']);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $this->artisan('audit:verify-chain')->assertSuccessful();
    $afterSuccess = scrapeAuditVerificationMetrics();

    expect($afterFailure['ok'])->toBe(0.0)
        ->and($afterFailure['failures'])->toBeGreaterThanOrEqual(1.0)
        ->and($afterSuccess['ok'])->toBe(1.0)
        ->and($afterSuccess['failures'])->toBe($afterFailure['failures'])
        ->and($afterSuccess['last_success'])->toBeGreaterThanOrEqual($afterSuccess['last_run'] - 5);
});

it('exports staleness above the 45 minute alert threshold when verification stops', function () {
    $clock = rebindAuditAlertClock(new DateTimeImmutable('2026-08-30T12:00:00.000000Z'));
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    alertAuditAppend('test.audit.alert_stale', $userId);
    $this->artisan('audit:verify-chain')->assertSuccessful();

    $clock->advance(2801);
    $metrics = scrapeAuditVerificationMetrics();

    expect($metrics['ok'])->toBe(1.0)
        ->and($metrics['staleness'])->toBeGreaterThan(2700)
        ->and($metrics['staleness'])->toBeLessThan(2900);
});

it('exports a failing signal when required checkpoint keys are missing', function () {
    alertAuditConfigureCheckpoints();
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    alertAuditAppend('test.audit.alert_keys_unavailable', $userId);
    app(CreateAuditChainCheckpoint::class)->create();

    config([
        'audit.checkpoint.public_key' => '',
        'audit.checkpoint.public_key_file' => '',
        'audit.checkpoint.private_key' => '',
        'audit.checkpoint.private_key_file' => '',
    ]);

    $this->artisan('audit:verify-chain')->assertFailed();
    $metrics = scrapeAuditVerificationMetrics();

    expect($metrics['ok'])->toBe(0.0)
        ->and($metrics['last_run'])->toBeGreaterThan(0)
        ->and($metrics['failures'])->toBeGreaterThanOrEqual(1.0);
});

it('treats a never-run verifier as stale after 45 minutes of scrapes', function () {
    $clock = rebindAuditAlertClock(new DateTimeImmutable('2026-08-30T12:00:00.000000Z'));
    $first = scrapeAuditVerificationMetrics();

    $clock->advance(2801);
    $later = scrapeAuditVerificationMetrics();

    expect($first['ok'])->toBe(0.0)
        ->and($first['last_run'])->toBe(0.0)
        ->and($first['staleness'])->toBeLessThan(60)
        ->and($later['ok'])->toBe(0.0)
        ->and($later['last_run'])->toBe(0.0)
        ->and($later['staleness'])->toBeGreaterThan(2700)
        ->and($later['staleness'])->toBeLessThan(2900);
});

it('schedules audit chain verification every fifteen minutes without silent overlap', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'audit:verify-chain'),
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->evenInMaintenanceMode)->toBeTrue();
});
