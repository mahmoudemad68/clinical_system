<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 03: Verification cases, document metadata, and decisions.
 *
 * Document bytes and object keys stay outside this module. object_id is an
 * opaque UUIDv7 reference, never a storage key. Decisions are append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('applicant_type', 32);
            $table->uuid('applicant_id');
            $table->string('case_type', 64);
            $table->string('status', 32);
            $table->timestampTz('submitted_at', 6)->nullable();
            $table->uuid('assigned_reviewer_id')->nullable();
            $table->timestampTz('decided_at', 6)->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_applicant_type_check
                CHECK (applicant_type IN ('doctor'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_case_type_check
                CHECK (case_type IN ('doctor_verification'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_status_check
                CHECK (status IN (
                    'draft',
                    'pending_review',
                    'changes_requested',
                    'approved',
                    'rejected'
                ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_submitted_status_check
                CHECK (
                    (status = 'draft' AND submitted_at IS NULL AND decided_at IS NULL)
                    OR (status = 'pending_review' AND submitted_at IS NOT NULL AND decided_at IS NULL)
                    OR (status IN ('approved', 'rejected', 'changes_requested') AND submitted_at IS NOT NULL AND decided_at IS NOT NULL)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_reviewer_fk
                FOREIGN KEY (assigned_reviewer_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX verification_cases_queue_index
                ON verification_cases (case_type, status, submitted_at)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX verification_cases_applicant_history_index
                ON verification_cases (applicant_type, applicant_id, created_at)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_cases_open_applicant_unique
                ON verification_cases (applicant_type, applicant_id, case_type)
                WHERE status IN ('draft', 'pending_review')
        SQL);

        Schema::create('verification_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->string('requirement_code', 64);
            $table->uuid('object_id');
            $table->char('sha256', 64);
            $table->string('detected_mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('scan_status', 32);
            $table->string('status', 32);
            $table->timestampTz('uploaded_at', 6);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_case_fk
                FOREIGN KEY (case_id) REFERENCES verification_cases (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_requirement_code_check
                CHECK (requirement_code ~ '^[a-z0-9_]+$' AND char_length(requirement_code) BETWEEN 1 AND 64)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_sha256_format_check
                CHECK (sha256 ~ '^[a-f0-9]{64}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_size_positive_check
                CHECK (size_bytes > 0 AND size_bytes <= 20971520)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_scan_status_check
                CHECK (scan_status IN ('pending', 'clean', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_status_check
                CHECK (status IN ('quarantined', 'available', 'rejected', 'retired'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_documents
                ADD CONSTRAINT verification_documents_available_requires_clean_check
                CHECK (status <> 'available' OR scan_status = 'clean')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_documents_object_id_unique
                ON verification_documents (object_id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX verification_documents_case_requirement_index
                ON verification_documents (case_id, requirement_code)
        SQL);

        Schema::create('verification_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->string('decision', 32);
            $table->string('reason_code', 64);
            $table->uuid('reviewer_id');
            $table->string('reviewer_assurance_level', 32);
            $table->binary('notes_ciphertext')->nullable();
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE verification_decisions
                ADD CONSTRAINT verification_decisions_case_fk
                FOREIGN KEY (case_id) REFERENCES verification_cases (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_decisions
                ADD CONSTRAINT verification_decisions_reviewer_fk
                FOREIGN KEY (reviewer_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_decisions
                ADD CONSTRAINT verification_decisions_decision_check
                CHECK (decision IN ('approved', 'rejected', 'changes_requested'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_decisions
                ADD CONSTRAINT verification_decisions_reason_code_check
                CHECK (reason_code ~ '^[a-z0-9_]+$' AND char_length(reason_code) BETWEEN 1 AND 64)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_decisions
                ADD CONSTRAINT verification_decisions_assurance_check
                CHECK (reviewer_assurance_level IN (
                    'aal1_password',
                    'aal2_totp',
                    'aal2_recovery_code',
                    'aal2_otp_phone',
                    'ial1_self_asserted',
                    'ial2_proof_pending',
                    'ial2_verified_link',
                    'ial3_operator'
                ))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX verification_decisions_case_id_unique
                ON verification_decisions (case_id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinic_verification_decisions_deny_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'verification_decisions is append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER verification_decisions_no_update_delete
            BEFORE UPDATE OR DELETE ON verification_decisions
            FOR EACH ROW
            EXECUTE FUNCTION clinic_verification_decisions_deny_mutation()
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS verification_decisions_no_update_delete ON verification_decisions');
        DB::statement('DROP FUNCTION IF EXISTS clinic_verification_decisions_deny_mutation()');
        Schema::dropIfExists('verification_decisions');
        Schema::dropIfExists('verification_documents');
        Schema::dropIfExists('verification_cases');
    }

    private function grantLeastPrivilege(): void
    {
        foreach (['verification_cases', 'verification_documents', 'verification_decisions'] as $table) {
            DB::statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            $this->revokeIfRole('clinic_reporter', 'ALL', $table);
            $this->revokeIfRole('clinic_worker', 'ALL', $table);
            $this->grantIfRole('clinic_backup', 'SELECT', $table);
        }

        $this->revokeIfRole('clinic_app', 'ALL', 'verification_cases');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', 'verification_cases');
        $this->revokeIfRole('clinic_app', 'ALL', 'verification_documents');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', 'verification_documents');
        $this->revokeIfRole('clinic_app', 'ALL', 'verification_decisions');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT', 'verification_decisions');
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
