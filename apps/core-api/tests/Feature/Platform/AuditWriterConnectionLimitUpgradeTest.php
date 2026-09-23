<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Services\Persistence\AuditWriterConnectionLimit;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->auditWriterConfigSnapshot = [
        'database.connections.pgsql_owner' => config('database.connections.pgsql_owner'),
        'database.connections.pgsql_migrator' => config('database.connections.pgsql_migrator'),
    ];
});

afterEach(function (): void {
    config([
        'database.connections.pgsql_owner' => $this->auditWriterConfigSnapshot['database.connections.pgsql_owner'],
        'database.connections.pgsql_migrator' => $this->auditWriterConfigSnapshot['database.connections.pgsql_migrator'],
    ]);
    DB::purge('pgsql_owner');
    DB::purge('pgsql_migrator');
    DB::setDefaultConnection('pgsql');

    auditWriterRestoreLimitBestEffort();
    auditWriterRestoreMigrationRow();
});

function auditWriterLimitMigrationName(): string
{
    return '2026_09_23_140000_raise_audit_writer_connection_limit';
}

function auditWriterLimit(?string $connection = null): ?int
{
    return app(AuditWriterConnectionLimit::class)->currentLimit($connection);
}

function auditWriterBindOwnerIfPresent(): bool
{
    $exists = DB::selectOne("select 1 as ok from pg_roles where rolname = 'clinic_owner'");
    if ($exists === null) {
        return false;
    }

    config([
        'database.connections.pgsql_owner.username' => 'clinic_owner',
        'database.connections.pgsql_owner.password' => 'local_dev_only_not_a_secret',
        'database.connections.pgsql_owner.url' => null,
        'database.connections.pgsql_owner.host' => config('database.connections.pgsql.host'),
        'database.connections.pgsql_owner.port' => config('database.connections.pgsql.port'),
        'database.connections.pgsql_owner.database' => config('database.connections.pgsql.database'),
    ]);
    DB::purge('pgsql_owner');

    try {
        DB::connection('pgsql_owner')->selectOne('select 1 as ok');

        return true;
    } catch (Throwable) {
        config(['database.connections.pgsql_owner.username' => '']);
        DB::purge('pgsql_owner');

        return false;
    }
}

function auditWriterRestoreLimitBestEffort(): void
{
    foreach (['pgsql_migrator', AuditWriterConnectionLimit::OWNER_CONNECTION] as $connection) {
        try {
            app(AuditWriterConnectionLimit::class)->apply(AuditWriterConnectionLimit::MINIMUM, $connection);

            return;
        } catch (Throwable) {
            continue;
        }
    }
}

function auditWriterPrivilegedConnection(): string
{
    if (auditWriterBindOwnerIfPresent()) {
        return AuditWriterConnectionLimit::OWNER_CONNECTION;
    }

    try {
        DB::connection('pgsql_migrator')->statement(
            'ALTER ROLE '.AuditWriterConnectionLimit::ROLE.' CONNECTION LIMIT '.AuditWriterConnectionLimit::MINIMUM,
        );

        return 'pgsql_migrator';
    } catch (Throwable) {
        test()->markTestSkipped('No privileged identity can ALTER ROLE clinic_audit_writer');
    }
}

function auditWriterForgetMigrationRow(): void
{
    DB::connection('pgsql_migrator')
        ->table('migrations')
        ->where('migration', auditWriterLimitMigrationName())
        ->delete();
}

function auditWriterMigrationRecorded(): bool
{
    return DB::connection('pgsql_migrator')
        ->table('migrations')
        ->where('migration', auditWriterLimitMigrationName())
        ->exists();
}

function auditWriterRestoreMigrationRow(): void
{
    if (auditWriterMigrationRecorded()) {
        return;
    }

    $batch = (int) DB::connection('pgsql_migrator')->table('migrations')->max('batch');

    DB::connection('pgsql_migrator')->table('migrations')->insert([
        'migration' => auditWriterLimitMigrationName(),
        'batch' => $batch > 0 ? $batch : 1,
    ]);
}

function auditWriterClearOwnerConfig(): void
{
    config([
        'database.connections.pgsql_owner.username' => '',
        'database.connections.pgsql_owner.password' => '',
        'database.connections.pgsql_owner.url' => null,
    ]);
    DB::purge('pgsql_owner');
}

it('raises clinic_audit_writer from 10 to 40 through the canonical upgrade path', function () {
    skipUnlessAuditWriterConnection();

    $privileged = auditWriterPrivilegedConnection();
    app(AuditWriterConnectionLimit::class)->apply(10, $privileged);
    expect(auditWriterLimit('pgsql_migrator'))->toBe(10);

    auditWriterForgetMigrationRow();
    expect(auditWriterMigrationRecorded())->toBeFalse();

    $this->artisan('platform:provision-postgres-roles')->assertSuccessful();
    expect(auditWriterLimit('pgsql_migrator'))->toBe(40);

    $this->artisan('migrate', [
        '--database' => 'pgsql_migrator',
        '--force' => true,
        '--no-interaction' => true,
        '--path' => 'database/migrations/'.auditWriterLimitMigrationName().'.php',
    ])->assertSuccessful();

    expect(auditWriterLimit('pgsql_migrator'))->toBe(40)
        ->and(auditWriterMigrationRecorded())->toBeTrue();
});

it('does not record the raise migration when the privileged role change cannot be applied', function () {
    skipUnlessAuditWriterConnection();

    $appRole = DB::selectOne("select 1 as ok from pg_roles where rolname = 'clinic_app'");
    if ($appRole === null) {
        test()->markTestSkipped('clinic_app is not present on this cluster');
    }

    $privileged = auditWriterPrivilegedConnection();
    app(AuditWriterConnectionLimit::class)->apply(10, $privileged);
    expect(auditWriterLimit('pgsql_migrator'))->toBe(10);

    auditWriterForgetMigrationRow();
    auditWriterClearOwnerConfig();

    config([
        'database.connections.pgsql_migrator.username' => 'clinic_app',
        'database.connections.pgsql_migrator.password' => 'local_dev_only_not_a_secret',
        'database.connections.pgsql_migrator.url' => null,
    ]);
    DB::purge('pgsql_migrator');
    DB::setDefaultConnection('pgsql_migrator');

    $migration = include database_path('migrations/'.auditWriterLimitMigrationName().'.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);

    DB::setDefaultConnection('pgsql');
    config([
        'database.connections.pgsql_migrator' => $this->auditWriterConfigSnapshot['database.connections.pgsql_migrator'],
    ]);
    DB::purge('pgsql_migrator');

    expect(auditWriterMigrationRecorded())->toBeFalse()
        ->and(auditWriterLimit())->toBe(10);

    expect(fn () => app(AuditWriterConnectionLimit::class)->assertSatisfied())
        ->toThrow(RuntimeException::class);
});

it('fails the provision command when owner is configured but cannot apply the limit', function () {
    skipUnlessAuditWriterConnection();

    $privileged = auditWriterPrivilegedConnection();
    app(AuditWriterConnectionLimit::class)->apply(10, $privileged);
    expect(auditWriterLimit('pgsql_migrator'))->toBe(10);

    config([
        'database.connections.pgsql_owner.username' => 'clinic_app',
        'database.connections.pgsql_owner.password' => 'local_dev_only_not_a_secret',
        'database.connections.pgsql_owner.url' => null,
        'database.connections.pgsql_owner.host' => config('database.connections.pgsql.host'),
        'database.connections.pgsql_owner.port' => config('database.connections.pgsql.port'),
        'database.connections.pgsql_owner.database' => config('database.connections.pgsql.database'),
    ]);
    DB::purge('pgsql_owner');

    $this->artisan('platform:provision-postgres-roles')->assertFailed();

    expect(auditWriterLimit('pgsql_migrator'))->toBe(10);
});

it('keeps the operator SQL file aligned with the required connection limit', function () {
    $sql = (string) file_get_contents(dirname(base_path(), 2).'/infra/docker/postgres/raise-audit-writer-connection-limit.sql');

    expect($sql)->toContain('ALTER ROLE clinic_audit_writer CONNECTION LIMIT 40')
        ->and($sql)->toContain('ON_ERROR_STOP');
});
