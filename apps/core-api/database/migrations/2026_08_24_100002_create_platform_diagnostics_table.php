<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Synthetic records for the Phase 00 round-trip slice.
 *
 * This table exists to prove the foundation works end to end: one bounded
 * transaction writes a row and an outbox row atomically, a worker publishes
 * after commit, and a forced duplicate delivery produces exactly one effect.
 *
 * It holds no personal or clinical data, ever. The label column is pattern
 * constrained at three layers (OpenAPI, form request, and the CHECK below), so
 * free-form user content cannot reach it even if a caller bypasses the API.
 *
 * Retention is short and the table is dropped when Phase 01 begins delivering
 * real slices; the diagnostics endpoint is flag-gated and disabled outside
 * local and development environments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_diagnostics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('label', 64);
            $table->unsignedSmallInteger('echo_delay_ms')->default(0);

            // The outbox row committed in the same transaction. Nullable only
            // because the foreign key is deferred: both rows are written in one
            // transaction, and a non-null value proves the pairing held.
            $table->uuid('outbox_event_id')->nullable();

            $table->uuid('correlation_id');
            $table->timestampTz('recorded_at', 6);

            // Set by the consumer. A second delivery must not change it, which
            // is what makes "exactly once in effect" observable in a test.
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->unsignedInteger('consumed_count')->default(0);
        });

        // Labels are machine-generated slugs identifying a test run, not prose.
        //
        // An earlier version of this constraint allowed spaces, which meant
        // 'patient complains of chest pain NID 29801011234567' satisfied it:
        // the pattern accepted arbitrary alphanumeric prose including a
        // national ID. Disallowing spaces removes sentences; the digit-run
        // guard below removes identifiers that would otherwise slip through as
        // a single token.
        //
        // Same rule as the OpenAPI pattern and the form request. Defence in
        // depth: this layer survives a caller that reaches the database by
        // another route.
        DB::statement(<<<'SQL'
            ALTER TABLE platform_diagnostics
                ADD CONSTRAINT platform_diagnostics_label_check
                CHECK (label ~ '^[a-z][a-z0-9_-]{0,63}$')
        SQL);

        // No run of 10 or more digits. An Egyptian national ID is 14 digits and
        // a mobile number is 11, so this rejects both even when embedded in an
        // otherwise well-formed slug such as 'run-29801011234567'.
        DB::statement(<<<'SQL'
            ALTER TABLE platform_diagnostics
                ADD CONSTRAINT platform_diagnostics_label_no_identifier_check
                CHECK (label !~ '[0-9]{10}')
        SQL);

        // A consumed record must record when. Catches a consumer that
        // increments the counter without setting the timestamp.
        DB::statement(<<<'SQL'
            ALTER TABLE platform_diagnostics
                ADD CONSTRAINT platform_diagnostics_consumption_check
                CHECK (
                    (consumed_count = 0 AND consumed_at IS NULL)
                    OR (consumed_count > 0 AND consumed_at IS NOT NULL)
                )
        SQL);

        Schema::table('platform_diagnostics', function (Blueprint $table): void {
            $table->index('correlation_id', 'platform_diagnostics_correlation_idx');
            $table->index('recorded_at', 'platform_diagnostics_retention_idx');

            $table->foreign('outbox_event_id')
                ->references('event_id')
                ->on('outbox_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_diagnostics');
    }
};
