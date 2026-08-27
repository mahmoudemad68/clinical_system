<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Health;

/**
 * One readiness check, owned by the Application layer and implemented in
 * Infrastructure.
 *
 * ReadinessProbe originally reached for Illuminate\Database\ConnectionInterface
 * and the Redis factory directly, which put framework types in the Application
 * layer and made readiness untestable without a container. deptrac flagged it.
 *
 * Inverting it here has a second benefit: adding a dependency to the readiness
 * set becomes a registration rather than an edit to the probe, so the probe's
 * critical/optional logic stays in one place and is never re-derived.
 */
interface DependencyCheck
{
    /**
     * Stable check name from a small fixed set.
     *
     * Bounded on purpose: this value is safe to use as a metric label, and the
     * phase file forbids unbounded label cardinality.
     */
    public function name(): string;

    /**
     * Does a failure here mean the process cannot serve?
     *
     * False for the AI service and Qdrant. An optional dependency reports
     * degraded and the process stays ready; wiring every dependency in as
     * critical is how one optional provider takes down a platform.
     */
    public function isCritical(): bool;

    /**
     * Run the check. Must be time-bounded and must not throw.
     *
     * A check that throws or blocks makes the readiness endpoint itself fail,
     * and an orchestrator reads that as "not ready" and starts cycling healthy
     * instances.
     */
    public function run(): CheckStatus;
}
