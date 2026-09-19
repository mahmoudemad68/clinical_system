<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps worker and reporter off verification tables', function () {
    foreach (['clinic_worker', 'clinic_reporter'] as $role) {
        $exists = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = '{$role}'");
        if ($exists === null) {
            $this->markTestSkipped($role.' is not present on this cluster');
        }

        foreach (['verification_cases', 'verification_documents', 'verification_decisions'] as $table) {
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

it('lets clinic_app mutate cases and documents but only insert decisions', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_app'");
    if ($role === null) {
        $this->markTestSkipped('clinic_app is not present on this cluster');
    }

    $caseUpdate = DB::selectOne("SELECT has_table_privilege('clinic_app', 'verification_cases', 'UPDATE') AS allowed");
    $docInsert = DB::selectOne("SELECT has_table_privilege('clinic_app', 'verification_documents', 'INSERT') AS allowed");
    $decisionInsert = DB::selectOne("SELECT has_table_privilege('clinic_app', 'verification_decisions', 'INSERT') AS allowed");
    $decisionUpdate = DB::selectOne("SELECT has_table_privilege('clinic_app', 'verification_decisions', 'UPDATE') AS allowed");
    $decisionDelete = DB::selectOne("SELECT has_table_privilege('clinic_app', 'verification_decisions', 'DELETE') AS allowed");

    expect((bool) $caseUpdate->allowed)->toBeTrue()
        ->and((bool) $docInsert->allowed)->toBeTrue()
        ->and((bool) $decisionInsert->allowed)->toBeTrue()
        ->and((bool) $decisionUpdate->allowed)->toBeFalse()
        ->and((bool) $decisionDelete->allowed)->toBeFalse();

    $backup = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_backup'");
    if ($backup === null) {
        return;
    }

    $backupSelect = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'verification_cases', 'SELECT') AS allowed");
    $backupInsert = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'verification_cases', 'INSERT') AS allowed");

    expect((bool) $backupSelect->allowed)->toBeTrue()
        ->and((bool) $backupInsert->allowed)->toBeFalse();
});
