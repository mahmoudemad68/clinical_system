<?php

declare(strict_types=1);

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Services\Persistence\AuditWriterConnectionLimit;
use RuntimeException;

/**
 * Privileged PostgreSQL role-level provisioning.
 *
 * Run as an operator job that can use DB_OWNER_USERNAME (clinic_owner) or,
 * on clusters where clinic_migrator is the image superuser, the migrator
 * connection. HTTP and queue workers never select pgsql_owner.
 *
 * Canonical upgrade:
 *   php artisan platform:provision-postgres-roles
 *   php artisan migrate --database=pgsql_migrator --force
 *
 * Operators without PHP may apply infra/docker/postgres/raise-audit-writer-connection-limit.sql
 * as clinic_owner, then run application migrations. Either path must fail
 * closed if clinic_audit_writer.rolconnlimit stays below 40.
 */
final class ProvisionPostgresRolesCommand extends Command
{
    protected $signature = 'platform:provision-postgres-roles';

    protected $description = 'Apply privileged PostgreSQL role settings that clinic_migrator cannot own';

    public function handle(AuditWriterConnectionLimit $limit): int
    {
        try {
            $limit->provision('pgsql_migrator');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(AuditWriterConnectionLimit::ROLE.' CONNECTION LIMIT is at least '.AuditWriterConnectionLimit::MINIMUM);

        return self::SUCCESS;
    }
}
