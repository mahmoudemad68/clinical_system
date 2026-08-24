<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency records (phase file, "Idempotency contract").
 *
 * The unique primary key on key_hash is the concurrency control. Two concurrent
 * requests with the same scoped key both attempt the insert; exactly one wins,
 * and the loser reads the winner's record instead of starting a second
 * transition. Application-level checking cannot provide that, which is why the
 * phase file makes database constraints the final concurrency defence.
 *
 * Deliberately absent: a response body column. The store holds a reference, so
 * replaying a result does not copy clinical or financial content into a table
 * with a different retention and access profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            // SHA-256 of (operation, actor, tenant, client key). Hashed so the
            // table discloses neither the client's key nor the actor identity.
            $table->char('key_hash', 64)->primary();

            $table->string('operation_id', 100);

            // Canonical hash of the request. Same key + different hash is a
            // 409 IDEMPOTENCY_KEY_REUSED, never a silent replay.
            $table->char('request_hash', 64);

            $table->string('state', 20);
            $table->unsignedSmallInteger('status_code')->nullable();

            // A pointer, not a payload.
            $table->string('response_reference', 255)->nullable();

            // Stable label such as "dependency_timeout". Never a provider message.
            $table->string('safe_error_class', 64)->nullable();

            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            // Retention must exceed the maximum client retry and offline window
            // for the operation, or a legitimate offline retry creates a second
            // effect. The doctor desktop local outbox makes this concrete.
            $table->timestampTz('expires_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys
                ADD CONSTRAINT idempotency_keys_state_check
                CHECK (state IN ('PROCESSING', 'SUCCEEDED', 'FAILED_RETRYABLE'))
        SQL);

        // A succeeded record must carry its outcome, or a replay would return
        // an empty success, which is worse than no record at all.
        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys
                ADD CONSTRAINT idempotency_keys_succeeded_has_outcome_check
                CHECK (
                    state <> 'SUCCEEDED'
                    OR (status_code IS NOT NULL AND response_reference IS NOT NULL)
                )
        SQL);

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index('expires_at', 'idempotency_keys_expiry_idx');
            $table->index(['operation_id', 'created_at'], 'idempotency_keys_operation_idx');
        });

        // Find records stuck in PROCESSING: a crashed request leaves one, and a
        // concurrent duplicate would otherwise wait forever on a dead claim.
        DB::statement(<<<'SQL'
            CREATE INDEX idempotency_keys_stuck_processing_idx
                ON idempotency_keys (updated_at)
                WHERE state = 'PROCESSING'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
