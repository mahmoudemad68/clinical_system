<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Contracts\IdempotencyStore;
use Modules\Platform\Enums\IdempotencyState;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Http\Responses\ErrorCode;
use Modules\Platform\Http\Responses\ErrorEnvelope;
use Modules\Platform\Services\Idempotency\CanonicalRequestHasher;
use Modules\Platform\Support\IdempotencyKey;
use Modules\Platform\Support\Identifier;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enforces the idempotency contract (phase file, "Idempotency contract").
 *
 * Applies to booking, check-in, consultation completion, prescription
 * finalization and amendment, purchase receipt, POS sale, cancellation,
 * return/refund, and external synchronization. Phase 00 exercises it on the
 * diagnostics slice so the mechanism is proven before a real invariant depends
 * on it.
 *
 * The decision table this implements:
 *
 *   no record                 -> claim, proceed
 *   same key, same hash, ok   -> replay the stored outcome (200)
 *   same key, different hash  -> 409 IDEMPOTENCY_KEY_REUSED
 *   same key, still running   -> 409 IDEMPOTENCY_IN_PROGRESS, never a second start
 *   same key, retryable fail  -> claim again, proceed
 *
 * Two rules are easy to get wrong and are called out at their call sites:
 * a permanent validation or authorization failure is never cached as a
 * successful business result, and the stored record holds a response
 * *reference*, never a response body.
 */
final class EnforceIdempotency
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly CanonicalRequestHasher $hasher,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);
        $header = $request->headers->get('Idempotency-Key');

        if (! is_string($header) || trim($header) === '') {
            return ErrorEnvelope::of(
                ErrorCode::ValidationFailed,
                $requestId,
                field: 'Idempotency-Key',
            );
        }

        try {
            $key = IdempotencyKey::scope(
                clientKey: $header,
                operationId: $this->operationId($request),
                // Server-owned actor identity. Phase 00 has no authenticated
                // user yet, so the synthetic token's fingerprint stands in.
                // A client-supplied actor here would defeat the whole scoping
                // mechanism, which is why it is derived, never read from input.
                actorKey: $this->actorKey($request),
            );
        } catch (InvalidValueObject $e) {
            return ErrorEnvelope::of(
                ErrorCode::ValidationFailed,
                $requestId,
                message: $e->getMessage(),
                field: 'Idempotency-Key',
            );
        }

        $requestHash = $this->hasher->hash(
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getContent(),
        );
        $existing = $this->store->claim($key, $requestHash);

        if ($existing !== null) {
            if (! $existing->matchesRequest($requestHash)) {
                // Same key, different intent. This is a client bug or an
                // attack; either way it must not replay someone else's result.
                return ErrorEnvelope::of(ErrorCode::IdempotencyKeyReused, $requestId);
            }

            if ($existing->state === IdempotencyState::Processing) {
                // A concurrent duplicate waits; it never starts a second
                // transition. Retry-After is a hint, not a promise.
                return ErrorEnvelope::of(
                    ErrorCode::IdempotencyInProgress,
                    $requestId,
                    headers: ['Retry-After' => '1'],
                );
            }

            if ($existing->state === IdempotencyState::Succeeded) {
                if ($this->replaysCredentialsViaHandler($request)) {
                    return $next($request);
                }

                return $this->replay($existing->statusCode, $existing->responseReference, $requestId);
            }

            // FailedRetryable: the previous attempt failed transiently and the
            // request is identical, so proceeding is correct.
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Unknown outcome. Release rather than record success, and rethrow
            // so the exception renderer produces the safe envelope.
            $this->store->release($key);

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $this->store->succeed($key, $status, $this->responseReference($response));
        } elseif ($status >= 500 || $status === 429) {
            // Transient. The same key with the same request may be retried.
            $this->store->failRetryable($key, $this->errorClassFor($status));
        } else {
            // 4xx other than 429: permanent validation or authorization
            // failure. It is not cached as success, and it is not offered as
            // retryable either — the caller must not be invited to retry
            // something that will always be refused.
            $this->store->release($key);
        }

        return $response;
    }

    private function replay(?int $status, ?string $reference, Identifier $requestId): Response
    {
        // The store holds a reference, not a body, so a replay re-reads the
        // outcome rather than serving a cached copy of clinical or financial
        // content from a table with different retention rules.
        $decoded = $reference === null ? null : json_decode($reference, true);
        $data = is_array($decoded) ? $this->expandReplayData($decoded) : $decoded;

        return response()->json(
            [
                'data' => $data,
                'meta' => (object) ['locale' => app()->getLocale(), 'idempotent_replay' => true],
                'errors' => [],
                'request_id' => $requestId->value,
            ],
            $status ?? 200,
            ['X-Request-Id' => $requestId->value, 'Idempotent-Replay' => 'true'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * A pointer to the outcome, bounded to the varchar(255) column.
     *
     * Credentials never belong here: access and refresh tokens are hashed in
     * Auth tables, not copied into idempotency_keys. Phase 00 diagnostics
     * payloads are small and synthetic, so they may be stored inline. Token-
     * issuing Auth responses store a session or challenge identifier and a
     * replay reconstructs metadata without repeating secrets.
     */
    private function responseReference(Response $response): string
    {
        $content = $response->getContent();

        if (! is_string($content)) {
            return '{"ref":"empty"}';
        }

        $decoded = json_decode($content, true);
        $data = is_array($decoded) && array_key_exists('data', $decoded) ? $decoded['data'] : null;

        if (! is_array($data)) {
            return '{"ref":"empty"}';
        }

        unset($data['access_token'], $data['refresh_token']);

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (is_string($encoded) && strlen($encoded) <= 255) {
            return $encoded;
        }

        $pointer = match (true) {
            isset($data['session_id']) => [
                'ref' => 'auth_session',
                'id' => $data['session_id'],
            ],
            isset($data['challenge_id']) => [
                'ref' => 'auth_challenge',
                'id' => $data['challenge_id'],
                'status' => $data['status'] ?? 'otp_required',
            ],
            isset($data['diagnostics_id']) => [
                'ref' => 'diagnostics',
                'id' => $data['diagnostics_id'],
            ],
            isset($data['profile']['patient_id']) => [
                'ref' => 'patient_profile',
                'id' => $data['profile']['patient_id'],
                'status' => $data['status'] ?? 'profile_ready',
            ],
            default => ['ref' => 'truncated'],
        };

        $compact = json_encode($pointer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($compact) && strlen($compact) <= 255 ? $compact : '{"ref":"truncated"}';
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function expandReplayData(array $decoded): array
    {
        return match ($decoded['ref'] ?? null) {
            'auth_session' => [
                'session_id' => $decoded['id'] ?? null,
                'tokens_replayed' => false,
            ],
            'auth_challenge' => [
                'challenge_id' => $decoded['id'] ?? null,
                'status' => $decoded['status'] ?? 'otp_required',
            ],
            'diagnostics' => [
                'diagnostics_id' => $decoded['id'] ?? null,
            ],
            'patient_profile' => [
                'status' => $decoded['status'] ?? 'profile_ready',
                'profile' => [
                    'patient_id' => $decoded['id'] ?? null,
                ],
            ],
            default => $decoded,
        };
    }

    /**
     * Refresh responses must not replay tokens from idempotency_keys. The
     * handler reconstructs the lost response from the device envelope.
     */
    private function replaysCredentialsViaHandler(Request $request): bool
    {
        return $request->is('api/v1/auth/token/refresh');
    }

    private function errorClassFor(int $status): string
    {
        // Stable, non-sensitive labels only. Never a provider message: those
        // carry payload fragments.
        return $status === 429 ? 'rate_limited' : 'dependency_failure';
    }

    private function operationId(Request $request): string
    {
        return $request->route()?->getName() ?? $request->getPathInfo();
    }

    private function actorKey(Request $request): string
    {
        $actor = $request->attributes->get('actor_id');
        if ($actor instanceof Identifier) {
            return $actor->value;
        }

        $token = $request->bearerToken();
        if ($token !== null && $token !== '') {
            return hash('sha256', $token);
        }

        $phone = $request->input('phone');
        if (is_string($phone) && $phone !== '') {
            return 'preauth-phone:'.hash('sha256', $phone);
        }

        return 'preauth:'.$request->getPathInfo();
    }

    private function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
