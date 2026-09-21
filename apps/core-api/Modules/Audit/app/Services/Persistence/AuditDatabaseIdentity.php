<?php

declare(strict_types=1);

namespace Modules\Audit\Services\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Fail-closed guard for the dedicated pgsql_audit connection.
 *
 * Business and outbox work stay on clinic_app / clinic_worker. Audit append
 * must use clinic_audit_writer on pgsql_audit and then the SECURITY DEFINER
 * clinic_append_audit_event() function. A generic DB_URL must never silently
 * reuse clinic_worker or clinic_app for that path.
 */
final class AuditDatabaseIdentity
{
    public const CONNECTION = 'pgsql_audit';

    public const ROLE = 'clinic_audit_writer';

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function connection(): ConnectionInterface
    {
        $this->assertWriterRole();

        return $this->db->connection(self::CONNECTION);
    }

    public function assertWriterRole(): string
    {
        $role = $this->verifiedCurrentRole();

        if ($role !== self::ROLE) {
            throw new RuntimeException(
                'Audit connection current_user must be '.self::ROLE.'.',
            );
        }

        return $role;
    }

    public function currentRole(): string
    {
        return $this->verifiedCurrentRole();
    }

    private function verifiedCurrentRole(): string
    {
        try {
            $row = $this->db->connection(self::CONNECTION)->selectOne('select current_user as username');
        } catch (Throwable) {
            throw new RuntimeException('Audit connection identity could not be verified.');
        }

        return $this->usernameFromRow($row);
    }

    private function usernameFromRow(mixed $row): string
    {
        if (is_object($row) && isset($row->username) && is_string($row->username)) {
            return $row->username;
        }

        if (is_array($row) && isset($row['username']) && is_string($row['username'])) {
            return $row['username'];
        }

        throw new RuntimeException('PostgreSQL current_user could not be read.');
    }
}
