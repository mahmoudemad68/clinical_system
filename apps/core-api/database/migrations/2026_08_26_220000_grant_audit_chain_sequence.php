<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'audit_events_chain_sequence_seq')
                   AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_app') THEN
                    EXECUTE 'GRANT USAGE, SELECT ON SEQUENCE audit_events_chain_sequence_seq TO clinic_app';
                END IF;
                IF EXISTS (SELECT 1 FROM pg_class WHERE relname = 'audit_events_chain_sequence_seq')
                   AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'clinic_audit_writer') THEN
                    EXECUTE 'GRANT USAGE, SELECT ON SEQUENCE audit_events_chain_sequence_seq TO clinic_audit_writer';
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        // Sequence grants are additive; leaving them in place is safe.
    }
};
