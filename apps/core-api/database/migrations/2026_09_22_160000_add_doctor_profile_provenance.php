<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 chunk 16: record how a doctor profile was established and which
 * authenticated user created it. Self-onboarded rows backfill created_by to
 * the linked doctor user. Admin-created rows point at the creating Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->string('source_type', 32)->default('self_onboarding');
            $table->uuid('created_by_user_id')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE doctor_profiles
            SET created_by_user_id = user_id
            WHERE created_by_user_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ALTER COLUMN created_by_user_id SET NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_source_type_check
                CHECK (source_type IN ('self_onboarding', 'admin_created'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_profiles
                ADD CONSTRAINT doctor_profiles_created_by_user_fk
                FOREIGN KEY (created_by_user_id) REFERENCES users (id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX doctor_profiles_created_by_user_index
                ON doctor_profiles (created_by_user_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE doctor_profiles DROP CONSTRAINT IF EXISTS doctor_profiles_created_by_user_fk');
        DB::statement('ALTER TABLE doctor_profiles DROP CONSTRAINT IF EXISTS doctor_profiles_source_type_check');
        DB::statement('DROP INDEX IF EXISTS doctor_profiles_created_by_user_index');

        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'created_by_user_id']);
        });
    }
};
