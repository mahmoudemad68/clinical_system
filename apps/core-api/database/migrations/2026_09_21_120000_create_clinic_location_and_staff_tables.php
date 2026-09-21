<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 10: clinic locations owned by approved doctors, staff
 * profiles, location-scoped memberships, and secretary invitations.
 *
 * PostGIS is expected to already exist from pharmacy organization tables.
 * CREATE EXTENSION IF NOT EXISTS remains safe. The doctor_profiles foreign
 * key is required by the Phase-02 schema; application ownership checks go
 * through Doctors public services, not clinic SQL against doctor_profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('clinic_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('doctor_id');
            $table->string('public_name', 200);
            $table->binary('address_ciphertext');
            $table->unsignedSmallInteger('address_key_version');
            $table->char('country_code', 2)->default('EG');
            $table->string('status', 32);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_locations
                ADD COLUMN geography_point geography(Point, 4326) NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_locations
                ADD CONSTRAINT clinic_locations_doctor_fk
                FOREIGN KEY (doctor_id) REFERENCES doctor_profiles (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_locations
                ADD CONSTRAINT clinic_locations_status_check
                CHECK (status IN ('draft', 'pending', 'active', 'suspended', 'closed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_locations
                ADD CONSTRAINT clinic_locations_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_locations
                ADD CONSTRAINT clinic_locations_public_name_length_check
                CHECK (char_length(public_name) BETWEEN 1 AND 200)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_locations
                ADD CONSTRAINT clinic_locations_country_code_check
                CHECK (country_code ~ '^[A-Z]{2}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_locations
                ADD CONSTRAINT clinic_locations_geography_legal_check
                CHECK (
                    ST_X(geography_point::geometry) BETWEEN -180 AND 180
                    AND ST_Y(geography_point::geometry) BETWEEN -90 AND 90
                    AND ST_SRID(geography_point::geometry) = 4326
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX clinic_locations_doctor_status_index
                ON clinic_locations (doctor_id, status)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX clinic_locations_geography_point_gix
                ON clinic_locations USING GIST (geography_point)
        SQL);

        Schema::create('clinic_staff_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_profiles
                ADD CONSTRAINT clinic_staff_profiles_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clinic_staff_profiles_user_unique
                ON clinic_staff_profiles (user_id)
        SQL);

        Schema::create('clinic_staff_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_profile_id');
            $table->uuid('location_id');
            $table->string('role', 32);
            $table->string('status', 32);
            $table->timestampTz('invited_at', 6)->nullable();
            $table->timestampTz('accepted_at', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->uuid('inviter_user_id')->nullable();
            $table->uuid('revoker_user_id')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_memberships
                ADD CONSTRAINT clinic_staff_memberships_staff_profile_fk
                FOREIGN KEY (staff_profile_id) REFERENCES clinic_staff_profiles (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_memberships
                ADD CONSTRAINT clinic_staff_memberships_location_fk
                FOREIGN KEY (location_id) REFERENCES clinic_locations (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_memberships
                ADD CONSTRAINT clinic_staff_memberships_inviter_fk
                FOREIGN KEY (inviter_user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_memberships
                ADD CONSTRAINT clinic_staff_memberships_revoker_fk
                FOREIGN KEY (revoker_user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_memberships
                ADD CONSTRAINT clinic_staff_memberships_role_check
                CHECK (role IN ('doctor', 'secretary'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_memberships
                ADD CONSTRAINT clinic_staff_memberships_status_check
                CHECK (status IN ('pending', 'active', 'suspended', 'revoked'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_memberships
                ADD CONSTRAINT clinic_staff_memberships_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clinic_staff_memberships_active_grant_unique
                ON clinic_staff_memberships (location_id, staff_profile_id)
                WHERE status IN ('pending', 'active')
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX clinic_staff_memberships_location_status_index
                ON clinic_staff_memberships (location_id, status)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX clinic_staff_memberships_staff_profile_status_index
                ON clinic_staff_memberships (staff_profile_id, status)
        SQL);

        Schema::create('clinic_staff_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('location_id');
            $table->string('role', 32);
            $table->string('status', 32);
            $table->binary('target_phone_lookup_hmac');
            $table->unsignedSmallInteger('target_phone_key_version');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->uuid('inviter_user_id');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_invitations
                ADD CONSTRAINT clinic_staff_invitations_location_fk
                FOREIGN KEY (location_id) REFERENCES clinic_locations (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_invitations
                ADD CONSTRAINT clinic_staff_invitations_inviter_fk
                FOREIGN KEY (inviter_user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_invitations
                ADD CONSTRAINT clinic_staff_invitations_role_check
                CHECK (role = 'secretary')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clinic_staff_invitations
                ADD CONSTRAINT clinic_staff_invitations_status_check
                CHECK (status IN ('pending', 'consumed', 'expired', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clinic_staff_invitations_pending_target_unique
                ON clinic_staff_invitations (location_id, target_phone_lookup_hmac)
                WHERE status = 'pending'
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX clinic_staff_invitations_location_status_index
                ON clinic_staff_invitations (location_id, status)
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_staff_invitations');
        Schema::dropIfExists('clinic_staff_memberships');
        Schema::dropIfExists('clinic_staff_profiles');
        Schema::dropIfExists('clinic_locations');
    }

    private function grantLeastPrivilege(): void
    {
        foreach (['clinic_locations', 'clinic_staff_profiles', 'clinic_staff_memberships', 'clinic_staff_invitations'] as $table) {
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
