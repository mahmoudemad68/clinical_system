<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * clinic_worker inserts verification_documents, and the protect trigger
 * SELECT ... FOR SHARE on verification_cases. FOR SHARE requires lock rights
 * clinic_worker must not have as table UPDATE. Run that lock as SECURITY
 * DEFINER so the worker path can promote uploads without granting broader
 * case DML.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.clinic_verification_documents_protect()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $$
            DECLARE
                case_status text;
                parent_id uuid;
            BEGIN
                parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.case_id ELSE NEW.case_id END;

                SELECT status INTO case_status
                FROM verification_cases
                WHERE id = parent_id
                FOR SHARE;

                IF TG_OP = 'INSERT' THEN
                    IF case_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'verification_documents is frozen after submission';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.case_id IS DISTINCT FROM OLD.case_id
                        OR NEW.requirement_code IS DISTINCT FROM OLD.requirement_code
                        OR NEW.object_id IS DISTINCT FROM OLD.object_id
                        OR NEW.sha256 IS DISTINCT FROM OLD.sha256
                        OR NEW.detected_mime IS DISTINCT FROM OLD.detected_mime
                        OR NEW.size_bytes IS DISTINCT FROM OLD.size_bytes
                        OR NEW.uploaded_at IS DISTINCT FROM OLD.uploaded_at
                        OR NEW.upload_intent_id IS DISTINCT FROM OLD.upload_intent_id
                    THEN
                        RAISE EXCEPTION 'verification_documents content identity is immutable';
                    END IF;

                    IF case_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'verification_documents is frozen after submission';
                    END IF;

                    RETURN NEW;
                END IF;

                IF case_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'verification_documents is frozen after submission';
                END IF;

                RETURN OLD;
            END;
            $$
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.clinic_verification_documents_protect() FROM PUBLIC');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.clinic_verification_documents_protect()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                case_status text;
                parent_id uuid;
            BEGIN
                parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.case_id ELSE NEW.case_id END;

                SELECT status INTO case_status
                FROM verification_cases
                WHERE id = parent_id
                FOR SHARE;

                IF TG_OP = 'INSERT' THEN
                    IF case_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'verification_documents is frozen after submission';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.case_id IS DISTINCT FROM OLD.case_id
                        OR NEW.requirement_code IS DISTINCT FROM OLD.requirement_code
                        OR NEW.object_id IS DISTINCT FROM OLD.object_id
                        OR NEW.sha256 IS DISTINCT FROM OLD.sha256
                        OR NEW.detected_mime IS DISTINCT FROM OLD.detected_mime
                        OR NEW.size_bytes IS DISTINCT FROM OLD.size_bytes
                        OR NEW.uploaded_at IS DISTINCT FROM OLD.uploaded_at
                        OR NEW.upload_intent_id IS DISTINCT FROM OLD.upload_intent_id
                    THEN
                        RAISE EXCEPTION 'verification_documents content identity is immutable';
                    END IF;

                    IF case_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'verification_documents is frozen after submission';
                    END IF;

                    RETURN NEW;
                END IF;

                IF case_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'verification_documents is frozen after submission';
                END IF;

                RETURN OLD;
            END;
            $$
        SQL);
    }
};
