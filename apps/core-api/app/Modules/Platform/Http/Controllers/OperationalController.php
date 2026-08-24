<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Health\ReadinessProbe;
use Illuminate\Http\JsonResponse;

/**
 * Unversioned operational probes for the orchestrator and load balancer.
 *
 * Deliberately outside /api/v1 and outside the envelope: these are not part of
 * the client contract, and an orchestrator should not have to understand our
 * response shape to decide whether to route traffic here.
 *
 * They must not be exposed through the public gateway route.
 */
final class OperationalController
{
    public function __construct(
        private readonly ReadinessProbe $readiness,
        private readonly string $version,
    ) {
    }

    /**
     * Liveness: is this process alive?
     *
     * Checks nothing else, on purpose. A liveness probe that touches the
     * database restarts every healthy instance during a database blip, turning
     * a brief dependency problem into a full outage.
     */
    public function live(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'alive',
            'service' => 'core-api',
            'version' => $this->version,
        ]);
    }

    /**
     * Readiness: can this process serve traffic?
     *
     * Critical dependencies fail the probe. Optional ones (AI, Qdrant) report
     * degraded and leave the process ready.
     */
    public function ready(): JsonResponse
    {
        $result = $this->readiness->evaluate();

        return new JsonResponse($result->toArray(), $result->httpStatus());
    }
}
