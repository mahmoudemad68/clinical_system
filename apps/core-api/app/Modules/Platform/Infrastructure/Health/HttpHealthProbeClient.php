<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Health;

use App\Modules\Platform\Application\Health\HealthProbeClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * HTTP liveness probe for an optional downstream service.
 *
 * Two properties matter more than the transport:
 *
 *   1. A hard, short timeout. This runs inside the readiness path, so a slow
 *      downstream must not make this process look unready.
 *   2. A short-lived negative cache. Readiness is polled every few seconds by
 *      the orchestrator; without caching, an AI outage would generate a steady
 *      stream of doomed connections from every core instance, adding load to a
 *      service that is already struggling.
 */
final class HttpHealthProbeClient implements HealthProbeClient
{
    private ?bool $cachedResult = null;

    private float $cachedAt = 0.0;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly int $timeoutMs = 2000,
        private readonly float $cacheSeconds = 5.0,
    ) {}

    public function isLive(): bool
    {
        $now = microtime(true);

        if ($this->cachedResult !== null && ($now - $this->cachedAt) < $this->cacheSeconds) {
            return $this->cachedResult;
        }

        $result = $this->probe();

        $this->cachedResult = $result;
        $this->cachedAt = $now;

        return $result;
    }

    private function probe(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }

        try {
            $seconds = max(0.1, $this->timeoutMs / 1000);

            $response = $this->http
                ->timeout($seconds)
                ->connectTimeout($seconds)
                // No retry. This is a health probe: a failed attempt is the
                // answer, and retrying inside the readiness path multiplies
                // the time budget by the retry count.
                ->withHeaders(['Accept' => 'application/json'])
                ->get(rtrim($this->baseUrl, '/').'/live');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
