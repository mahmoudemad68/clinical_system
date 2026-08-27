<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Health;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Modules\Platform\Services\Telemetry\PlatformMetrics;
use Throwable;

/**
 * One named Redis connection responds to PING.
 *
 * Each named role (cache, queue, realtime, ratelimit) is checked separately,
 * because production runs them on different instances (plan.md section 114) and
 * an aggregate check would hide which one is down.
 */
final class RedisCheck implements DependencyCheck
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connectionName,
        private readonly bool $critical = true,
        private readonly ?PlatformMetrics $metrics = null,
    ) {}

    public function name(): string
    {
        return "redis_{$this->connectionName}";
    }

    public function isCritical(): bool
    {
        return $this->critical;
    }

    public function run(): CheckStatus
    {
        try {
            $this->redis->connection($this->connectionName)->command('ping', []);

            return CheckStatus::Pass;
        } catch (Throwable) {
            $this->metrics?->increment('clinic_redis_errors_total', [
                'connection' => $this->connectionName,
            ]);

            return CheckStatus::Fail;
        }
    }
}
