<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps worker and reporter off pharmacy tables', function () {
    foreach (['clinic_worker', 'clinic_reporter'] as $role) {
        $exists = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = '{$role}'");
        if ($exists === null) {
            $this->markTestSkipped($role.' is not present on this cluster');
        }

        foreach (['pharmacy_organizations', 'pharmacy_branches', 'pharmacy_memberships'] as $table) {
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

it('lets clinic_app mutate pharmacy tables and backup only select', function () {
    $role = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_app'");
    if ($role === null) {
        $this->markTestSkipped('clinic_app is not present on this cluster');
    }

    foreach (['pharmacy_organizations', 'pharmacy_branches', 'pharmacy_memberships'] as $table) {
        $update = DB::selectOne("SELECT has_table_privilege('clinic_app', '{$table}', 'UPDATE') AS allowed");
        $insert = DB::selectOne("SELECT has_table_privilege('clinic_app', '{$table}', 'INSERT') AS allowed");
        expect((bool) $update->allowed)->toBeTrue()
            ->and((bool) $insert->allowed)->toBeTrue();
    }

    $backup = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'clinic_backup'");
    if ($backup === null) {
        return;
    }

    $backupSelect = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'pharmacy_organizations', 'SELECT') AS allowed");
    $backupInsert = DB::selectOne("SELECT has_table_privilege('clinic_backup', 'pharmacy_organizations', 'INSERT') AS allowed");

    expect((bool) $backupSelect->allowed)->toBeTrue()
        ->and((bool) $backupInsert->allowed)->toBeFalse();
});
