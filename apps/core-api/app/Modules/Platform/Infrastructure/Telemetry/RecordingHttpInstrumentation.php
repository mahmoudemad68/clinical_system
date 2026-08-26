<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Telemetry;

use App\Modules\Platform\Application\Telemetry\HttpInstrumentation;

/**
 * Writes a redacted HTTP span plus a Prometheus sample for one request.
 */
final class RecordingHttpInstrumentation implements HttpInstrumentation
{
    public function __construct(
        private readonly TelemetryGateway $telemetry,
        private readonly PlatformMetrics $metrics,
    ) {}

    public function recordServerRequest(
        string $method,
        string $route,
        int $status,
        float $seconds,
        string $requestId,
    ): void {
        $this->telemetry->startHttpSpan('http.server', [
            'method' => $method,
            'route' => $route,
            'status' => (string) $status,
            'status_class' => ((int) floor($status / 100)).'xx',
            'request_id' => $requestId,
        ]);

        $this->metrics->recordHttp($method, $route, $status, $seconds);
    }
}
