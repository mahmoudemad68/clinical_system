<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Application\Features\PlatformFeatures;
use App\Modules\Platform\Infrastructure\Audit\ConfigChangeAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Server-owned flags fail closed; config/flag changes are audited.
 */
final class FeatureFlagAndAuditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function v1_exclusions_stay_disabled_even_when_env_and_pennant_claim_otherwise(): void
    {
        config()->set('platform.features.online_payments', true);
        Feature::activate('online-payments');

        foreach (PlatformFeatures::V1_EXCLUSIONS as $name) {
            $this->assertFalse(
                PlatformFeatures::enabled($name),
                $name.' must remain disabled in V1.',
            );
        }
    }

    #[Test]
    public function a_flag_change_writes_an_audit_row_without_secret_values(): void
    {
        $auditor = $this->app->make(ConfigChangeAuditor::class);

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
    }
}
