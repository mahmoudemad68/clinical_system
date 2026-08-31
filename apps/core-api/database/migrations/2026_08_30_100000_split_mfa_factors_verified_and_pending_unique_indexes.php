<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow one verified TOTP and one pending replacement TOTP per user.
 *
 * The original unique index treated any non-disabled TOTP as exclusive, so a
 * lost-authenticator replacement could not keep the old factor active while
 * the new secret was confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS mfa_factors_one_active_totp');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mfa_factors_one_verified_totp
                ON mfa_factors (user_id)
                WHERE factor_type = 'totp' AND disabled_at IS NULL AND verified_at IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mfa_factors_one_pending_totp
                ON mfa_factors (user_id)
                WHERE factor_type = 'totp' AND disabled_at IS NULL AND verified_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mfa_factors_one_verified_totp');
        DB::statement('DROP INDEX IF EXISTS mfa_factors_one_pending_totp');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX mfa_factors_one_active_totp
                ON mfa_factors (user_id)
                WHERE factor_type = 'totp' AND disabled_at IS NULL
        SQL);
    }
};
