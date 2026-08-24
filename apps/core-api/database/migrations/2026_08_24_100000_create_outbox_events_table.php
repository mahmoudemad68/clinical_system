<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (ADR 0004).
 *
 * Every column here exists to answer an operational question:
 *   - can a worker claim work without colliding with another worker?
 *   - can an operator see what is stuck, why, and for how long?
 *   - can a specific event or range be replayed without duplicating effects?
 *
 * Partitioning is deliberately NOT applied. The phase file forbids premature
 * partitioning; this is a candidate table, revisited with measured volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            // event_id is the consumer idempotency key, so it is the primary
            // key rather than a surrogate: two rows for one event_id would
            // break the exactly-once-in-effect guarantee consumers rely on.
            $table->uuid('event_id')->primary();

            $table->string('event_type', 100);
            $table->unsignedSmallInteger('schema_version');
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');

            // Assigned inside the originating transaction. Distinct from
            // created_at (row insert) and processed_at (publication).
            $table->timestampTz('occurred_at', 6);

            $table->uuid('actor_id')->nullable();
            $table->uuid('correlation_id');
            $table->uuid('causation_id')->nullable();

            $table->string('classification', 16)->default('internal');

            // jsonb, not json: we want the binary form for operator queries and
            // the option of a GIN index later without a rewrite.
            $table->jsonb('payload');

            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);

            // Backoff target. A claim only considers rows whose time has come.
            $table->timestampTz('available_at', 6);

            // Claim lease. A worker that dies leaves these set; another worker
            // recovers the row once the lease expires.
            $table->timestampTz('claimed_at', 6)->nullable();
            $table->string('claimed_by', 64)->nullable();
            $table->timestampTz('lease_expires_at', 6)->nullable();

            $table->timestampTz('processed_at', 6)->nullable();

            // A stable, non-sensitive label such as "dependency_timeout".
            // Never a provider message: those carry payload fragments.
            $table->string('last_error_class', 64)->nullable();

            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE outbox_events
                ADD CONSTRAINT outbox_events_status_check
                CHECK (status IN ('PENDING', 'CLAIMED', 'PROCESSED', 'FAILED', 'DEAD_LETTER'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE outbox_events
                ADD CONSTRAINT outbox_events_classification_check
                CHECK (classification IN ('public', 'internal', 'personal', 'sensitive'))
        SQL);

        // Credentials never travel in an event. Enforced in the database as
        // well as in the validator, because a schema check only guards the
        // schemas we remembered to write.
        DB::statement(<<<'SQL'
            ALTER TABLE outbox_events
                ADD CONSTRAINT outbox_events_processed_consistency_check
                CHECK (
                    (status = 'PROCESSED' AND processed_at IS NOT NULL)
                    OR (status <> 'PROCESSED' AND processed_at IS NULL)
                )
        SQL);

        // The claim index. A worker selects PENDING/FAILED rows whose
        // available_at has passed, ordered by availability, with
        // FOR UPDATE SKIP LOCKED. Partial, because PROCESSED rows dominate the
        // table within hours and must not bloat the hot index.
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_events_claimable_idx
                ON outbox_events (available_at, event_id)
                WHERE status IN ('PENDING', 'FAILED')
        SQL);

        // Operator visibility: what is stuck and needs a human.
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_events_dead_letter_idx
                ON outbox_events (created_at)
                WHERE status = 'DEAD_LETTER'
        SQL);

        // Lease recovery: find rows whose claiming worker died.
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_events_expired_lease_idx
                ON outbox_events (lease_expires_at)
                WHERE status = 'CLAIMED'
        SQL);

        // Retention sweep, and replay of an explicit range.
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->index(['status', 'processed_at'], 'outbox_events_retention_idx');
            $table->index('correlation_id', 'outbox_events_correlation_idx');
            $table->index(['aggregate_type', 'aggregate_id', 'occurred_at'], 'outbox_events_aggregate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
