<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Services\Pagination\HmacCursorSigner;
use Modules\Platform\Support\CursorScope;
use Modules\Platform\Support\IdempotencyKey;
use Modules\Platform\Support\PaginationCursor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pagination cursor signing and idempotency-key scoping (G-04-03).
 */
final class CursorAndIdempotencyKeyTest extends TestCase
{
    #[Test]
    public function a_signed_cursor_round_trips_inside_its_scope(): void
    {
        $signer = new HmacCursorSigner('test-cursor-secret-not-a-production-key');
        $scope = CursorScope::of('appointments.list', 'actor-a', 'tenant-1', ['status' => 'open'], ['id']);
        $cursor = PaginationCursor::forScope($scope, ['id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01']);

        $token = $signer->encode($cursor);

        $this->assertLessThanOrEqual(PaginationCursor::MAX_ENCODED_LENGTH, strlen($token));
        $this->assertStringNotContainsString('actor-a', $token);

        $decoded = $signer->decode($token, $scope);
        $this->assertSame($cursor->toPayload(), $decoded->toPayload());
    }

    #[Test]
    public function a_cursor_from_another_actor_is_rejected(): void
    {
        $signer = new HmacCursorSigner('test-cursor-secret-not-a-production-key');
        $scope = CursorScope::of('appointments.list', 'actor-a', null, [], ['id']);
        $token = $signer->encode(PaginationCursor::forScope($scope, ['id' => '1']));

        $this->expectException(InvalidValueObject::class);

        $signer->decode($token, CursorScope::of('appointments.list', 'actor-b', null, [], ['id']));
    }

    #[Test]
    public function a_tampered_cursor_is_rejected(): void
    {
        $signer = new HmacCursorSigner('test-cursor-secret-not-a-production-key');
        $scope = CursorScope::of('x', 'a', null, [], ['id']);
        $token = $signer->encode(PaginationCursor::forScope($scope, ['id' => '1']));
        $tampered = substr($token, 0, -2).'aa';

        $this->expectException(InvalidValueObject::class);

        $signer->decode($tampered, $scope);
    }

    #[Test]
    public function idempotency_keys_are_scoped_to_actor_and_operation(): void
    {
        $sameClient = 'client-key-aaaaaaa1';

        $a = IdempotencyKey::scope($sameClient, 'op.book', 'actor-1');
        $b = IdempotencyKey::scope($sameClient, 'op.book', 'actor-2');
        $c = IdempotencyKey::scope($sameClient, 'op.cancel', 'actor-1');
        $again = IdempotencyKey::scope($sameClient, 'op.book', 'actor-1');

        $this->assertFalse($a->equals($b), 'The same client key must not collide across actors.');
        $this->assertFalse($a->equals($c), 'The same client key must not collide across operations.');
        $this->assertTrue($a->equals($again));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->storageKey);
    }

    #[Test]
    public function a_short_or_hostile_client_key_is_rejected_without_echoing_it(): void
    {
        try {
            IdempotencyKey::scope('short', 'op.book', 'actor-1');
            $this->fail('Expected InvalidValueObject.');
        } catch (InvalidValueObject $e) {
            $this->assertStringNotContainsString('short', $e->getMessage());
        }
    }
}
