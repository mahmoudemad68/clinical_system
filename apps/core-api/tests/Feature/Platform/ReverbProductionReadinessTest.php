<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->readinessSnapshot = [
        'app.env' => config('app.env'),
        'app.url' => config('app.url'),
        'session.secure' => config('session.secure'),
        'identity.trusted_proxies' => config('identity.trusted_proxies'),
        'database.connections.pgsql.sslmode' => config('database.connections.pgsql.sslmode'),
        'database.connections.pgsql_migrator.sslmode' => config('database.connections.pgsql_migrator.sslmode'),
        'database.connections.pgsql_worker.sslmode' => config('database.connections.pgsql_worker.sslmode'),
        'database.connections.pgsql_reporter.sslmode' => config('database.connections.pgsql_reporter.sslmode'),
        'database.connections.pgsql_audit.sslmode' => config('database.connections.pgsql_audit.sslmode'),
        'reverb.apps.apps' => config('reverb.apps.apps'),
        'reverb.servers.reverb.host' => config('reverb.servers.reverb.host'),
        'reverb.servers.reverb.host_explicit' => config('reverb.servers.reverb.host_explicit'),
        'cors.allowed_origins' => config('cors.allowed_origins'),
        'cors.allowed_origins_patterns' => config('cors.allowed_origins_patterns'),
    ];
});

afterEach(function () {
    config($this->readinessSnapshot);
});

it('fails /ready when production reverb credentials are empty', function () {
    config([
        'app.env' => 'production',
        'app.url' => 'https://clinic.example',
        'session.secure' => true,
        'identity.trusted_proxies' => ['10.0.0.1'],
        'database.connections.pgsql.sslmode' => 'require',
        'database.connections.pgsql_migrator.sslmode' => 'require',
        'database.connections.pgsql_worker.sslmode' => 'require',
        'database.connections.pgsql_reporter.sslmode' => 'require',
        'database.connections.pgsql_audit.sslmode' => 'require',
        'reverb.apps.apps.0.app_id' => '',
        'reverb.apps.apps.0.key' => '',
        'reverb.apps.apps.0.secret' => '',
        'reverb.apps.apps.0.allowed_origins' => ['app.clinic.example'],
        'reverb.apps.apps.0.origins_explicit' => true,
        'reverb.apps.apps.0.scheme_explicit' => true,
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.apps.apps.0.options.useTLS' => true,
        'reverb.servers.reverb.host' => '0.0.0.0',
        'reverb.servers.reverb.host_explicit' => true,
        'cors.allowed_origins' => ['https://admin.clinic.example'],
        'cors.allowed_origins_patterns' => [],
    ]);

    $response = $this->getJson('/ready');
    $check = collect($response->json('checks'))->firstWhere('name', 'configuration');

    expect($response->status())->toBe(503)
        ->and($check)->not->toBeNull()
        ->and($check['status'])->toBe('fail')
        ->and($check['critical'])->toBeTrue();
});
