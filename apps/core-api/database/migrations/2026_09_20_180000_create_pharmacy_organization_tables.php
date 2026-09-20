<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 07: pharmacy organization, initial branch, and founding
 * owner membership. Phase 10 later extends these same tables with operating
 * mode, payment methods, and the business capability matrix. This migration
 * does not create a second pharmacy aggregate.
 *
 * Unique registration HMAC and unique founding-owner memberships are the
 * concurrent uniqueness controls. Verification cases remain owned by
 * Verification, not Pharmacies. PostGIS is enabled here because this is the
 * first Core table that stores geography(Point, 4326); later clinic locations
 * reuse the same extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::create('pharmacy_organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->binary('legal_name_ciphertext');
            $table->unsignedSmallInteger('legal_name_key_version');
            $table->string('public_name', 200);
            $table->binary('registration_ciphertext');
            $table->binary('registration_lookup_hmac');
            $table->unsignedSmallInteger('registration_key_version');
            $table->string('verification_status', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_organizations
                ADD CONSTRAINT pharmacy_organizations_verification_status_check
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
            ALTER TABLE pharmacy_organizations
                ADD CONSTRAINT pharmacy_organizations_status_check
                CHECK (status IN ('draft', 'pending', 'active', 'suspended', 'closed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_organizations
                ADD CONSTRAINT pharmacy_organizations_active_requires_approved_check
                CHECK (status <> 'active' OR verification_status = 'approved')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_organizations
                ADD CONSTRAINT pharmacy_organizations_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_organizations
                ADD CONSTRAINT pharmacy_organizations_public_name_length_check
                CHECK (char_length(public_name) BETWEEN 1 AND 200)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pharmacy_organizations_registration_hmac_unique
                ON pharmacy_organizations (registration_lookup_hmac)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX pharmacy_organizations_status_index
                ON pharmacy_organizations (verification_status, status)
        SQL);

        Schema::create('pharmacy_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('public_name', 200);
            $table->binary('address_ciphertext');
            $table->unsignedSmallInteger('address_key_version');
            $table->char('country_code', 2);
            $table->binary('phone_ciphertext');
            $table->unsignedSmallInteger('phone_key_version');
            $table->string('status', 32);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD COLUMN geography_point geography(Point, 4326) NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD CONSTRAINT pharmacy_branches_organization_fk
                FOREIGN KEY (organization_id) REFERENCES pharmacy_organizations (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD CONSTRAINT pharmacy_branches_status_check
                CHECK (status IN ('draft', 'pending', 'active', 'suspended', 'closed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD CONSTRAINT pharmacy_branches_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD CONSTRAINT pharmacy_branches_public_name_length_check
                CHECK (char_length(public_name) BETWEEN 1 AND 200)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD CONSTRAINT pharmacy_branches_country_code_check
                CHECK (country_code ~ '^[A-Z]{2}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD CONSTRAINT pharmacy_branches_geography_legal_check
                CHECK (
                    ST_X(geography_point::geometry) BETWEEN -180 AND 180
                    AND ST_Y(geography_point::geometry) BETWEEN -90 AND 90
                    AND ST_SRID(geography_point::geometry) = 4326
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX pharmacy_branches_organization_status_index
                ON pharmacy_branches (organization_id, status)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX pharmacy_branches_geography_point_gix
                ON pharmacy_branches USING GIST (geography_point)
        SQL);

        Schema::create('pharmacy_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->uuid('branch_id')->nullable();
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
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_organization_fk
                FOREIGN KEY (organization_id) REFERENCES pharmacy_organizations (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_branch_fk
                FOREIGN KEY (branch_id) REFERENCES pharmacy_branches (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_role_check
                CHECK (role IN ('owner', 'branch_operator'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_status_check
                CHECK (status IN ('pending', 'active', 'suspended', 'revoked'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_owner_scope_check
                CHECK (
                    (role = 'owner' AND branch_id IS NULL)
                    OR (role = 'branch_operator' AND branch_id IS NOT NULL)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pharmacy_memberships_organization_user_unique
                ON pharmacy_memberships (organization_id, user_id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pharmacy_memberships_founding_owner_user_unique
                ON pharmacy_memberships (user_id)
                WHERE role = 'owner' AND status IN ('pending', 'active')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pharmacy_memberships_founding_owner_org_unique
                ON pharmacy_memberships (organization_id)
                WHERE role = 'owner' AND status IN ('pending', 'active')
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX pharmacy_memberships_user_status_index
                ON pharmacy_memberships (user_id, status)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX pharmacy_memberships_organization_status_index
                ON pharmacy_memberships (organization_id, status)
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_memberships');
        Schema::dropIfExists('pharmacy_branches');
        Schema::dropIfExists('pharmacy_organizations');
    }

    private function grantLeastPrivilege(): void
    {
        foreach (['pharmacy_organizations', 'pharmacy_branches', 'pharmacy_memberships'] as $table) {
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
