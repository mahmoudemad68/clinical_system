<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profile Claim Policy v1 ceremony persistence (PC-002/010/015).
 *
 * Credentials are peppered-hash-only. Production enablement remains hard-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_claim_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->binary('credential_lookup_hmac');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('issued_at', 6);
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE patient_claim_credentials
                ADD CONSTRAINT patient_claim_credentials_patient_fk
                FOREIGN KEY (patient_id) REFERENCES patient_profiles (id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX patient_claim_credentials_hmac_unique
                ON patient_claim_credentials (credential_lookup_hmac)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX patient_claim_credentials_one_open
                ON patient_claim_credentials (patient_id)
                WHERE consumed_at IS NULL
        SQL);

        Schema::create('patient_claim_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->binary('national_id_lookup_hmac');
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE patient_claim_failures
                ADD CONSTRAINT patient_claim_failures_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX patient_claim_failures_account_hmac_created
                ON patient_claim_failures (user_id, national_id_lookup_hmac, created_at DESC)
        SQL);

        Schema::create('patient_claim_locks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->binary('national_id_lookup_hmac');
            $table->timestampTz('cooldown_until', 6)->nullable();
            $table->timestampTz('locked_at', 6)->nullable();
            $table->timestampTz('updated_at', 6);
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE patient_claim_locks
                ADD CONSTRAINT patient_claim_locks_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX patient_claim_locks_account_hmac_unique
                ON patient_claim_locks (user_id, national_id_lookup_hmac)
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_claim_locks');
        Schema::dropIfExists('patient_claim_failures');
        Schema::dropIfExists('patient_claim_credentials');
    }

    private function grantLeastPrivilege(): void
    {
        foreach (['patient_claim_credentials', 'patient_claim_failures', 'patient_claim_locks'] as $table) {
            DB::statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            $this->revokeIfRole('clinic_reporter', 'ALL', $table);
            $this->revokeIfRole('clinic_worker', 'ALL', $table);
            $this->grantIfRole('clinic_backup', 'SELECT', $table);
            $this->revokeIfRole('clinic_app', 'ALL', $table);
            $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', $table);
        }
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
