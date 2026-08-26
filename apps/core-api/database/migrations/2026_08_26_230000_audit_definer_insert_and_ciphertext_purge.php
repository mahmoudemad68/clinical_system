<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ISR-001 / ISR-008: audit insert via SECURITY DEFINER, append-only trigger,
 * backup-complete SELECT, OTP ciphertext nullable for purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_requests')) {
            DB::statement('ALTER TABLE otp_requests ALTER COLUMN code_ciphertext DROP NOT NULL');
            DB::statement('ALTER TABLE otp_requests ALTER COLUMN destination_ciphertext DROP NOT NULL');
        }

        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT IF EXISTS audit_events_no_update_delete');
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT IF EXISTS audit_events_chain_sequence_positive');
        DB::statement('ALTER TABLE audit_events ADD CONSTRAINT audit_events_chain_sequence_positive CHECK (chain_sequence > 0)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinic_audit_deny_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS audit_events_no_update_delete ON audit_events');
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_events_no_update_delete
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW
            EXECUTE FUNCTION clinic_audit_deny_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinic_append_audit_event(
                p_id uuid,
                p_event_name text,
                p_actor_id uuid,
                p_actor_type text,
                p_object_type text,
                p_object_id uuid,
                p_metadata jsonb,
                p_previous_hash bytea,
                p_row_hash bytea,
                p_chain_sequence bigint,
                p_occurred_at timestamptz
            ) RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS $$
            BEGIN
                INSERT INTO audit_events (
                    id, event_name, actor_id, actor_type, object_type, object_id,
                    metadata, previous_hash, row_hash, chain_sequence, occurred_at
                ) VALUES (
                    p_id, p_event_name, p_actor_id, p_actor_type, p_object_type, p_object_id,
                    p_metadata, p_previous_hash, p_row_hash, p_chain_sequence, p_occurred_at
                );
            END;
            $$
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz) FROM PUBLIC');

        $this->executeIfRole('clinic_app', 'GRANT EXECUTE ON FUNCTION clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz) TO clinic_app');
        $this->executeIfRole('clinic_audit_writer', 'GRANT EXECUTE ON FUNCTION clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz) TO clinic_audit_writer');
        $this->executeIfRole('clinic_app', 'REVOKE INSERT ON TABLE audit_events FROM clinic_app');
        $this->executeIfRole('clinic_worker', 'REVOKE INSERT ON TABLE audit_events FROM clinic_worker');
        $this->executeIfRole('clinic_app', 'GRANT SELECT ON TABLE audit_events TO clinic_app');
        $this->executeIfRole('clinic_app', 'GRANT USAGE, SELECT ON SEQUENCE audit_events_chain_sequence_seq TO clinic_app');
        $this->executeIfRole('clinic_audit_writer', 'GRANT SELECT, INSERT ON TABLE audit_events TO clinic_audit_writer');

        if (Schema::hasTable('audit_events')) {
            $this->grantSelectToBackup('audit_events');
        }

        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        foreach ($tables as $row) {
            $table = (string) $row->tablename;
            $this->grantSelectToBackup($table);
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_backup') THEN
                    GRANT USAGE ON SCHEMA public TO clinic_backup;
                    GRANT USAGE ON SCHEMA reporting TO clinic_backup;
                    GRANT SELECT ON ALL TABLES IN SCHEMA reporting TO clinic_backup;
                    GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO clinic_backup;
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_events_no_update_delete ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS clinic_audit_deny_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz)');
        $this->executeIfRole('clinic_app', 'GRANT SELECT, INSERT ON TABLE audit_events TO clinic_app');
    }

    private function executeIfRole(string $role, string $sql): void
    {
        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE {$this->literal($sql)};
                END IF;
            END
            \$\$;
        SQL);
    }

    private function grantSelectToBackup(string $table): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $table)) {
            return;
        }

        $this->executeIfRole('clinic_backup', 'GRANT SELECT ON TABLE '.$table.' TO clinic_backup');
    }

    private function literal(string $sql): string
    {
        return "'".str_replace("'", "''", $sql)."'";
    }
};
