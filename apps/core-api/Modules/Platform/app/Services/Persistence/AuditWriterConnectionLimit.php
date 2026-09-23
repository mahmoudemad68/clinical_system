<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Persistence;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * clinic_audit_writer CONNECTION LIMIT is a PostgreSQL role-level setting.
 *
 * clinic_migrator has CREATEROLE but cannot ALTER a role it did not create
 * (no ADMIN OPTION). Serving identities must never receive that privilege.
 * Fresh initdb applies the limit as clinic_owner; existing volumes require
 * the same privileged identity via platform:provision-postgres-roles.
 *
 * Application migrations may apply the limit when the current identity is
 * allowed to, then always assert. They never swallow insufficient_privilege.
 */
final class AuditWriterConnectionLimit
{
    public const ROLE = 'clinic_audit_writer';

    public const MINIMUM = 40;

    public const OWNER_CONNECTION = 'pgsql_owner';

    public function __construct(private readonly DatabaseManager $db) {}

    public function currentLimit(?string $connection = null): ?int
    {
        $this->assertPostgres($connection);

        $row = $this->db->connection($connection)->selectOne(
            'select rolconnlimit as n from pg_roles where rolname = ?',
            [self::ROLE],
        );

        if ($row === null) {
            return null;
        }

        return is_object($row) ? (int) $row->n : (int) ($row['n'] ?? 0);
    }

    public function isSatisfied(?string $connection = null): bool
    {
        $limit = $this->currentLimit($connection);

        return $limit !== null && $limit >= self::MINIMUM;
    }

    public function assertSatisfied(?string $connection = null): void
    {
        $limit = $this->currentLimit($connection);

        if ($limit !== null && $limit >= self::MINIMUM) {
            return;
        }

        throw new RuntimeException(
            self::ROLE.' CONNECTION LIMIT must be at least '.self::MINIMUM
            .'; observed '.($limit === null ? 'missing role' : (string) $limit).'.',
        );
    }

    public function privilegedConnectionName(?string $fallback = null): string
    {
        $owner = trim((string) config('database.connections.'.self::OWNER_CONNECTION.'.username', ''));
        if ($owner !== '') {
            return self::OWNER_CONNECTION;
        }

        return $fallback ?? (string) config('database.default');
    }

    /**
     * No-op when the role does not exist yet (fresh cluster before migrations).
     * Otherwise apply the privileged change if needed and fail closed.
     */
    public function provision(?string $fallbackConnection = null): void
    {
        if ($this->currentLimit($fallbackConnection) === null) {
            return;
        }

        $this->ensureSatisfied($fallbackConnection);
    }

    /**
     * Apply the role limit when it is below the floor, then assert.
     *
     * Already-correct clusters (fresh initdb) succeed without ALTER ROLE so
     * clinic_migrator can finish application migrations. A BASE cluster still
     * at 10 cannot report success unless a privileged identity applied 40.
     */
    public function ensureSatisfied(?string $fallbackConnection = null): void
    {
        if ($this->isSatisfied($fallbackConnection)) {
            return;
        }

        $this->apply(self::MINIMUM, $this->privilegedConnectionName($fallbackConnection));
        $this->assertSatisfied($fallbackConnection);
    }

    public function apply(int $limit, string $connection): void
    {
        $this->assertPostgres($connection);

        if ($limit < 1) {
            throw new RuntimeException('A PostgreSQL role connection limit must be a positive integer.');
        }

        try {
            $this->db->connection($connection)->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'The privileged PostgreSQL identity could not connect to apply '
                .self::ROLE.' CONNECTION LIMIT '.$limit.'.',
                0,
                $e,
            );
        }

        try {
            $this->db->connection($connection)->statement(
                'ALTER ROLE '.self::ROLE.' CONNECTION LIMIT '.$limit,
            );
        } catch (QueryException $e) {
            throw new RuntimeException(
                'Failed to set '.self::ROLE.' CONNECTION LIMIT to '.$limit
                .'. Privileged PostgreSQL owner provisioning is required.',
                0,
                $e,
            );
        }
    }

    private function assertPostgres(?string $connection): void
    {
        $name = $connection ?? (string) config('database.default');
        if ($this->db->connection($name)->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Audit writer connection limits are PostgreSQL role attributes.');
        }
    }
}
