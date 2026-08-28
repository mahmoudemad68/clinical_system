<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * clinic_worker is the outbox consumer identity. The Phase 00 diagnostics
 * consumer updates platform_diagnostics; without this grant the worker role
 * can claim outbox_events and then fail on the only registered consumer.
 *
 * Serial jobs / failed_jobs inserts need sequence USAGE. Table DML was
 * already granted; sequence rights were not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_diagnostics')) {
            $this->grantIfRole('clinic_worker', 'SELECT, UPDATE', 'platform_diagnostics');
        }

        $this->grantSequenceIfRole('clinic_worker', 'jobs_id_seq');
        $this->grantSequenceIfRole('clinic_worker', 'failed_jobs_id_seq');
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_diagnostics')) {
            $this->revokeIfRole('clinic_worker', 'SELECT, UPDATE', 'platform_diagnostics');
        }

        $this->revokeSequenceIfRole('clinic_worker', 'jobs_id_seq');
        $this->revokeSequenceIfRole('clinic_worker', 'failed_jobs_id_seq');
    }

    private function grantIfRole(string $role, string $privileges, string $table): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'GRANT {$privileges} ON TABLE {$table} TO {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }

    private function revokeIfRole(string $role, string $privileges, string $table): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'REVOKE {$privileges} ON TABLE {$table} FROM {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }

    private function grantSequenceIfRole(string $role, string $sequence): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_class WHERE relname = '{$sequence}')
                   AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'GRANT USAGE, SELECT ON SEQUENCE {$sequence} TO {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }

    private function revokeSequenceIfRole(string $role, string $sequence): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_class WHERE relname = '{$sequence}')
                   AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'REVOKE USAGE, SELECT ON SEQUENCE {$sequence} FROM {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }
};
