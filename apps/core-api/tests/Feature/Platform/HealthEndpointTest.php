<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gate G-02-02: health, readiness, version, correlation, and localization
 * exercised against the real HTTP stack.
 *
 * These boot the framework and go through the actual middleware chain, so they
 * cover the wiring that unit tests deliberately bypass: route registration,
 * middleware ordering, envelope shape, and header emission.
 */
final class HealthEndpointTest extends TestCase
{
    // -------------------------------------------------------- operational

    #[Test]
    public function liveness_reports_alive_without_touching_dependencies(): void
    {
        $response = $this->getJson('/live');

        $response->assertOk()
            ->assertJson(['status' => 'alive', 'service' => 'core-api'])
            ->assertJsonStructure(['status', 'service', 'version']);
    }

    #[Test]
    public function liveness_is_not_enveloped(): void
    {
        // Operational probes are consumed by an orchestrator, which should not
        // have to understand our response envelope to route traffic.
        $body = $this->getJson('/live')->json();

        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('request_id', $body);
    }

    #[Test]
    public function readiness_reports_named_checks(): void
    {
        $response = $this->getJson('/ready');

        // 200 or 503 are both valid outcomes depending on what is running; the
        // contract is the shape and the presence of named checks.
        $this->assertContains($response->getStatusCode(), [200, 503]);

        $response->assertJsonStructure([
            'status',
            'service',
            'version',
            'checks' => [['name', 'critical', 'status', 'duration_ms']],
        ]);

        $names = array_column($response->json('checks'), 'name');
        $this->assertContains('configuration', $names);
        $this->assertContains('postgresql', $names);
        $this->assertContains('ai_service', $names);
    }

    #[Test]
    public function the_ai_check_is_not_critical_by_default(): void
    {
        // Gate G-02-04 at the HTTP layer: whatever the AI service is doing, it
        // must be declared non-critical so it cannot unseat core readiness.
        $checks = collect($this->getJson('/ready')->json('checks'))
            ->firstWhere('name', 'ai_service');

        $this->assertNotNull($checks);
        $this->assertFalse($checks['critical'], 'AI must be optional for core readiness (ADR 0001).');
    }

    // --------------------------------------------------------------- meta

    #[Test]
    public function version_returns_the_envelope(): void
    {
        $this->getJson('/api/v1/meta/version')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['service', 'version', 'api_version', 'environment'],
                'meta',
                'errors',
                'request_id',
            ])
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('errors', []);
    }

    // ------------------------------------------------------------- health

    #[Test]
    public function health_returns_an_english_message_by_default(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'message', 'components' => ['core', 'realtime', 'ai'], 'version', 'server_time'],
                'meta',
                'errors',
                'request_id',
            ]);

        $this->assertMatchesRegularExpression('/[A-Za-z]/', $response->json('data.message'));
    }

    #[Test]
    public function health_returns_an_arabic_message_when_negotiated(): void
    {
        // Phase 00 end-to-end requirement: each client displays core health and
        // version in Arabic and English.
        $response = $this->withHeaders(['Accept-Language' => 'ar'])->getJson('/api/v1/health');

        $response->assertOk()->assertJsonPath('meta.locale', 'ar');

        $message = $response->json('data.message');
        $this->assertMatchesRegularExpression(
            '/\p{Arabic}/u',
            $message,
            'Arabic negotiation must produce an Arabic message, not a fallback.',
        );
    }

    #[Test]
    public function locale_negotiation_handles_quality_values_and_regional_tags(): void
    {
        $this->withHeaders(['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.8'])
            ->getJson('/api/v1/health')
            ->assertJsonPath('meta.locale', 'ar');

        $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->getJson('/api/v1/health')
            ->assertJsonPath('meta.locale', 'en');
    }

    #[Test]
    public function an_unsupported_or_hostile_language_header_falls_back_safely(): void
    {
        foreach (['zz', str_repeat('x', 500), '../../etc/passwd', ''] as $header) {
            $response = $this->withHeaders(['Accept-Language' => $header])->getJson('/api/v1/health');

            $response->assertOk();
            $this->assertContains(
                $response->json('meta.locale'),
                ['ar', 'en'],
                'Locale must always resolve to a supported tag.',
            );
        }
    }

    #[Test]
    public function the_response_declares_its_language_and_varies_on_it(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'ar'])->getJson('/api/v1/health');

        $response->assertHeader('Content-Language', 'ar');
        // Without Vary, a shared cache could serve an Arabic body to an
        // English client.
        $this->assertStringContainsString('Accept-Language', $response->headers->get('Vary') ?? '');
    }

    // ------------------------------------------------------- correlation

    #[Test]
    public function a_correlation_id_is_assigned_and_echoed(): void
    {
        $response = $this->getJson('/api/v1/health');

        $header = $response->headers->get('X-Request-Id');
        $body = $response->json('request_id');

        $this->assertNotNull($header);
        $this->assertSame($header, $body, 'Header and body request_id must agree.');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $header,
        );
    }

    #[Test]
    public function a_well_formed_client_correlation_id_is_adopted(): void
    {
        $supplied = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a99';

        $this->withHeaders(['X-Request-Id' => $supplied])
            ->getJson('/api/v1/health')
            ->assertHeader('X-Request-Id', $supplied);
    }

    #[Test]
    public function a_malformed_client_correlation_id_is_replaced_not_reflected(): void
    {
        // The value lands in logs, traces, and outbox rows. Reflecting
        // arbitrary client text would allow log injection and forged
        // correlation with another user's activity.
        $hostile = '<script>alert(1)</script>';

        $response = $this->withHeaders(['X-Request-Id' => $hostile])->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertNotSame($hostile, $response->headers->get('X-Request-Id'));
        $this->assertStringNotContainsString('script', (string) $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function two_requests_receive_distinct_correlation_ids(): void
    {
        // The HTTP-level companion to the Octane isolation unit test.
        $first = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');
        $second = $this->getJson('/api/v1/health')->headers->get('X-Request-Id');

        $this->assertNotSame($first, $second);
    }

    // ---------------------------------------------------------- security

    #[Test]
    public function secure_response_headers_are_present(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy') ?? '');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
    }

    #[Test]
    public function the_stack_is_not_advertised(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertNull($response->headers->get('X-Powered-By'));
    }

    #[Test]
    public function health_never_exposes_infrastructure_detail(): void
    {
        // A public health endpoint must not double as reconnaissance. Component
        // status is coarse; hostnames, ports, and dependency versions are not
        // part of the contract.
        $body = json_encode($this->getJson('/ready')->json(), JSON_THROW_ON_ERROR);

        foreach (['postgres:', '5432', 'redis:', '6379', 'password', 'clinic_app'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "Readiness leaked \"{$leak}\".");
        }
    }
}
