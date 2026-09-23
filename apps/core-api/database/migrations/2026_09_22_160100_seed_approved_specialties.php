<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 02 chunk 16: seed the approved active specialty catalogue.
 *
 * Source: database/data/approved_specialties.v1.php (repository engineering-
 * default vocabulary). Idempotent on `code`. Does not invent government or
 * syndicate certification claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var list<array{id: string, code: string, label_ar: string, label_en: string, sort_order: int}> $rows */
        $rows = require dirname(__DIR__).'/data/approved_specialties.v1.php';
        $now = now('UTC');

        foreach ($rows as $row) {
            $exists = DB::table('specialties')->where('code', $row['code'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('specialties')->insert([
                'id' => $row['id'],
                'code' => $row['code'],
                'label_ar' => $row['label_ar'],
                'label_en' => $row['label_en'],
                'active' => true,
                'sort_order' => $row['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        /** @var list<array{id: string, code: string, label_ar: string, label_en: string, sort_order: int}> $rows */
        $rows = require dirname(__DIR__).'/data/approved_specialties.v1.php';
        $ids = array_map(static fn (array $row): string => $row['id'], $rows);

        DB::table('specialties')->whereIn('id', $ids)->delete();
    }
};
