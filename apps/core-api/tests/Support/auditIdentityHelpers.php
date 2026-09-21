<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Services\Persistence\AuditDatabaseIdentity;
use Modules\Audit\Services\Persistence\PostgresAuditStore;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Services\Persistence\WorkerDatabaseIdentity;
use Modules\Verification\Services\Persistence\PostgresVerificationStore;
use Modules\Verification\Services\VerificationUploadProcessor;

/**
 * @param  array<string, mixed>  $connection
 */
function postgresRoleUrl(array $connection): string
{
    $username = rawurlencode((string) ($connection['username'] ?? ''));
    $password = rawurlencode((string) ($connection['password'] ?? ''));
    $host = (string) ($connection['host'] ?? '127.0.0.1');
    $port = (string) ($connection['port'] ?? '5432');
    $database = (string) ($connection['database'] ?? '');

    return "pgsql://{$username}:{$password}@{$host}:{$port}/{$database}";
}

function skipUnlessWorkerConnection(): void
{
    $role = DB::selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', ['clinic_worker']);
    if ($role === null) {
        test()->markTestSkipped('clinic_worker is not present on this cluster');
    }

    try {
        DB::connection('pgsql_worker')->selectOne('select 1');
    } catch (Throwable) {
        test()->markTestSkipped('pgsql_worker cannot connect');
    }
}

function skipUnlessAuditWriterConnection(): void
{
    $role = DB::selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', ['clinic_audit_writer']);
    if ($role === null) {
        test()->markTestSkipped('clinic_audit_writer is not present on this cluster');
    }

    try {
        DB::connection('pgsql_audit')->selectOne('select 1');
    } catch (Throwable) {
        test()->markTestSkipped('pgsql_audit cannot connect');
    }
}

function auditConnectionCurrentUser(): string
{
    $row = DB::connection('pgsql_audit')->selectOne('select current_user as username');

    return is_object($row) ? (string) $row->username : (string) ($row['username'] ?? '');
}

function workerConnectionCurrentUser(): string
{
    $row = DB::connection('pgsql_worker')->selectOne('select current_user as username');

    return is_object($row) ? (string) $row->username : (string) ($row['username'] ?? '');
}

function bindDedicatedAuditAppend(): void
{
    app()->forgetInstance(AppendAuditEvent::class);
    app()->instance(AppendAuditEvent::class, new PostgresAuditStore(
        app(AuditDatabaseIdentity::class)->connection(),
        app(IdentityGenerator::class),
    ));
}

function restoreTestingAuditAppend(): void
{
    app()->forgetInstance(AppendAuditEvent::class);
    app()->forgetInstance(AuditDatabaseIdentity::class);
    app()->singleton(AppendAuditEvent::class, static function ($app): AppendAuditEvent {
        return new PostgresAuditStore(
            $app->make(ConnectionInterface::class),
            $app->make(IdentityGenerator::class),
        );
    });
    DB::purge('pgsql_audit');
}

function commitDefaultConnectionForCrossRoleVisibility(): void
{
    $connection = DB::connection();
    while ($connection->transactionLevel() > 0) {
        $connection->commit();
    }
}

function activateWorkerWithDedicatedAudit(): WorkerDatabaseIdentity
{
    $identity = app(WorkerDatabaseIdentity::class);
    $identity->activate();
    app()->forgetInstance(PostgresVerificationStore::class);
    app()->forgetInstance(VerificationUploadProcessor::class);
    bindDedicatedAuditAppend();

    return $identity;
}

function workerNeverGainedAuditExecute(): bool
{
    $execute = DB::selectOne(
        "SELECT has_function_privilege('clinic_worker', 'clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, timestamptz)', 'EXECUTE') AS allowed",
    );

    return ! (bool) $execute->allowed;
}
