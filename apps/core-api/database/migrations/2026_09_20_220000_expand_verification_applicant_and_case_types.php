<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 02 chunk 08: allow pharmacy verification cases on the existing
 * Verification tables. Does not rewrite historical migrations. Pairing is
 * enforced so doctor/pharmacy applicant types cannot mix with the other
 * case type. Open-case uniqueness and queue/history indexes are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE verification_cases DROP CONSTRAINT verification_cases_applicant_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_applicant_type_check
                CHECK (applicant_type IN ('doctor', 'pharmacy'))
        SQL);

        DB::statement('ALTER TABLE verification_cases DROP CONSTRAINT verification_cases_case_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_case_type_check
                CHECK (case_type IN ('doctor_verification', 'pharmacy_verification'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_applicant_case_type_pair_check
                CHECK (
                    (applicant_type = 'doctor' AND case_type = 'doctor_verification')
                    OR (applicant_type = 'pharmacy' AND case_type = 'pharmacy_verification')
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE verification_cases DROP CONSTRAINT IF EXISTS verification_cases_applicant_case_type_pair_check');

        DB::statement('ALTER TABLE verification_cases DROP CONSTRAINT verification_cases_applicant_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_applicant_type_check
                CHECK (applicant_type IN ('doctor'))
        SQL);

        DB::statement('ALTER TABLE verification_cases DROP CONSTRAINT verification_cases_case_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE verification_cases
                ADD CONSTRAINT verification_cases_case_type_check
                CHECK (case_type IN ('doctor_verification'))
        SQL);
    }
};
