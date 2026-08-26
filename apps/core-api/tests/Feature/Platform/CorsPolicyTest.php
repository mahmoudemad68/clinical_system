<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('never allows a wildcard CORS origin', function () {
    $origins = config('cors.allowed_origins');

    expect($origins)->toBeArray()
        ->and($origins)->not->toContain('*')
        ->and(config('cors.allowed_origins_patterns'))->toBe([]);
});

it('does not reflect an unlisted origin', function () {
    $response = $this->call('OPTIONS', '/api/v1/health', server: [
        'HTTP_ORIGIN' => 'https://evil.invalid',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    $acao = $response->headers->get('Access-Control-Allow-Origin');
    expect($acao)->not->toBe('*')
        ->and($acao)->not->toBe('https://evil.invalid');
});

it('allows an enumerated local origin', function () {
    $response = $this->call('OPTIONS', '/api/v1/health', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->toBe('http://localhost:5173');
});
