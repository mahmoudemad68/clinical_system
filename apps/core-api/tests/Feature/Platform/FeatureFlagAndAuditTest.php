<?php

declare(strict_types=1);

use App\Modules\Platform\Application\Features\PlatformFeatures;
use App\Modules\Platform\Infrastructure\Audit\ConfigChangeAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps V1 exclusions disabled even when env and pennant claim otherwise', function () {
    config()->set('platform.features.online_payments', true);
    Feature::activate('online-payments');

    foreach (PlatformFeatures::V1_EXCLUSIONS as $name) {
        expect(PlatformFeatures::enabled($name))->toBeFalse();
    }
});

it('writes an audit row without secret values', function () {
    $auditor = app(ConfigChangeAuditor::class);

    $auditor->record(
        kind: 'flag',
        key: 'diagnostics-slice',
        fromValue: 'false',
        toValue: 'true',
        actorKey: 'test',
    );

    $auditor->record(
        kind: 'secret_access',
        key: 'ai.internal-token',
        fromValue: null,
        toValue: 'local_dev_only_not_a_secret_value_that_is_long',
        actorKey: 'test',
    );

    $this->assertDatabaseHas('platform_config_audits', [
        'kind' => 'flag',
        'key' => 'diagnostics-slice',
        'to_value' => 'true',
    ]);

    $this->assertDatabaseHas('platform_config_audits', [
        'kind' => 'secret_access',
        'key' => 'ai.internal-token',
        'to_value' => '[withheld]',
    ]);
});
