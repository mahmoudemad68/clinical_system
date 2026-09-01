<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps worker and reporter off patient tables', function () {
    foreach (['clinic_worker', 'clinic_reporter'] as $role) {
        $exists = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = '{$role}'");
        if ($exists === null) {
            $this->markTestSkipped($role.' is not present on this cluster');
        }

        foreach (['patient_profiles', 'patient_demographic_revisions'] as $table) {
            $select = DB::selectOne("SELECT has_table_privilege('{$role}', '{$table}', 'SELECT') AS allowed");
            $insert = DB::selectOne("SELECT has_table_privilege('{$role}', '{$table}', 'INSERT') AS allowed");
            $update = DB::selectOne("SELECT has_table_privilege('{$role}', '{$table}', 'UPDATE') AS allowed");
            $delete = DB::selectOne("SELECT has_table_privilege('{$role}', '{$table}', 'DELETE') AS allowed");

            expect((bool) $select->allowed)->toBeFalse()
                ->and((bool) $insert->allowed)->toBeFalse()
                ->and((bool) $update->allowed)->toBeFalse()
                ->and((bool) $delete->allowed)->toBeFalse();
        }
    }
});

it('lets clinic_app mutate profiles but only insert revisions', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_app'");
    if ($role === null) {
        $this->markTestSkipped('clinic_app is not present on this cluster');
    }

    $profileUpdate = DB::selectOne("SELECT has_table_privilege('clinic_app', 'patient_profiles', 'UPDATE') AS allowed");
    $revisionInsert = DB::selectOne("SELECT has_table_privilege('clinic_app', 'patient_demographic_revisions', 'INSERT') AS allowed");
    $revisionUpdate = DB::selectOne("SELECT has_table_privilege('clinic_app', 'patient_demographic_revisions', 'UPDATE') AS allowed");
    $revisionDelete = DB::selectOne("SELECT has_table_privilege('clinic_app', 'patient_demographic_revisions', 'DELETE') AS allowed");
    expect((bool) $profileUpdate->allowed)->toBeTrue()
        ->and((bool) $revisionInsert->allowed)->toBeTrue()
        ->and((bool) $revisionUpdate->allowed)->toBeFalse()
        ->and((bool) $revisionDelete->allowed)->toBeFalse();

    $backup = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_backup'");
    if ($backup === null) {
        return;
    }

    $backupSelect = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'patient_profiles', 'SELECT') AS allowed");
    $backupInsert = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'patient_profiles', 'INSERT') AS allowed");

    expect((bool) $backupSelect->allowed)->toBeTrue()
        ->and((bool) $backupInsert->allowed)->toBeFalse();
});
