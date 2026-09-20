<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 02 chunk 07 tenant-scope: a membership may only reference a branch
 * that belongs to the same organization. Independent FKs on organization_id
 * and branch_id cannot prove that. MATCH SIMPLE keeps founding-owner
 * branch_id NULL valid. This slice still does not write branch_operator rows.
 *
 * pharmacy_branches (organization_id, id) is a candidate key for the
 * composite FK. It does not replace the primary key on id or the
 * (organization_id, status) lookup index.
 *
 * The single-column pharmacy_memberships_branch_fk is dropped because the
 * composite FK supersedes it when branch_id is not null.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                ADD CONSTRAINT pharmacy_branches_organization_id_id_unique
                UNIQUE (organization_id, id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                DROP CONSTRAINT pharmacy_memberships_branch_fk
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_organization_branch_fk
                FOREIGN KEY (organization_id, branch_id)
                REFERENCES pharmacy_branches (organization_id, id)
                MATCH SIMPLE
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                DROP CONSTRAINT IF EXISTS pharmacy_memberships_organization_branch_fk
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_memberships
                ADD CONSTRAINT pharmacy_memberships_branch_fk
                FOREIGN KEY (branch_id) REFERENCES pharmacy_branches (id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pharmacy_branches
                DROP CONSTRAINT IF EXISTS pharmacy_branches_organization_id_id_unique
        SQL);
    }
};
