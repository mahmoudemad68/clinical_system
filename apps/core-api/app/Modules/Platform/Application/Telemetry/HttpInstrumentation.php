<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Telemetry;

/**
 * Records a bounded HTTP sample after the response.
 *
 * Owned by Application so Http middleware never imports Infrastructure
 * (deptrac: Http -> Application -> Domain).
 */
interface HttpInstrumentation
{
    public function recordServerRequest(
        string $method,
        string $route,
        int $status,
        float $seconds,
        string $requestId,
    ): void;
}
