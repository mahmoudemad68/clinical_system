<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 01: patient demographic profiles and append-only revisions.
 *
 * Height/weight CHECKs encode ENGINEERING_DEFAULT bounds from config/patients.php,
 * not a clinical protocol. Unique HMAC is the concurrent uniqueness control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->binary('national_id_ciphertext');
            $table->binary('national_id_lookup_hmac');
            $table->unsignedSmallInteger('national_id_key_version');
            $table->binary('full_name_ciphertext');
            $table->string('gender', 16);
            $table->date('date_of_birth')->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->string('marital_status', 32)->nullable();
            $table->string('blood_type', 8)->nullable();
            $table->string('status', 16);
            $table->string('created_by_type', 32);
            $table->uuid('created_by_id');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_status_check
                CHECK (status IN ('active', 'disputed', 'merged', 'restricted', 'archived'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_gender_check
                CHECK (gender IN ('male', 'female'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_blood_type_check
                CHECK (
                    blood_type IS NULL
                    OR blood_type IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-')
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_marital_status_check
                CHECK (
                    marital_status IS NULL
                    OR marital_status IN ('single', 'married', 'divorced', 'widowed')
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_created_by_type_check
                CHECK (created_by_type IN ('user', 'staff', 'system'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_height_engineering_default_check
                CHECK (
                    height_cm IS NULL
                    OR (height_cm > 0 AND height_cm >= 30 AND height_cm <= 300)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_weight_engineering_default_check
                CHECK (
                    weight_kg IS NULL
                    OR (weight_kg > 0 AND weight_kg >= 1 AND weight_kg <= 700)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_profiles
                ADD CONSTRAINT patient_profiles_dob_engineering_default_check
                CHECK (
                    date_of_birth IS NULL
                    OR date_of_birth >= DATE '1850-01-01'
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX patient_profiles_authoritative_hmac_unique
                ON patient_profiles (national_id_lookup_hmac)
                WHERE status <> 'merged'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX patient_profiles_user_id_unique
                ON patient_profiles (user_id)
                WHERE user_id IS NOT NULL
        SQL);

        Schema::create('patient_demographic_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_profile_id');
            $table->string('field_name', 64);
            $table->binary('old_protected')->nullable();
            $table->binary('new_protected')->nullable();
            $table->string('old_plain', 64)->nullable();
            $table->string('new_plain', 64)->nullable();
            $table->string('actor_type', 32);
            $table->uuid('actor_id');
            $table->string('reason_code', 64);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('profile_version');
            $table->uuid('request_id');
            $table->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE patient_demographic_revisions
                ADD CONSTRAINT patient_demographic_revisions_profile_fk
                FOREIGN KEY (patient_profile_id) REFERENCES patient_profiles (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE patient_demographic_revisions
                ADD CONSTRAINT patient_demographic_revisions_field_check
                CHECK (field_name IN (
                    'full_name',
                    'gender',
                    'date_of_birth',
                    'height_cm',
                    'weight_kg',
                    'marital_status',
                    'blood_type'
                ))
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX patient_demographic_revisions_profile_created_index
                ON patient_demographic_revisions (patient_profile_id, created_at)
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinic_patient_revisions_deny_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'patient_demographic_revisions is append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER patient_demographic_revisions_no_update_delete
            BEFORE UPDATE OR DELETE ON patient_demographic_revisions
            FOR EACH ROW
            EXECUTE FUNCTION clinic_patient_revisions_deny_mutation()
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS patient_demographic_revisions_no_update_delete ON patient_demographic_revisions');
        DB::statement('DROP FUNCTION IF EXISTS clinic_patient_revisions_deny_mutation()');
        Schema::dropIfExists('patient_demographic_revisions');
        Schema::dropIfExists('patient_profiles');
    }

    private function grantLeastPrivilege(): void
    {
        foreach (['patient_profiles', 'patient_demographic_revisions'] as $table) {
            DB::statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            $this->revokeIfRole('clinic_reporter', 'ALL', $table);
            $this->revokeIfRole('clinic_worker', 'ALL', $table);
            $this->grantIfRole('clinic_backup', 'SELECT', $table);
        }

        $this->revokeIfRole('clinic_app', 'ALL', 'patient_profiles');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', 'patient_profiles');
        $this->revokeIfRole('clinic_app', 'ALL', 'patient_demographic_revisions');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT', 'patient_demographic_revisions');
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
