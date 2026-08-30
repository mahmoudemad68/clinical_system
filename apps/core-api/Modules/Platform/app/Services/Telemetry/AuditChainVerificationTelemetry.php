<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Telemetry;

use Illuminate\Contracts\Cache\Repository;
use Modules\Platform\Contracts\Clock;
use Throwable;

/**
 * Durable audit-chain verification health for Prometheus scrape.
 *
 * The scheduler process is not the scraped HTTP worker. Results live in the
 * shared cache so /metrics can distinguish pass, fail, and silence without
 * holding hashes, signatures, or audit payloads.
 */
final class AuditChainVerificationTelemetry
{
    public const KEY_OK = 'platform:audit:chain:verification:ok';

    public const KEY_LAST_RUN = 'platform:audit:chain:verification:last_run';

    public const KEY_LAST_SUCCESS = 'platform:audit:chain:verification:last_success';

    public const KEY_FAILURES = 'platform:audit:chain:verification:failures';

    public const KEY_OBSERVED = 'platform:audit:chain:verification:observed';

    public function __construct(
        private readonly Repository $cache,
        private readonly Clock $clock,
    ) {}

    public function record(bool $ok): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->cache->forever(self::KEY_LAST_RUN, $now);
        $this->cache->forever(self::KEY_OK, $ok ? 1 : 0);

        if ($ok) {
            $this->cache->forever(self::KEY_LAST_SUCCESS, $now);

            return;
        }

        $this->cache->increment(self::KEY_FAILURES);
    }

    /**
     * @return array{ok: int, last_run: int, last_success: int, failures: int, staleness: int}
     */
    public function snapshot(): array
    {
        try {
            $lastRun = (int) ($this->cache->get(self::KEY_LAST_RUN) ?? 0);
            $lastSuccess = (int) ($this->cache->get(self::KEY_LAST_SUCCESS) ?? 0);
            $ok = (int) ($this->cache->get(self::KEY_OK) ?? 0);
            $failures = (int) ($this->cache->get(self::KEY_FAILURES) ?? 0);
            $now = $this->clock->now()->getTimestamp();

            if ($lastRun === 0) {
                $observed = (int) ($this->cache->get(self::KEY_OBSERVED) ?? 0);
                if ($observed === 0) {
                    $observed = $now;
                    $this->cache->forever(self::KEY_OBSERVED, $observed);
                }
                $staleness = max(0, $now - $observed);
            } else {
                $staleness = max(0, $now - $lastRun);
            }
        } catch (Throwable) {
            return [
                'ok' => 0,
                'last_run' => 0,
                'last_success' => 0,
                'failures' => 0,
                'staleness' => 0,
            ];
        }

        return [
            'ok' => $ok,
            'last_run' => $lastRun,
            'last_success' => $lastSuccess,
            'failures' => $failures,
            'staleness' => $staleness,
        ];
    }
}
