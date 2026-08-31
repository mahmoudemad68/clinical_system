<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('loads sanctum stateful domains from SANCTUM_STATEFUL_DOMAINS, not the request Host header', function () {
    $configured = config('sanctum.stateful');

    expect($configured)->toBeArray()->not->toBeEmpty();

    $this->call('GET', '/api/v1/health', server: [
        'HTTP_HOST' => 'evil.invalid',
    ]);

    expect(config('sanctum.stateful'))->toBe($configured)
        ->and($configured)->not->toContain('evil.invalid');
});
