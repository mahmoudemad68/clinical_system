<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ISR-008: the audit chain is derived inside PostgreSQL. Callers may supply
 * business fields only. clinic_audit_writer writes solely through the
 * SECURITY DEFINER function.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.clinic_audit_canonical_metadata(p_metadata jsonb)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE
            PARALLEL SAFE
            SET search_path = pg_catalog, public
            AS $canonical$
                SELECT CASE
                    WHEN p_metadata IS NULL OR pg_catalog.jsonb_typeof(p_metadata) = 'null' THEN '[]'
                    WHEN pg_catalog.jsonb_typeof(p_metadata) = 'array' THEN p_metadata::text
                    WHEN pg_catalog.jsonb_typeof(p_metadata) = 'object'
                         AND NOT EXISTS (SELECT 1 FROM pg_catalog.jsonb_object_keys(p_metadata)) THEN '[]'
                    WHEN pg_catalog.jsonb_typeof(p_metadata) = 'object' THEN (
                        SELECT '{' || pg_catalog.string_agg(
                            pg_catalog.to_json(key)::text || ':' || value::text,
                            ','
                            ORDER BY key
                        ) || '}'
                        FROM pg_catalog.jsonb_each(p_metadata)
                    )
                    ELSE p_metadata::text
                END;
            $canonical$;
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.clinic_audit_canonical_metadata(jsonb) FROM PUBLIC');

        DB::statement('DROP FUNCTION IF EXISTS public.clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.clinic_append_audit_event(
                p_id uuid,
                p_event_name text,
                p_actor_id uuid,
                p_actor_type text,
                p_object_type text,
                p_object_id uuid,
                p_metadata jsonb,
                p_occurred_at timestamptz
            ) RETURNS void
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $append$
            DECLARE
                v_previous bytea;
                v_previous_hex text;
                v_sequence bigint;
                v_occurred timestamptz;
                v_occurred_text text;
                v_metadata_text text;
                v_canonical text;
                v_row_hash bytea;
            BEGIN
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtext('audit_events_chain'));

                SELECT ae.row_hash
                  INTO v_previous
                  FROM public.audit_events AS ae
                  ORDER BY ae.chain_sequence DESC
                  LIMIT 1
                  FOR UPDATE;

                v_sequence := pg_catalog.nextval('public.audit_events_chain_sequence_seq');
                v_occurred := COALESCE(p_occurred_at, pg_catalog.clock_timestamp());
                v_occurred_text := pg_catalog.to_char(
                    v_occurred AT TIME ZONE 'UTC',
                    'YYYY-MM-DD HH24:MI:SS.US'
                ) || '+00:00';
                v_metadata_text := pg_catalog.replace(
                    public.clinic_audit_canonical_metadata(COALESCE(p_metadata, '{}'::jsonb)),
                    '/',
                    E'\\/'
                );
                v_previous_hex := CASE
                    WHEN v_previous IS NULL THEN ''
                    ELSE pg_catalog.encode(v_previous, 'hex')
                END;
                v_canonical := v_previous_hex
                    || '|' || p_id::text
                    || '|' || COALESCE(p_event_name, '')
                    || '|' || COALESCE(p_object_type, '')
                    || '|' || p_object_id::text
                    || '|' || COALESCE(p_actor_id::text, '')
                    || '|' || COALESCE(p_actor_type, '')
                    || '|' || v_metadata_text
                    || '|' || v_occurred_text;
                v_row_hash := public.digest(pg_catalog.convert_to(v_canonical, 'UTF8'), 'sha256');

                INSERT INTO public.audit_events (
                    id, event_name, actor_id, actor_type, object_type, object_id,
                    metadata, previous_hash, row_hash, chain_sequence, occurred_at
                ) VALUES (
                    p_id,
                    p_event_name,
                    p_actor_id,
                    p_actor_type,
                    p_object_type,
                    p_object_id,
                    COALESCE(p_metadata, '{}'::jsonb),
                    v_previous,
                    v_row_hash,
                    v_sequence,
                    v_occurred
                );
            END;
            $append$;
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, timestamptz) FROM PUBLIC');
        $this->executeIfRole(
            'clinic_app',
            'GRANT EXECUTE ON FUNCTION public.clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, timestamptz) TO clinic_app',
        );
        $this->executeIfRole(
            'clinic_audit_writer',
            'GRANT EXECUTE ON FUNCTION public.clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, timestamptz) TO clinic_audit_writer',
        );

        $this->executeIfRole('clinic_app', 'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE audit_events FROM clinic_app');
        $this->executeIfRole('clinic_app', 'GRANT SELECT ON TABLE audit_events TO clinic_app');
        $this->executeIfRole('clinic_worker', 'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE audit_events FROM clinic_worker');
        $this->executeIfRole(
            'clinic_audit_writer',
            'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE audit_events FROM clinic_audit_writer',
        );
        $this->executeIfRole('clinic_audit_writer', 'GRANT SELECT ON TABLE audit_events TO clinic_audit_writer');
        $this->executeIfRole('clinic_reporter', 'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON TABLE audit_events FROM clinic_reporter');

        $this->executeIfRole('clinic_app', 'REVOKE USAGE, SELECT ON SEQUENCE audit_events_chain_sequence_seq FROM clinic_app');
        $this->executeIfRole(
            'clinic_audit_writer',
            'REVOKE USAGE, SELECT ON SEQUENCE audit_events_chain_sequence_seq FROM clinic_audit_writer',
        );
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS public.clinic_audit_canonical_metadata(jsonb)');

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
        $this->executeIfRole(
            'clinic_app',
            'GRANT EXECUTE ON FUNCTION clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz) TO clinic_app',
        );
        $this->executeIfRole(
            'clinic_audit_writer',
            'GRANT EXECUTE ON FUNCTION clinic_append_audit_event(uuid, text, uuid, text, text, uuid, jsonb, bytea, bytea, bigint, timestamptz) TO clinic_audit_writer',
        );
        $this->executeIfRole('clinic_app', 'GRANT SELECT ON TABLE audit_events TO clinic_app');
        $this->executeIfRole('clinic_app', 'GRANT USAGE, SELECT ON SEQUENCE audit_events_chain_sequence_seq TO clinic_app');
        $this->executeIfRole('clinic_audit_writer', 'GRANT SELECT, INSERT ON TABLE audit_events TO clinic_audit_writer');
        $this->executeIfRole(
            'clinic_audit_writer',
            'GRANT USAGE, SELECT ON SEQUENCE audit_events_chain_sequence_seq TO clinic_audit_writer',
        );
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

    private function literal(string $sql): string
    {
        return "'".str_replace("'", "''", $sql)."'";
    }
};
