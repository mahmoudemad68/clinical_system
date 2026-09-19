<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze verification_documents once the parent case leaves draft.
 * Content-identity columns are immutable even while draft; scan_status/status
 * may change only on a draft case through the trusted scanner path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinic_verification_documents_protect()
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

        DB::statement(<<<'SQL'
            DROP TRIGGER IF EXISTS verification_documents_protect ON verification_documents
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER verification_documents_protect
            BEFORE INSERT OR UPDATE OR DELETE ON verification_documents
            FOR EACH ROW
            EXECUTE FUNCTION clinic_verification_documents_protect()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS verification_documents_protect ON verification_documents');
        DB::statement('DROP FUNCTION IF EXISTS clinic_verification_documents_protect()');
    }
};
