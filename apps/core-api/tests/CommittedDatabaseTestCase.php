<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;

/**
 * Commits between statements so two OS processes can see the same rows.
 *
 * RefreshDatabase wraps each test in a transaction, which hides setup from a
 * second PostgreSQL connection. G-01-12 races need the opposite.
 */
abstract class CommittedDatabaseTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * @var list<string>
     */
    protected $exceptTables = [
        'audit_events',
        'migrations',
    ];
}
