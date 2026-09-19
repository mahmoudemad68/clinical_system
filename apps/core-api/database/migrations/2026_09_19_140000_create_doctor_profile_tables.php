<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 02: doctor profiles and specialties.
 *
 * Specialties are an empty catalog until an approved reference dataset exists.
 * Unique HMAC and unique user_id are the concurrent uniqueness controls.
 * Verification documents remain owned by Verification, not Doctors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('label_ar', 200);
            $table->string('label_en', 200);
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE specialties
                ADD CONSTRAINT specialties_code_unique UNIQUE (code)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE specialties
                ADD CONSTRAINT specialties_code_format_check
                CHECK (code ~ '^[a-z0-9_]+$')
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX specialties_active_sort_index
                ON specialties (active, sort_order, code)
        SQL);

        Schema::create('doctor_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->binary('national_id_ciphertext');
            $table->binary('national_id_lookup_hmac');
            $table->unsignedSmallInteger('national_id_key_version');
            $table->binary('syndicate_number_ciphertext')->nullable();
            $table->binary('syndicate_number_lookup_hmac')->nullable();
            $table->unsignedSmallInteger('syndicate_number_key_version')->nullable();
            $table->uuid('specialty_id');
            $table->string('professional_display_name', 200);
            $table->string('verification_status', 32);
            $table->string('public_status', 16);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('approved_at', 6)->nullable();
            $table->timestampTz('suspended_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_specialty_fk
                FOREIGN KEY (specialty_id) REFERENCES specialties (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_verification_status_check
                CHECK (verification_status IN (
                    'draft',
                    'pending_review',
                    'changes_requested',
                    'approved',
                    'rejected',
                    'suspended'
                ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_public_status_check
                CHECK (public_status IN ('hidden', 'listed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_listed_requires_approved_check
                CHECK (public_status <> 'listed' OR verification_status = 'approved')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_display_name_length_check
                CHECK (char_length(professional_display_name) BETWEEN 1 AND 200)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_syndicate_protected_pair_check
                CHECK (
                    (
                        syndicate_number_ciphertext IS NULL
                        AND syndicate_number_lookup_hmac IS NULL
                        AND syndicate_number_key_version IS NULL
                    )
                    OR (
                        syndicate_number_ciphertext IS NOT NULL
                        AND syndicate_number_lookup_hmac IS NOT NULL
                        AND syndicate_number_key_version IS NOT NULL
                    )
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_profiles_user_id_unique
                ON doctor_profiles (user_id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_profiles_national_id_hmac_unique
                ON doctor_profiles (national_id_lookup_hmac)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_profiles_syndicate_hmac_unique
                ON doctor_profiles (syndicate_number_lookup_hmac)
                WHERE syndicate_number_lookup_hmac IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX doctor_profiles_specialty_id_index
                ON doctor_profiles (specialty_id)
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_profiles');
        Schema::dropIfExists('specialties');
    }

    private function grantLeastPrivilege(): void
    {
        foreach (['specialties', 'doctor_profiles'] as $table) {
            DB::statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            $this->revokeIfRole('clinic_reporter', 'ALL', $table);
            $this->revokeIfRole('clinic_worker', 'ALL', $table);
            $this->grantIfRole('clinic_backup', 'SELECT', $table);
        }

        $this->revokeIfRole('clinic_app', 'ALL', 'specialties');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', 'specialties');
        $this->revokeIfRole('clinic_app', 'ALL', 'doctor_profiles');
        $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', 'doctor_profiles');
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
