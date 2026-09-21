<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('does not fall back pgsql_audit url to the generic DB_URL', function () {
    expect(app()->environment())->toBe('testing');

    $contents = (string) file_get_contents(base_path('config/database.php'));

    expect($contents)->toContain("'url' => env('DB_AUDIT_URL')")
        ->and($contents)->not->toContain("env('DB_AUDIT_URL', env('DB_URL'))")
        ->and($contents)->toContain("env('DB_URL')");
});
