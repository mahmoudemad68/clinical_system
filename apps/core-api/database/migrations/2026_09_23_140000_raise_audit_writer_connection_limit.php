<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 02 chunk 17: Octane workers persist pgsql_audit when
 * DisconnectFromDatabases is disabled. A CONNECTION LIMIT of 10 is below the
 * canonical 16-worker Core API pool and fails writes with
 * "too many connections for role clinic_audit_writer".
 *
 * Align with clinic_app. clinic_migrator may lack permission to ALTER a role
 * it did not create; initdb and the performance harness apply the same default
 * as clinic_owner. Swallow only insufficient_privilege / undefined_object.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setAuditWriterConnectionLimit(40);
    }

    public function down(): void
    {
        $this->setAuditWriterConnectionLimit(10);
    }

    private function setAuditWriterConnectionLimit(int $limit): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                BEGIN
                    EXECUTE 'ALTER ROLE clinic_audit_writer CONNECTION LIMIT {$limit}';
                EXCEPTION
                    WHEN insufficient_privilege THEN
                        NULL;
                    WHEN undefined_object THEN
                        NULL;
                END;
            END
            \$\$;
        SQL);
    }
};
