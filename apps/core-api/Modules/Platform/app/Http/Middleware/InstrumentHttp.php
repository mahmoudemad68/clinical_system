<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Services\Telemetry\HttpInstrumentation;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a redacted HTTP span and Prometheus sample after the response.
 *
 * Runs after correlation assignment so the request_id is already a UUIDv7.
 * Attributes are bounded: method, route name, status class, request_id.
 * Free-text path segments never become labels.
 */
final class InstrumentHttp
{
    public function __construct(
        private readonly HttpInstrumentation $instrumentation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $traceparent = $this->adoptTraceparent($request);

        $response = $next($request);

        $seconds = (hrtime(true) - $started) / 1_000_000_000;
        $route = $request->route()?->getName() ?? 'unnamed';
        $status = $response->getStatusCode();
        $requestId = (string) $request->headers->get('X-Request-Id', '');

        $this->instrumentation->recordServerRequest(
            $request->getMethod(),
            $route,
            $status,
            $seconds,
            $requestId,
        );

        if ($traceparent !== null) {
            $response->headers->set('traceresponse', $traceparent);
        }

        return $response;
    }

    private function adoptTraceparent(Request $request): ?string
    {
        $header = $request->headers->get('traceparent');

        if (! is_string($header) || $header === '') {
            return null;
        }

        // W3C traceparent: version-traceid-spanid-flags. Anything else is
        // discarded rather than forwarded into telemetry.
        if (preg_match('/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/', $header) !== 1) {
            return null;
        }

        $request->attributes->set('traceparent', $header);

        return $header;
    }
}
