<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Services\Persistence\AuditWriterConnectionLimit;

/**
 * Phase 02 chunk 17: Octane workers persist pgsql_audit when
 * DisconnectFromDatabases is disabled. A CONNECTION LIMIT of 10 is below the
 * canonical 16-worker Core API pool and fails writes with
 * "too many connections for role clinic_audit_writer".
 *
 * Role-level ALTER ROLE is a privileged infrastructure operation
 * (clinic_owner / platform:provision-postgres-roles /
 * infra/docker/postgres/raise-audit-writer-connection-limit.sql). This
 * application migration applies the limit only when the current identity is
 * allowed to, then always asserts. It never swallows insufficient_privilege:
 * a BASE database left at 10 cannot be recorded as migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(AuditWriterConnectionLimit::class)->ensureSatisfied(DB::getDefaultConnection());
    }

    public function down(): void
    {
        // Irreversible: lowering the cap reintroduces audit-writer exhaustion
        // under the canonical Octane worker pool. Restore via a forward
        // privileged provisioning change if a lower cap is ever required.
    }
};
