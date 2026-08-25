<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Health;

use App\Modules\Platform\Application\Health\CheckStatus;
use App\Modules\Platform\Application\Health\DependencyCheck;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * PostgreSQL is reachable with the serving credentials.
 *
 * SELECT 1 rather than a table read: this proves the connection and the
 * credentials without depending on any migration having run and without taking
 * a lock on anything.
 */
final class DatabaseCheck implements DependencyCheck
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function name(): string
    {
        return 'postgresql';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function run(): CheckStatus
    {
        try {
            $this->connection->select('SELECT 1');

            return CheckStatus::Pass;
        } catch (Throwable) {
            // The exception is swallowed deliberately. A readiness body is
            // reachable by anything that can reach the port, and a database
            // error message names hosts, databases, and sometimes credentials.
            return CheckStatus::Fail;
        }
    }
}
