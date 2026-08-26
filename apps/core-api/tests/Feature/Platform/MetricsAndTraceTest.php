<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prometheus exposition and traceparent adoption (G-07-01, G-07-02).
 */
final class MetricsAndTraceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function metrics_export_the_alert_family_names_without_an_envelope(): void
    {
        $response = $this->get('/metrics');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('"data"', $body);
        $this->assertStringContainsString('clinic_readiness_status', $body);
        $this->assertStringContainsString('clinic_outbox_pending_total', $body);
        $this->assertStringContainsString('clinic_outbox_dead_letter_total', $body);
        $this->assertStringContainsString('clinic_db_connections_in_use', $body);
        $this->assertStringContainsString('clinic_db_connections_limit', $body);
        $this->assertStringContainsString('clinic_horizon_queue_depth', $body);
        $this->assertStringContainsString('clinic_db_query_duration_seconds_bucket', $body);
        $this->assertStringNotContainsString('patient_id', $body);
    }

    #[Test]
    public function a_valid_traceparent_is_echoed_and_a_hostile_one_is_dropped(): void
    {
        $valid = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

        $this->withHeaders(['traceparent' => $valid])
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('traceresponse', $valid);

        $hostile = 'not-a-traceparent<script>';

        $response = $this->withHeaders(['traceparent' => $hostile])->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertNull($response->headers->get('traceresponse'));
        $this->assertStringNotContainsString('script', (string) $response->headers->get('traceresponse'));
    }
}
