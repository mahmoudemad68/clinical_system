<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\WorkerStarting as QueueWorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Services\Persistence\WorkerDatabaseIdentity;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Uid\UuidV7;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function (): void {
    if (app()->bound(WorkerDatabaseIdentity::class)) {
        app(WorkerDatabaseIdentity::class)->restore();
    }
});

it('keeps http requests on the serving pgsql connection', function () {
    $this->getJson('/live')->assertOk();

    $identity = app(WorkerDatabaseIdentity::class);

    expect($identity->isActive())->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('pgsql')
        ->and($identity->currentRole())->not->toBe(WorkerDatabaseIdentity::ROLE);
});

it('can log in as clinic_app without selecting that identity for workers', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_app'");
    if ($role === null) {
        $this->markTestSkipped('clinic_app is not present on this cluster');
    }

    config(['database.connections.pgsql_app_probe' => array_merge(
        (array) config('database.connections.pgsql'),
        [
            'username' => 'clinic_app',
            'password' => 'local_dev_only_not_a_secret',
            'url' => null,
        ],
    )]);

    $this->getJson('/live')->assertOk();

    expect(app(WorkerDatabaseIdentity::class)->currentRole('pgsql_app_probe'))->toBe('clinic_app')
        ->and(DB::getDefaultConnection())->toBe('pgsql')
        ->and(app(WorkerDatabaseIdentity::class)->isActive())->toBeFalse()
        ->and(app(WorkerDatabaseIdentity::class)->currentRole())->not->toBe('clinic_app');
});

it('uses clinic_worker for outbox:work', function () {
    skipUnlessWorkerConnection();

    $this->artisan('outbox:work', ['--once' => true])->assertSuccessful();

    expect(app(WorkerDatabaseIdentity::class)->lastVerifiedRole())->toBe('clinic_worker')
        ->and(app(WorkerDatabaseIdentity::class)->isActive())->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('pgsql')
        ->and(app(WorkerDatabaseIdentity::class)->currentRole())->not->toBe('clinic_worker');
});

it('uses clinic_worker when a Laravel queue worker starts', function () {
    skipUnlessWorkerConnection();

    Event::dispatch(new QueueWorkerStarting('redis', 'critical', new WorkerOptions));

    $identity = app(WorkerDatabaseIdentity::class);

    expect($identity->isActive())->toBeTrue()
        ->and(DB::getDefaultConnection())->toBe('pgsql_worker')
        ->and($identity->currentRole())->toBe('clinic_worker')
        ->and($identity->currentRole('pgsql'))->toBe('clinic_worker');
});

it('uses clinic_worker for the horizon:work command path', function () {
    skipUnlessWorkerConnection();

    $identity = app(WorkerDatabaseIdentity::class);

    expect($identity->isWorkerCommand('horizon:work'))->toBeTrue()
        ->and($identity->isWorkerCommand('horizon'))->toBeTrue()
        ->and($identity->isWorkerCommand('octane:start'))->toBeFalse()
        ->and($identity->isWorkerCommand('serve'))->toBeFalse();

    $identity->handleCommandStarting(new CommandStarting(
        'horizon:work',
        new ArrayInput([]),
        new NullOutput,
    ));

    expect($identity->currentRole())->toBe('clinic_worker')
        ->and(DB::getDefaultConnection())->toBe('pgsql_worker');
});

it('rolls back worker transactions on the clinic_worker connection', function () {
    skipUnlessWorkerConnection();

    $identity = app(WorkerDatabaseIdentity::class);
    $identity->activate();

    $eventId = (string) UuidV7::generate();
    $connection = DB::connection();

    expect($connection->getName())->toBe('pgsql_worker')
        ->and($identity->currentRole())->toBe('clinic_worker');

    $connection->beginTransaction();
    $connection->table('outbox_events')->insert([
        'event_id' => $eventId,
        'event_type' => 'platform.diagnostics_round_trip_recorded',
        'schema_version' => 1,
        'aggregate_type' => 'Diagnostics',
        'aggregate_id' => (string) UuidV7::generate(),
        'occurred_at' => now(),
        'correlation_id' => (string) UuidV7::generate(),
        'classification' => 'internal',
        'payload' => json_encode(['diagnostics_id' => (string) UuidV7::generate()], JSON_THROW_ON_ERROR),
        'status' => 'PENDING',
        'attempts' => 0,
        'available_at' => now(),
        'created_at' => now(),
    ]);

    expect($connection->table('outbox_events')->where('event_id', $eventId)->exists())->toBeTrue();

    $connection->rollBack();

    expect($connection->table('outbox_events')->where('event_id', $eventId)->exists())->toBeFalse();
});

it('refuses to activate when the worker username is the serving role', function () {
    config(['database.connections.pgsql_worker.username' => 'clinic_app']);

    expect(fn () => app(WorkerDatabaseIdentity::class)->activate())
        ->toThrow(RuntimeException::class, 'Queue worker database username must be clinic_worker.');

    expect(DB::getDefaultConnection())->toBe('pgsql')
        ->and(app(WorkerDatabaseIdentity::class)->isActive())->toBeFalse();
});

it('does not let an active worker silently fall back to the serving connection', function () {
    skipUnlessWorkerConnection();

    $identity = app(WorkerDatabaseIdentity::class);
    $identity->activate();

    DB::setDefaultConnection('pgsql');

    expect(fn () => $identity->handleJobProcessing())
        ->toThrow(RuntimeException::class);
});

/**
 * Worker tests need a live clinic_worker login, not only has_table_privilege.
 */
function skipUnlessWorkerConnection(): void
{
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_worker'");
    if ($role === null) {
        test()->markTestSkipped('clinic_worker is not present on this cluster');
    }

    try {
        DB::connection('pgsql_worker')->selectOne('select 1');
    } catch (Throwable) {
        test()->markTestSkipped('pgsql_worker cannot connect');
    }
}
