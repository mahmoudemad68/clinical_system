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
    ];
}

function configurationCheck(): ConfigurationCheck
{
    return new ConfigurationCheck([
        'app.key',
        'app.version',
        'database.default',
        'identity.hmac.keys.1',
        'identity.encryption.keys.1',
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
