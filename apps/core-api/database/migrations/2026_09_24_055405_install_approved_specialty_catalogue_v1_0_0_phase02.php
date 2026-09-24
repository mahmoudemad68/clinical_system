<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Doctors\Services\InstallApprovedSpecialtyCatalogue;

/**
 * Phase 02: install independently approved specialty catalogue v1.0.0-phase02.
 *
 * First production catalogue. Fail-closed if unexpected or synthetic rows exist.
 * Does not modify 2026_09_19_140000_create_doctor_profile_tables.php.
 *
 * These are product-approved specialty labels, not government or syndicate
 * certification claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new InstallApprovedSpecialtyCatalogue(DB::connection()))->install();
    }

    public function down(): void
    {
        (new InstallApprovedSpecialtyCatalogue(DB::connection()))->rollBack();
    }
};
