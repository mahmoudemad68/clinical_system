<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pennant feature flag store plus the configuration/flag/secret-access audit.
 *
 * Audit rows never contain secret values, national IDs, or clinical text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();
            $table->unique(['name', 'scope']);
        });

        Schema::create('platform_config_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 32);
            $table->string('key', 128);
            $table->string('from_value', 64)->nullable();
            $table->string('to_value', 64)->nullable();
            $table->string('actor_key', 64)->nullable();
            $table->timestampTz('occurred_at', 6);
        });

        DB::statement("ALTER TABLE platform_config_audits ADD CONSTRAINT platform_config_audits_kind_check CHECK (kind IN ('flag', 'config', 'secret_access'))");
        DB::statement("ALTER TABLE platform_config_audits ADD CONSTRAINT platform_config_audits_key_check CHECK (key ~ '^[a-z][a-z0-9._-]{0,127}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_config_audits');
        Schema::dropIfExists('features');
    }
};
