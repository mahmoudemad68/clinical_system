<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Health;

use App\Modules\Platform\Application\Health\CheckStatus;
use App\Modules\Platform\Application\Health\DependencyCheck;
use App\Modules\Platform\Application\Health\HealthProbeClient;
use Throwable;

/**
 * The AI service is reachable. Optional by default.
 *
 * This is the isolation proof (gate G-02-04): with `critical` false, stopping
 * the AI service leaves core /ready at 200 while this entry reports degraded.
 *
 * Note it reports Degraded rather than Fail when non-critical. The distinction
 * is visible to operators and dashboards, and it keeps the readiness contract
 * honest: the dependency really is down, the process really can still serve.
 */
final class AiServiceCheck implements DependencyCheck
{
    public function __construct(
        private readonly HealthProbeClient $client,
        private readonly bool $critical = false,
    ) {
    }

    public function name(): string
    {
        return 'ai_service';
    }

    public function isCritical(): bool
    {
        return $this->critical;
    }

    public function run(): CheckStatus
    {
        try {
            if ($this->client->isLive()) {
                return CheckStatus::Pass;
            }

            return $this->critical ? CheckStatus::Fail : CheckStatus::Degraded;
        } catch (Throwable) {
            return $this->critical ? CheckStatus::Fail : CheckStatus::Degraded;
        }
    }
}
