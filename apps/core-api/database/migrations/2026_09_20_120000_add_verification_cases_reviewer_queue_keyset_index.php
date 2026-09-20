<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reviewer queue keyset: case_type, status, submitted_at, id.
 * Does not replace the existing (case_type, status, submitted_at) index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE INDEX verification_cases_reviewer_queue_keyset_index
                ON verification_cases (case_type, status, submitted_at, id)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS verification_cases_reviewer_queue_keyset_index');
    }
};
