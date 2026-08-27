<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Health;

/**
 * Evaluates whether this process can serve traffic.
 *
 * The critical/optional split is the whole design (Phase 00 §2.3-2.4):
 *
 *   critical  configuration, PostgreSQL, the Redis connections the request path
 *             needs. A failure here means this process cannot serve correctly,
 *             so it must leave the load balancer pool.
 *
 *   optional  the AI service, Qdrant, and anything else the core does not need.
 *             A failure reports `degraded` and the process stays ready. An AI
 *             outage is not a core outage (plan.md section 141), and wiring
 *             every dependency into readiness is how one optional provider
 *             takes down a whole platform.
 *
 * The probe holds no framework type. Each check is a DependencyCheck
 * implemented in Infrastructure, so this class is a pure unit test subject and
 * gate G-02-04 can be proven without a network or a container.
 */
final class ReadinessProbe
{
    /**
     * @param  list<DependencyCheck>  $checks
     */
    public function __construct(
        private readonly array $checks,
        private readonly string $version,
        private readonly string $service = 'core-api',
    ) {}

    public function evaluate(): ReadinessResult
    {
        $results = [];
        $ready = true;

        foreach ($this->checks as $check) {
            $startedAt = hrtime(true);
            $status = $check->run();
            $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

            $results[] = new ReadinessCheck(
                $check->name(),
                $check->isCritical(),
                $status,
                $durationMs,
            );

            // Only a critical failure unseats readiness. Degraded never does,
            // whether the check is critical or not: degraded means "reduced",
            // and a reduced optional dependency is the normal state this
            // design is built to tolerate.
            if ($check->isCritical() && $status === CheckStatus::Fail) {
                $ready = false;
            }
        }

        return new ReadinessResult($ready, $this->service, $this->version, $results);
    }
}
