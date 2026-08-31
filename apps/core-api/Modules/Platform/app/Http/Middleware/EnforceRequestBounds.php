<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Http\Responses\ErrorCode;
use Modules\Platform\Http\Responses\ErrorEnvelope;
use Modules\Platform\Support\Identifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict request and content-size limits, and safe JSON parsing.
 *
 * A mandatory Phase 00 control. The gateway enforces a coarse limit; this is
 * the application-side backstop, because the gateway is not the only path into
 * the process (a worker replaying a payload, a test client, a misconfigured
 * proxy).
 *
 * Depth bounding is the part that is easy to overlook. A deeply nested JSON
 * document is small on the wire and expensive to parse: `{"a":{"a":{"a":…` a
 * few thousand levels deep is a denial of service that a byte-size limit does
 * not catch. PHP's own depth limit throws, so this sets a lower explicit bound
 * and answers with the stable envelope rather than a 500.
 */
final class EnforceRequestBounds
{
    public function __construct(
        private readonly int $maxBodyBytes,
        private readonly int $maxJsonDepth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);

        $declaredLength = $request->headers->get('Content-Length');

        if (is_numeric($declaredLength) && (int) $declaredLength > $this->maxBodyBytes) {
            return ErrorEnvelope::of(ErrorCode::RequestTooLarge, $requestId, meta: [
                'max_bytes' => $this->maxBodyBytes,
            ]);
        }

        if ($request->getContent() !== '' && strlen($request->getContent()) > $this->maxBodyBytes) {
            // Content-Length can be absent or dishonest under chunked transfer.
            return ErrorEnvelope::of(ErrorCode::RequestTooLarge, $requestId, meta: [
                'max_bytes' => $this->maxBodyBytes,
            ]);
        }

        if ($this->expectsJsonBody($request)) {
            $contentType = $request->headers->get('Content-Type', '');

            if (! str_contains(strtolower($contentType), 'application/json')) {
                return ErrorEnvelope::of(ErrorCode::UnsupportedMediaType, $requestId);
            }

            $body = $request->getContent();

            if ($body !== '') {
                try {
                    json_decode($body, true, $this->maxJsonDepth, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // One code for both malformed syntax and excessive depth.
                    // Distinguishing them would tell a prober exactly which
                    // bound they hit, which helps them and helps no one else.
                    return ErrorEnvelope::of(ErrorCode::MalformedRequest, $requestId);
                }
            }
        }

        return $next($request);
    }

    private function expectsJsonBody(Request $request): bool
    {
        return in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true);
    }

    private function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        // AssignCorrelationId runs first, so this is defensive. If the ordering
        // ever changes, a response without a request_id would be worse than an
        // extra identifier.
        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
