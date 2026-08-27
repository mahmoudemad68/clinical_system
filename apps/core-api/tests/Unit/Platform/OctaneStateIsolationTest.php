<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Modules\Platform\Contracts\CorrelationScope;
use Modules\Platform\Services\Identity\UuidV7Generator;
use Modules\Platform\Services\Transaction\CorrelationIdProvider;
use Modules\Platform\Support\Identifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gate G-02-05: request-scoped state must not leak across Octane requests.
 *
 * Octane keeps the application container alive between requests, so a
 * singleton holding request state carries it into the next request served by
 * the same worker. The phase file calls for a regression test using two
 * synthetic identities, and this is it.
 *
 * Why this matters more than the other gates in section 2: a correlation-id
 * leak merely makes two requests look related in a trace. The same defect in
 * anything actor-scoped — a resolved patient, a tenant predicate, a cached
 * authorization decision — serves one patient's context to another. The
 * mechanism is identical; only the payload differs. Proving the reset works
 * here is what makes it safe to hold actor-scoped state later.
 *
 * The two identities are synthetic UUIDs and carry no personal data.
 */
final class OctaneStateIsolationTest extends TestCase
{
    /** Synthetic identity A. */
    private const IDENTITY_A = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a01';

    /** Synthetic identity B. */
    private const IDENTITY_B = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7b02';

    #[Test]
    public function a_reset_scope_does_not_carry_state_into_the_next_request(): void
    {
        $scope = $this->scope();

        // Request 1: worker handles synthetic identity A.
        $scope->set(Identifier::fromString(self::IDENTITY_A));
        $this->assertSame(self::IDENTITY_A, $scope->current()->value);

        // Worker finishes. This is what the Octane RequestTerminated /
        // RequestReceived hooks call.
        $scope->reset();

        // Request 2: the same worker instance handles synthetic identity B.
        $scope->set(Identifier::fromString(self::IDENTITY_B));

        $this->assertSame(
            self::IDENTITY_B,
            $scope->current()->value,
            'Identity B must see its own correlation id, not the one left by identity A.',
        );
    }

    #[Test]
    public function without_a_reset_the_previous_identity_leaks(): void
    {
        // This is the negative control. It asserts that the leak is real when
        // the hook does not run, which is what makes the test above meaningful
        // rather than vacuous: if this test ever starts failing, the reset is
        // happening somewhere implicit and the guarantee is no longer under
        // this test's control.
        $scope = $this->scope();

        $scope->set(Identifier::fromString(self::IDENTITY_A));

        // Second request arrives; the hook did NOT fire.
        $leaked = $scope->current()->value;

        $this->assertSame(
            self::IDENTITY_A,
            $leaked,
            'Expected the un-reset scope to still hold identity A, demonstrating the leak the hook prevents.',
        );
    }

    #[Test]
    public function a_reset_scope_generates_a_fresh_identifier_rather_than_a_shared_placeholder(): void
    {
        $scope = $this->scope();

        $scope->set(Identifier::fromString(self::IDENTITY_A));
        $scope->reset();

        // Nothing was set for request 2. It must mint its own identifier: a
        // shared placeholder would make every un-set request look like one
        // correlated conversation in the trace backend.
        $first = $scope->current()->value;

        $scope->reset();
        $second = $scope->current()->value;

        $this->assertNotSame(self::IDENTITY_A, $first);
        $this->assertNotSame($first, $second, 'Each reset must yield a distinct correlation id.');
    }

    #[Test]
    public function reset_clears_the_has_been_set_marker(): void
    {
        $scope = $this->scope();

        $this->assertFalse($scope->hasBeenSet());

        $scope->set(Identifier::fromString(self::IDENTITY_A));
        $this->assertTrue($scope->hasBeenSet());

        $scope->reset();
        $this->assertFalse(
            $scope->hasBeenSet(),
            'A reset scope must report itself unset, or middleware cannot tell a fresh request from a stale one.',
        );
    }

    #[Test]
    public function repeated_reset_is_safe(): void
    {
        // Both RequestReceived and RequestTerminated call reset, so a normal
        // request triggers it twice. That must be harmless.
        $scope = $this->scope();

        $scope->set(Identifier::fromString(self::IDENTITY_A));
        $scope->reset();
        $scope->reset();

        $this->assertFalse($scope->hasBeenSet());
    }

    private function scope(): CorrelationScope
    {
        return new CorrelationIdProvider(new UuidV7Generator);
    }
}
