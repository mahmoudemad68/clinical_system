<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Health;

/**
 * Probes an optional downstream service's liveness.
 *
 * A port rather than a concrete HTTP client so readiness tests can simulate an
 * AI outage without a network, which is what makes gate G-02-04 a fast unit
 * test instead of a fragile integration one.
 *
 * Implementations must be strictly time-bounded. A probe that blocks makes the
 * readiness endpoint block, and an orchestrator reads a slow readiness response
 * as "not ready" and starts cycling healthy instances. A slow optional
 * dependency must degrade, never cascade.
 */
interface HealthProbeClient
{
    /**
     * True when the downstream reports alive within its deadline.
     *
     * Returns false rather than throwing for the ordinary "it is down" case.
     * Down is an expected state for an optional dependency, not an exception.
     */
    public function isLive(): bool;
}
