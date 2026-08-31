<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable HMAC version markers so lookup-column rotation can resume without
 * an in-memory cursor. Existing rows default to version 1 (the only write
 * version before this change). Not a statutory retention column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('phone_hmac_version')->default(1);
        });

        Schema::table('identity_national_ids', function (Blueprint $table): void {
            $table->unsignedSmallInteger('hmac_key_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone_hmac_version');
        });

        Schema::table('identity_national_ids', function (Blueprint $table): void {
            $table->dropColumn('hmac_key_version');
        });
    }
};
