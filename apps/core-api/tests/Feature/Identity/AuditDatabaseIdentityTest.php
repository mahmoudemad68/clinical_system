<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Audit\Services\Persistence\AuditDatabaseIdentity;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Services\Persistence\WorkerDatabaseIdentity;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->auditConnectionSnapshot = (array) config('database.connections.pgsql_audit');
});

afterEach(function (): void {
    if (app()->bound(WorkerDatabaseIdentity::class)) {
        app(WorkerDatabaseIdentity::class)->restore();
    }

    config(['database.connections.pgsql_audit' => $this->auditConnectionSnapshot]);
    restoreTestingAuditAppend();
});

it('does not inherit a generic DB_URL identity when DB_AUDIT_URL is absent', function () {
    skipUnlessWorkerConnection();
    skipUnlessAuditWriterConnection();

    expect(config('database.connections.pgsql_audit.url') === null || config('database.connections.pgsql_audit.url') === '')->toBeTrue();
    expect(config('database.connections.pgsql.username'))->not->toBe('clinic_audit_writer')
        ->and(config('database.connections.pgsql_audit.username'))->toBe('clinic_audit_writer');

    $workerUrl = postgresRoleUrl((array) config('database.connections.pgsql_worker'));
    expect((string) parse_url($workerUrl, PHP_URL_USER))->toBe('clinic_worker')
        ->and((string) parse_url($workerUrl, PHP_URL_USER))->not->toBe('clinic_audit_writer');

    config(['database.connections.pgsql_audit_inherited' => array_merge(
        (array) config('database.connections.pgsql_audit'),
        [
            'url' => $workerUrl,
            'username' => 'clinic_audit_writer',
            'password' => (string) config('database.connections.pgsql_audit.password'),
        ],
    )]);
    DB::purge('pgsql_audit_inherited');

    $inherited = DB::connection('pgsql_audit_inherited')->selectOne('select current_user as username');
    DB::purge('pgsql_audit');

    expect((string) $inherited->username)->toBe('clinic_worker')
        ->and(auditConnectionCurrentUser())->toBe('clinic_audit_writer');
});

it('connects pgsql_audit as clinic_audit_writer when DB_AUDIT_URL is explicit', function () {
    skipUnlessAuditWriterConnection();

    $auditUrl = postgresRoleUrl((array) config('database.connections.pgsql_audit'));
    expect((string) parse_url($auditUrl, PHP_URL_USER))->toBe('clinic_audit_writer');

    config(['database.connections.pgsql_audit.url' => $auditUrl]);
    DB::purge('pgsql_audit');
    app()->forgetInstance(AuditDatabaseIdentity::class);

    expect(auditConnectionCurrentUser())->toBe('clinic_audit_writer')
        ->and(app(AuditDatabaseIdentity::class)->assertWriterRole())->toBe('clinic_audit_writer');
});

it('fails closed when DB_AUDIT_URL points at a non-writer role', function () {
    skipUnlessWorkerConnection();
    skipUnlessAuditWriterConnection();

    $workerUrl = postgresRoleUrl((array) config('database.connections.pgsql_worker'));
    config(['database.connections.pgsql_audit.url' => $workerUrl]);
    DB::purge('pgsql_audit');
    app()->forgetInstance(AuditDatabaseIdentity::class);

    $thrown = null;
    try {
        app(AuditDatabaseIdentity::class)->assertWriterRole();
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Audit connection current_user must be clinic_audit_writer.')
        ->and($thrown->getMessage())->not->toContain('password')
        ->and($thrown->getMessage())->not->toContain('pgsql://')
        ->and($thrown->getMessage())->not->toContain((string) config('database.connections.pgsql_worker.password'));
});

it('appends audit events as clinic_audit_writer while the default worker identity stays clinic_worker', function () {
    skipUnlessWorkerConnection();
    skipUnlessAuditWriterConnection();

    $identity = activateWorkerWithDedicatedAudit();
    $objectId = app(IdentityGenerator::class)->next();

    expect($identity->currentRole())->toBe('clinic_worker')
        ->and($identity->currentRole('pgsql_worker'))->toBe('clinic_worker')
        ->and($identity->currentRole('pgsql_audit'))->toBe('clinic_audit_writer')
        ->and(DB::getDefaultConnection())->toBe('pgsql_worker');

    $eventId = app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($objectId): Identifier {
            return app(AppendAuditEvent::class)->append(
                $tx,
                'test.audit.worker_dedicated_connection',
                'user',
                $objectId,
                ['reason_code' => 'worker_audit_identity'],
                $objectId,
                'system',
            );
        },
    );

    expect($identity->currentRole())->toBe('clinic_worker')
        ->and($identity->currentRole('pgsql_audit'))->toBe('clinic_audit_writer')
        ->and(DB::connection('pgsql_audit')->table('audit_events')->where('id', $eventId->value)->exists())->toBeTrue()
        ->and(workerNeverGainedAuditExecute())->toBeTrue();

    $identity->restore();
    expect(app(VerifyAuditChain::class)->verify()['ok'])->toBeTrue();
});
