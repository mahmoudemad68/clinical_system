<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Domain\Contracts\Clock;
use App\Modules\Platform\Domain\Contracts\IdempotencyStore;
use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;
use App\Modules\Platform\Domain\ValueObjects\IdempotencyKey;
use App\Modules\Platform\Domain\ValueObjects\IdempotencyState;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use App\Modules\Platform\Http\Responses\ErrorCode;
use App\Modules\Platform\Http\Responses\ErrorEnvelope;
use Closure;
use Illuminate\Http\Request;
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
        private readonly Clock $clock,
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

        $requestHash = $this->canonicalRequestHash($request);
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

    /**
     * Canonical hash of the request's intent.
     *
     * Covers method, path, and body. Deliberately excludes headers and query
     * ordering noise: two byte-identical intents must hash the same even if a
     * proxy added a header on the retry.
     */
    private function canonicalRequestHash(Request $request): string
    {
        $body = $request->getContent();

        // Decode and re-encode with sorted keys so JSON key ordering does not
        // change the hash. A client library that reorders keys between attempts
        // would otherwise turn a legitimate retry into a 409.
        $decoded = json_decode($body === '' ? '{}' : $body, true);
        if (is_array($decoded)) {
            $this->ksortRecursive($decoded);
            $body = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return hash('sha256', implode("\0", [
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $body,
        ]));
    }

    /**
     * @param  array<array-key, mixed>  $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    private function replay(?int $status, ?string $reference, Identifier $requestId): Response
    {
        // The store holds a reference, not a body, so a replay re-reads the
        // outcome rather than serving a cached copy of clinical or financial
        // content from a table with different retention rules.
        return response()->json(
            [
                'data' => $reference === null ? null : json_decode($reference, true),
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
     * A pointer to the outcome, bounded in size.
     *
     * For the Phase 00 slice the result is small, synthetic, and contains no
     * personal data, so the payload itself is the reference. A later operation
     * whose result carries clinical or financial content must store an
     * identifier here and re-read the record instead.
     */
    private function responseReference(Response $response): string
    {
        $content = $response->getContent();

        if (! is_string($content)) {
            return '';
        }

        $decoded = json_decode($content, true);
        $data = is_array($decoded) && array_key_exists('data', $decoded) ? $decoded['data'] : null;

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Hard bound. A large body here would turn the idempotency table into
        // a second, unmanaged copy of the response.
        return is_string($encoded) && strlen($encoded) <= 4096 ? $encoded : '';
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
        $token = $request->bearerToken();

        return $token === null ? 'anonymous' : hash('sha256', $token);
    }

    private function requestId(Request $request): Identifier
    {
        $assigned = $request->attributes->get('correlation_id');

        return $assigned instanceof Identifier
            ? $assigned
            : Identifier::fromTrusted('00000000-0000-7000-8000-000000000000');
    }
}
