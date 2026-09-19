<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 04: Verification-owned upload intents.
 *
 * storage_locator is classified infrastructure. It is never a public
 * identifier, never authorization, and must not appear in HTTP, events,
 * logs, or metrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_upload_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->uuid('created_by_user_id');
            $table->string('requirement_code', 64);
            $table->uuid('object_id');
            $table->string('storage_locator', 201);
            $table->string('state', 32);
            $table->unsignedBigInteger('expected_size_bytes');
            $table->string('declared_media_type', 128);
            $table->char('expected_sha256', 64)->nullable();
            $table->string('object_version', 128)->nullable();
            $table->unsignedBigInteger('observed_size_bytes')->nullable();
            $table->char('observed_sha256', 64)->nullable();
            $table->string('detected_mime', 128)->nullable();
            $table->string('scanner_identity', 64)->nullable();
            $table->string('scanner_version', 64)->nullable();
            $table->string('rejection_reason', 64)->nullable();
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('available_at', 6)->nullable();
            $table->timestampTz('cleanup_eligible_at', 6)->nullable();
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_case_fk
                FOREIGN KEY (case_id) REFERENCES verification_cases (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_requirement_code_check
                CHECK (requirement_code ~ '^[a-z0-9_]+$' AND char_length(requirement_code) BETWEEN 1 AND 64)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_state_check
                CHECK (state IN (
                    'requested',
                    'uploading',
                    'quarantined',
                    'validating',
                    'scanning',
                    'available',
                    'rejected'
                ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_size_positive_check
                CHECK (
                    expected_size_bytes > 0 AND expected_size_bytes <= 20971520
                    AND (observed_size_bytes IS NULL OR (observed_size_bytes > 0 AND observed_size_bytes <= 20971520))
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_sha256_format_check
                CHECK (
                    (expected_sha256 IS NULL OR expected_sha256 ~ '^[a-f0-9]{64}$')
                    AND (observed_sha256 IS NULL OR observed_sha256 ~ '^[a-f0-9]{64}$')
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_locator_check
                CHECK (
                    storage_locator ~ '^[a-z][a-z0-9_./-]{1,200}$'
                    AND position('..' in storage_locator) = 0
                    AND position('//' in storage_locator) = 0
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_timestamps_check
                CHECK (
                    expires_at > created_at
                    AND (completed_at IS NULL OR completed_at >= created_at)
                    AND (available_at IS NULL OR (completed_at IS NOT NULL AND available_at >= completed_at))
                    AND version >= 1
                    AND processing_attempts <= 64
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_state_consistency_check
                CHECK (
                    (
                        state IN ('requested', 'uploading')
                        AND available_at IS NULL
                        AND rejection_reason IS NULL
                    )
                    OR (
                        state IN ('quarantined', 'validating', 'scanning')
                        AND completed_at IS NOT NULL
                        AND available_at IS NULL
                        AND rejection_reason IS NULL
                    )
                    OR (
                        state = 'available'
                        AND observed_sha256 IS NOT NULL
                        AND observed_size_bytes IS NOT NULL
                        AND detected_mime IS NOT NULL
                        AND available_at IS NOT NULL
                        AND completed_at IS NOT NULL
                        AND rejection_reason IS NULL
                    )
                    OR (
                        state = 'rejected'
                        AND rejection_reason IS NOT NULL
                        AND available_at IS NULL
                    )
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_upload_intents
                ADD CONSTRAINT verification_upload_intents_rejection_reason_check
                CHECK (
                    rejection_reason IS NULL
                    OR rejection_reason IN (
                        'expired',
                        'object_missing',
                        'zero_byte',
                        'oversized',
                        'mime_mismatch',
                        'unsupported_format',
                        'malformed',
                        'malware_detected',
                        'toctou_mismatch',
                        'case_not_draft',
                        'processing_failed'
                    )
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_upload_intents_object_id_unique
                ON verification_upload_intents (object_id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_upload_intents_storage_locator_unique
                ON verification_upload_intents (storage_locator)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX verification_upload_intents_case_requirement_index
                ON verification_upload_intents (case_id, requirement_code)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX verification_upload_intents_expiry_index
                ON verification_upload_intents (expires_at)
                WHERE state IN ('requested', 'uploading', 'quarantined', 'validating', 'scanning')
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinic_verification_upload_intents_protect()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.case_id IS DISTINCT FROM OLD.case_id
                        OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                        OR NEW.object_id IS DISTINCT FROM OLD.object_id
                        OR NEW.storage_locator IS DISTINCT FROM OLD.storage_locator
                    THEN
                        RAISE EXCEPTION 'verification_upload_intents identity is immutable';
                    END IF;

                    IF OLD.state = 'available' AND NEW.state IS DISTINCT FROM 'available' THEN
                        RAISE EXCEPTION 'verification_upload_intents available state is terminal';
                    END IF;

                    IF OLD.state = 'rejected' AND NEW.state = 'available' THEN
                        RAISE EXCEPTION 'verification_upload_intents rejected state cannot become available';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER verification_upload_intents_protect
            BEFORE UPDATE ON verification_upload_intents
            FOR EACH ROW
            EXECUTE FUNCTION clinic_verification_upload_intents_protect()
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD COLUMN upload_intent_id uuid NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_upload_intent_fk
                FOREIGN KEY (upload_intent_id) REFERENCES verification_upload_intents (id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_documents_upload_intent_id_unique
                ON verification_documents (upload_intent_id)
                WHERE upload_intent_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinic_verification_documents_protect()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                case_status text;
                parent_id uuid;
            BEGIN
                parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.case_id ELSE NEW.case_id END;

                SELECT status INTO case_status
                FROM verification_cases
                WHERE id = parent_id
                FOR SHARE;

                IF TG_OP = 'INSERT' THEN
                    IF case_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'verification_documents is frozen after submission';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.case_id IS DISTINCT FROM OLD.case_id
                        OR NEW.requirement_code IS DISTINCT FROM OLD.requirement_code
                        OR NEW.object_id IS DISTINCT FROM OLD.object_id
                        OR NEW.sha256 IS DISTINCT FROM OLD.sha256
                        OR NEW.detected_mime IS DISTINCT FROM OLD.detected_mime
                        OR NEW.size_bytes IS DISTINCT FROM OLD.size_bytes
                        OR NEW.uploaded_at IS DISTINCT FROM OLD.uploaded_at
                        OR NEW.upload_intent_id IS DISTINCT FROM OLD.upload_intent_id
                    THEN
                        RAISE EXCEPTION 'verification_documents content identity is immutable';
                    END IF;

                    IF case_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'verification_documents is frozen after submission';
                    END IF;

                    RETURN NEW;
                END IF;

                IF case_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'verification_documents is frozen after submission';
                END IF;

                RETURN OLD;
            END;
            $$
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS verification_documents_upload_intent_id_unique');
        DB::statement('ALTER TABLE verification_documents DROP CONSTRAINT IF EXISTS verification_documents_upload_intent_fk');
        DB::statement('ALTER TABLE verification_documents DROP COLUMN IF EXISTS upload_intent_id');
        DB::statement('DROP TRIGGER IF EXISTS verification_upload_intents_protect ON verification_upload_intents');
        DB::statement('DROP FUNCTION IF EXISTS clinic_verification_upload_intents_protect()');
        Schema::dropIfExists('verification_upload_intents');
    }

    private function grantLeastPrivilege(): void
    {
        DB::statement('REVOKE ALL ON TABLE verification_upload_intents FROM PUBLIC');
        $this->revokeIfRole('clinic_reporter', 'ALL', 'verification_upload_intents');
        $this->revokeIfRole('clinic_worker', 'ALL', 'verification_upload_intents');
        $this->grantIfRole('clinic_backup', 'SELECT', 'verification_upload_intents');
        $this->revokeIfRole('clinic_app', 'ALL', 'verification_upload_intents');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', 'verification_upload_intents');
        $this->grantIfRole('clinic_worker', 'SELECT', 'verification_cases');
        $this->grantIfRole('clinic_worker', 'SELECT, INSERT', 'verification_documents');
        $this->grantIfRole('clinic_worker', 'SELECT, UPDATE, DELETE', 'verification_upload_intents');
    }

    private function grantIfRole(string $role, string $privileges, string $table): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'GRANT {$privileges} ON TABLE {$table} TO {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }

    private function revokeIfRole(string $role, string $privileges, string $table): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'REVOKE {$privileges} ON TABLE {$table} FROM {$role}';
                END IF;
            END
            \$\$;
        SQL);
    }
};
