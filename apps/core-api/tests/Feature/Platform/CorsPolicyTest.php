<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No wildcard CORS (mandatory Phase 00 control).
 */
final class CorsPolicyTest extends TestCase
{
    #[Test]
    public function configured_origins_never_include_a_wildcard(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertIsArray($origins);
        $this->assertNotContains('*', $origins);
        $this->assertSame([], config('cors.allowed_origins_patterns'));
    }

    #[Test]
    public function an_unlisted_origin_does_not_receive_allow_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'https://evil.invalid',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $acao = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotSame('*', $acao);
        $this->assertNotSame('https://evil.invalid', $acao);
    }

    #[Test]
    public function an_enumerated_local_origin_is_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertSame('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
