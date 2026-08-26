<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reverb private-channel authorization is deny-by-default (Phase 00 scaffold).
 */
final class BroadcastChannelDenyTest extends TestCase
{
    #[Test]
    public function broadcasting_auth_refuses_an_unauthenticated_subscriber(): void
    {
        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => 'private-platform.health',
        ]);

        // Unauthenticated subscription is refused. The envelope collapses 403
        // into 404 so the response cannot be used to probe whether a private
        // channel exists (ExceptionRenderer).
        $this->assertContains($response->status(), [401, 403, 404]);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('true', $body);
        $this->assertStringNotContainsString('"auth"', $body);
    }
}
