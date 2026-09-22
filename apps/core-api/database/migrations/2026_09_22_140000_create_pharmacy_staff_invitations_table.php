<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 14: pharmacy staff invitations for the fixed-scope
 * branch_operator membership. Additional branches reuse pharmacy_branches.
 *
 * The composite FK (organization_id, branch_id) keeps a membership invitation
 * from targeting a branch that belongs to a different organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_staff_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('role', 32);
            $table->string('status', 32);
            $table->binary('target_phone_lookup_hmac');
            $table->unsignedSmallInteger('target_phone_key_version');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('invited_at', 6);
            $table->timestampTz('accepted_at', 6)->nullable();
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->uuid('inviter_user_id');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_staff_invitations
                ADD CONSTRAINT pharmacy_staff_invitations_organization_fk
                FOREIGN KEY (organization_id) REFERENCES pharmacy_organizations (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_staff_invitations
                ADD CONSTRAINT pharmacy_staff_invitations_organization_branch_fk
                FOREIGN KEY (organization_id, branch_id)
                REFERENCES pharmacy_branches (organization_id, id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_staff_invitations
                ADD CONSTRAINT pharmacy_staff_invitations_inviter_fk
                FOREIGN KEY (inviter_user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_staff_invitations
                ADD CONSTRAINT pharmacy_staff_invitations_role_check
                CHECK (role = 'branch_operator')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_staff_invitations
                ADD CONSTRAINT pharmacy_staff_invitations_status_check
                CHECK (status IN ('pending', 'consumed', 'expired', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_staff_invitations
                ADD CONSTRAINT pharmacy_staff_invitations_version_positive_check
                CHECK (version >= 1)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pharmacy_staff_invitations_pending_target_unique
                ON pharmacy_staff_invitations (organization_id, branch_id, target_phone_lookup_hmac)
                WHERE status = 'pending'
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX pharmacy_staff_invitations_branch_status_index
                ON pharmacy_staff_invitations (branch_id, status)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX pharmacy_staff_invitations_organization_status_index
                ON pharmacy_staff_invitations (organization_id, status)
        SQL);

        $this->grantLeastPrivilege();
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_staff_invitations');
    }

    private function grantLeastPrivilege(): void
    {
        $table = 'pharmacy_staff_invitations';
        DB::statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
        $this->revokeIfRole('clinic_reporter', 'ALL', $table);
        $this->revokeIfRole('clinic_worker', 'ALL', $table);
        $this->grantIfRole('clinic_backup', 'SELECT', $table);
        $this->revokeIfRole('clinic_app', 'ALL', $table);
        $this->grantIfRole('clinic_app', 'SELECT, INSERT, UPDATE, DELETE', $table);
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
