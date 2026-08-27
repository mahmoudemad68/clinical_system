<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Platform\Application\Idempotency\CanonicalRequestHasher;
use App\Modules\Platform\Application\Outbox\RetryPolicy;
use App\Modules\Platform\Http\Responses\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 00 unit oracles: hashing, retry schedule, and error mapping.
 */
final class RequestHashAndRetryTest extends TestCase
{
    #[Test]
    public function json_key_order_does_not_change_the_canonical_hash(): void
    {
        $hasher = new CanonicalRequestHasher;

        $first = $hasher->hash('POST', '/api/v1/diagnostics/round-trip', '{"label":"a","echo_delay_ms":5}');
        $second = $hasher->hash('POST', '/api/v1/diagnostics/round-trip', '{"echo_delay_ms":5,"label":"a"}');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    #[Test]
    public function a_different_body_produces_a_different_hash(): void
    {
        $hasher = new CanonicalRequestHasher;

        $this->assertNotSame(
            $hasher->hash('POST', '/x', '{"label":"a"}'),
            $hasher->hash('POST', '/x', '{"label":"b"}'),
        );
    }

    #[Test]
    public function secret_fields_do_not_change_the_canonical_hash(): void
    {
        $hasher = new CanonicalRequestHasher;

        $this->assertSame(
            $hasher->hash('POST', '/api/v1/auth/login', '{"phone":"1","password":"alpha-secret"}'),
            $hasher->hash('POST', '/api/v1/auth/login', '{"phone":"1","password":"beta-secret"}'),
        );
        $this->assertSame(
            $hasher->hash('POST', '/api/v1/auth/token/refresh', '{"refresh_token":"one"}'),
            $hasher->hash('POST', '/api/v1/auth/token/refresh', '{"refresh_token":"two"}'),
        );
    }

    #[Test]
    public function method_is_normalized_so_case_does_not_split_retries(): void
    {
        $hasher = new CanonicalRequestHasher;

        $this->assertSame(
            $hasher->hash('post', '/x', '{}'),
            $hasher->hash('POST', '/x', '{}'),
        );
    }

    #[Test]
    public function retry_is_capped_and_uses_the_injected_randomizer(): void
    {
        $policy = new RetryPolicy(baseSeconds: 2, maxSeconds: 16, maxAttempts: 4);

        $this->assertTrue($policy->shouldRetry(0));
        $this->assertTrue($policy->shouldRetry(3));
        $this->assertFalse($policy->shouldRetry(4));
        $this->assertSame(4, $policy->maxAttempts());

        $delay = $policy->delayFor(10, static fn (int $min, int $max): int => $max);

        $this->assertSame(16, $delay);
        $this->assertLessThanOrEqual(16, $delay);
    }

    #[Test]
    public function error_codes_map_to_the_phase_00_status_table(): void
    {
        $this->assertSame(400, ErrorCode::MalformedRequest->httpStatus());
        $this->assertSame(401, ErrorCode::Unauthenticated->httpStatus());
        $this->assertSame(403, ErrorCode::PermissionDenied->httpStatus());
        $this->assertSame(404, ErrorCode::NotFound->httpStatus());
        $this->assertSame(409, ErrorCode::IdempotencyKeyReused->httpStatus());
        $this->assertSame(409, ErrorCode::IdempotencyInProgress->httpStatus());
        $this->assertSame(422, ErrorCode::ValidationFailed->httpStatus());
        $this->assertSame(429, ErrorCode::RateLimited->httpStatus());
        $this->assertSame(503, ErrorCode::DependencyUnavailable->httpStatus());
        $this->assertSame(500, ErrorCode::InternalError->httpStatus());
        $this->assertTrue(ErrorCode::InternalError->isServerFault());
        $this->assertFalse(ErrorCode::NotFound->isServerFault());
    }
}
