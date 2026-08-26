<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('lets the app insert audit rows but not update them', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_app'");
    if ($role === null) {
        $this->markTestSkipped('clinic_app is not present on this cluster');
    }

    $insert = DB::selectOne("SELECT has_table_privilege('clinic_app', 'audit_events', 'INSERT') AS allowed");
    $update = DB::selectOne("SELECT has_table_privilege('clinic_app', 'audit_events', 'UPDATE') AS allowed");
    $delete = DB::selectOne("SELECT has_table_privilege('clinic_app', 'audit_events', 'DELETE') AS allowed");

    expect((bool) $insert->allowed)->toBeTrue()
        ->and((bool) $update->allowed)->toBeFalse()
        ->and((bool) $delete->allowed)->toBeFalse();
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
