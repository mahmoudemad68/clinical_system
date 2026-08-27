<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Audit\Contracts\VerifyAuditChain;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Support\Identifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps reporter off identity tables and on reporting views only', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_reporter'");
    if ($role === null) {
        $this->markTestSkipped('clinic_reporter is not present on this cluster');
    }

    $users = DB::selectOne("SELECT has_table_privilege('clinic_reporter', 'users', 'SELECT') AS allowed");
    $otp = DB::selectOne("SELECT has_table_privilege('clinic_reporter', 'otp_requests', 'SELECT') AS allowed");
    $view = DB::selectOne("SELECT has_table_privilege('clinic_reporter', 'reporting.account_status_counts', 'SELECT') AS allowed");

    expect((bool) $users->allowed)->toBeFalse()
        ->and((bool) $otp->allowed)->toBeFalse()
        ->and((bool) $view->allowed)->toBeTrue();
});

it('lets the app execute the audit append function but not insert directly', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_app'");
    if ($role === null) {
        $this->markTestSkipped('clinic_app is not present on this cluster');
    }

    $insert = DB::selectOne("SELECT has_table_privilege('clinic_app', 'audit_events', 'INSERT') AS allowed");
    $update = DB::selectOne("SELECT has_table_privilege('clinic_app', 'audit_events', 'UPDATE') AS allowed");
    $delete = DB::selectOne("SELECT has_table_privilege('clinic_app', 'audit_events', 'DELETE') AS allowed");
    $execute = DB::selectOne("SELECT has_function_privilege('clinic_app', 'clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz)', 'EXECUTE') AS allowed");

    expect((bool) $insert->allowed)->toBeFalse()
        ->and((bool) $update->allowed)->toBeFalse()
        ->and((bool) $delete->allowed)->toBeFalse()
        ->and((bool) $execute->allowed)->toBeTrue();
});

it('keeps the worker off users and grants', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_worker'");
    if ($role === null) {
        $this->markTestSkipped('clinic_worker is not present on this cluster');
    }

    $users = DB::selectOne("SELECT has_table_privilege('clinic_worker', 'users', 'SELECT') AS allowed");
    $grants = DB::selectOne("SELECT has_table_privilege('clinic_worker', 'contextual_access_grants', 'UPDATE') AS allowed");
    $jobs = DB::selectOne("SELECT has_table_privilege('clinic_worker', 'jobs', 'UPDATE') AS allowed");

    expect((bool) $users->allowed)->toBeFalse()
        ->and((bool) $grants->allowed)->toBeFalse()
        ->and((bool) $jobs->allowed)->toBeTrue();
});

it('gives the backup role select on identity tables without write', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_backup'");
    if ($role === null) {
        $this->markTestSkipped('clinic_backup is not present on this cluster');
    }

    $select = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'users', 'SELECT') AS allowed");
    $insert = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'users', 'INSERT') AS allowed");
    $otp = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'otp_requests', 'SELECT') AS allowed");

    expect((bool) $select->allowed)->toBeTrue()
        ->and((bool) $insert->allowed)->toBeFalse()
        ->and((bool) $otp->allowed)->toBeTrue();
});

it('verifies the audit hash chain after an identity write', function () {
    $user = User::factory()->create();
    $userId = Identifier::fromTrusted((string) $user->id);
    app(TransactionRunner::class)->run(
        function (TransactionContext $tx) use ($userId): void {
            app(AppendAuditEvent::class)->append(
                $tx,
                'test.chain',
                'user',
                $userId,
                ['reason_code' => 'test'],
                $userId,
                'user',
            );
        },
    );

    $result = app(VerifyAuditChain::class)->verify();
    expect($result['ok'])->toBeTrue()
        ->and($result['checked'])->toBeGreaterThan(0);
});
