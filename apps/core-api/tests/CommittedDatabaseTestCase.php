<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;

/**
 * Commits between statements so two OS processes can see the same rows.
 *
 * RefreshDatabase wraps each test in a transaction, which hides setup from a
 * second PostgreSQL connection. G-01-12 races need the opposite.
 *
 * Laravel's DatabaseTruncation trait empties tables in setUp only. A later
 * RefreshDatabase test then begins a transaction on whatever this process
 * committed. tearDown therefore truncates again so race helpers cannot leak
 * outbox, users, patient, or other durable rows into later suites.
 *
 * migrations stays excepted so the schema is not dropped. clinic_migrator can
 * TRUNCATE audit_events (DELETE is blocked by the append-only trigger).
 */
abstract class CommittedDatabaseTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * @var list<string>
     */
    protected $exceptTables = [
        'migrations',
    ];

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }
}
