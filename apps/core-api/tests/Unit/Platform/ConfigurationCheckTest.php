<?php

declare(strict_types=1);

use Modules\Platform\Services\Health\CheckStatus;
use Modules\Platform\Services\Health\ConfigurationCheck;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array<string, mixed>
 */
function productionReverbBaseline(): array
{
    return [
        'app.env' => 'production',
        'app.url' => 'https://clinic.example',
        'session.secure' => true,
        'identity.trusted_proxies' => ['10.0.0.1'],
        'database.connections.pgsql.sslmode' => 'require',
        'database.connections.pgsql_migrator.sslmode' => 'require',
        'database.connections.pgsql_worker.sslmode' => 'require',
        'database.connections.pgsql_reporter.sslmode' => 'require',
        'database.connections.pgsql_audit.sslmode' => 'require',
        'reverb.apps.apps.0.app_id' => 'clinic-prod-test',
        'reverb.apps.apps.0.key' => 'local_dev_only_not_a_secret',
        'reverb.apps.apps.0.secret' => 'local_dev_only_not_a_secret',
        'reverb.apps.apps.0.allowed_origins' => ['app.clinic.example'],
        'reverb.apps.apps.0.origins_explicit' => true,
        'reverb.apps.apps.0.scheme_explicit' => true,
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.apps.apps.0.options.useTLS' => true,
        'reverb.servers.reverb.host' => '0.0.0.0',
        'reverb.servers.reverb.host_explicit' => true,
        'cors.allowed_origins' => ['https://admin.clinic.example'],
        'cors.allowed_origins_patterns' => [],
    ];
}

function configurationCheck(): ConfigurationCheck
{
    return new ConfigurationCheck([
        'app.key',
        'app.version',
        'database.default',
    ]);
}

beforeEach(function () {
    $this->configurationSnapshot = [
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
        'identity.hmac.current_version' => config('identity.hmac.current_version'),
        'identity.encryption.current_version' => config('identity.encryption.current_version'),
        'identity.hmac.keys' => config('identity.hmac.keys'),
        'identity.encryption.keys' => config('identity.encryption.keys'),
    ];
});

afterEach(function () {
    config($this->configurationSnapshot);
});

it('fails closed when production reverb credentials are missing', function () {
    config(productionReverbBaseline());
    config([
        'reverb.apps.apps.0.app_id' => null,
        'reverb.apps.apps.0.key' => null,
        'reverb.apps.apps.0.secret' => null,
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production reverb credentials are empty', function () {
    config(productionReverbBaseline());
    config([
        'reverb.apps.apps.0.app_id' => '',
        'reverb.apps.apps.0.key' => '',
        'reverb.apps.apps.0.secret' => '',
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production reverb origins are implicit localhost defaults', function () {
    config(productionReverbBaseline());
    config([
        'reverb.apps.apps.0.allowed_origins' => ['localhost', '127.0.0.1'],
        'reverb.apps.apps.0.origins_explicit' => false,
        'reverb.servers.reverb.host_explicit' => false,
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production reverb allows a wildcard origin', function () {
    config(productionReverbBaseline());
    config([
        'reverb.apps.apps.0.allowed_origins' => ['*'],
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('passes when production reverb configuration is explicit and valid', function () {
    config(productionReverbBaseline());

    expect(configurationCheck()->run())->toBe(CheckStatus::Pass);
});

it('fails closed when production reverb bind host is not explicit', function () {
    config(productionReverbBaseline());
    config(['reverb.servers.reverb.host_explicit' => false]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('passes local testing reverb configuration without production gates', function () {
    expect((string) config('app.env'))->toBe('testing')
        ->and(configurationCheck()->run())->toBe(CheckStatus::Pass);
});

it('fails closed when production APP_URL is http', function () {
    config(productionReverbBaseline());
    config(['app.url' => 'http://clinic.example']);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production session.secure is false', function () {
    config(productionReverbBaseline());
    config(['session.secure' => false]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production PostgreSQL sslmode is prefer, allow, or disable', function (string $mode) {
    config(productionReverbBaseline());
    config([
        'database.connections.pgsql.sslmode' => $mode,
        'database.connections.pgsql_migrator.sslmode' => $mode,
        'database.connections.pgsql_worker.sslmode' => $mode,
        'database.connections.pgsql_reporter.sslmode' => $mode,
        'database.connections.pgsql_audit.sslmode' => $mode,
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
})->with(['prefer', 'allow', 'disable']);

it('fails closed when production CORS origins are empty', function () {
    config(productionReverbBaseline());
    config(['cors.allowed_origins' => []]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production CORS allows localhost or loopback', function (string $origin) {
    config(productionReverbBaseline());
    config(['cors.allowed_origins' => [$origin]]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
})->with([
    'http://localhost:5173',
    'https://localhost',
    'http://127.0.0.1:3000',
    'https://127.0.0.1',
    'https://[::1]',
    'https://[0:0:0:0:0:0:0:1]',
    'https://[::ffff:127.0.0.1]',
]);

it('fails closed when production CORS origin is an unbracketed or truncated IPv6 loopback URL', function (string $origin) {
    config(productionReverbBaseline());
    config(['cors.allowed_origins' => [$origin]]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
})->with([
    'https://::1',
    'https://[::1',
]);

it('passes when production CORS allows a non-loopback HTTPS IPv6 origin', function () {
    config(productionReverbBaseline());
    config(['cors.allowed_origins' => ['https://[2001:db8::10]']]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Pass);
});

it('fails closed when production reverb allows IPv6 loopback hosts', function (string $origin) {
    config(productionReverbBaseline());
    config(['reverb.apps.apps.0.allowed_origins' => [$origin]]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
})->with([
    '[::1]',
    '::1',
    '0:0:0:0:0:0:0:1',
    'https://[::1]',
]);

it('fails closed when production CORS allows a wildcard origin', function () {
    config(productionReverbBaseline());
    config(['cors.allowed_origins' => ['*']]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production CORS uses origin patterns', function () {
    config(productionReverbBaseline());
    config(['cors.allowed_origins_patterns' => ['https://.*\\.clinic\\.example']]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production CORS origin is not https', function () {
    config(productionReverbBaseline());
    config(['cors.allowed_origins' => ['http://admin.clinic.example']]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when production CORS origin is malformed', function () {
    config(productionReverbBaseline());
    config(['cors.allowed_origins' => ['https://']]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when the configured current hmac key is missing', function () {
    config(productionReverbBaseline());
    config([
        'identity.hmac.current_version' => 2,
        'identity.hmac.keys.2' => '',
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('fails closed when the configured current encryption key is weak', function () {
    config(productionReverbBaseline());
    config([
        'identity.encryption.current_version' => 2,
        'identity.encryption.keys.2' => str_repeat('x', 31),
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Fail);
});

it('passes when current_version is 2 and the v2 keys meet the floor', function () {
    config(productionReverbBaseline());
    config([
        'identity.hmac.current_version' => 2,
        'identity.encryption.current_version' => 2,
        'identity.hmac.keys.2' => 'test_identity_hmac_v2_not_a_secret_value!!',
        'identity.encryption.keys.2' => 'test_identity_enc_v2_not_a_secret_value!!',
    ]);

    expect(configurationCheck()->run())->toBe(CheckStatus::Pass);
});
