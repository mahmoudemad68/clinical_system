<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('refuses an unauthenticated private-channel subscriber', function () {
    $response = $this->postJson('/broadcasting/auth', [
        'socket_id' => '1.1',
        'channel_name' => 'private-platform.health',
    ]);

    expect($response->status())->toBeIn([401, 403, 404]);

    $body = (string) $response->getContent();
    expect($body)->not->toContain('true')
        ->and($body)->not->toContain('"auth"');
});
