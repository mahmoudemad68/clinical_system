<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Http\Responses\ErrorCode;
use App\Modules\Platform\Http\Responses\ErrorEnvelope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Phase 00 foundation slice.
 *
 * Two independent conditions, both of which must hold:
 *
 *   1. the server-owned feature flag is on;
 *   2. the process is running in an allow-listed environment.
 *
 * The environment allow-list is not redundant with the flag. A flag is a value
 * someone can set by mistake in the wrong place; the allow-list means that
 * mistake still cannot expose a synthetic write endpoint in production.
 *
 * A blocked request answers 404, not 403. A 403 confirms the route exists,
 * which is exactly the disclosure the phase file's "hiding resource existence
 * is safer" rule exists to prevent.
 */
final class RequireDiagnosticsSlice
{
    /**
     * @param list<string> $allowedEnvironments
     */
    public function __construct(
        private readonly bool $flagEnabled,
        private readonly string $environment,
        private readonly array $allowedEnvironments,
        private readonly string $expectedToken,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);

        if (!$this->flagEnabled || !in_array($this->environment, $this->allowedEnvironments, true)) {
            return ErrorEnvelope::of(ErrorCode::NotFound, $requestId);
        }

        if (!$this->tokenMatches($request)) {
            return ErrorEnvelope::of(ErrorCode::Unauthenticated, $requestId);
        }

        return $next($request);
    }

    /**
     * Synthetic bearer token for the Phase 00 slice only.
     *
     * Real authentication, device binding, and MFA are Phase 01. This exists so
     * the slice is not anonymous, and it is compared with hash_equals so the
     * comparison cannot be timed. An empty configured token denies everything
     * rather than accepting everything, which is the fail-closed direction.
     */
    private function tokenMatches(Request $request): bool
    {
        if ($this->expectedToken === '') {
            return false;
        }

        $presented = $request->bearerToken();

        return is_string($presented) && hash_equals($this->expectedToken, $presented);
    }

    private function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
