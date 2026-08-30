<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('exports alert family names without an envelope', function () {
    $response = $this->get('/metrics');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

    $body = $response->getContent();
    expect($body)->toBeString()
        ->and($body)->not->toContain('"data"')
        ->and($body)->toContain('clinic_readiness_status')
        ->and($body)->toContain('clinic_outbox_pending_total')
        ->and($body)->toContain('clinic_outbox_dead_letter_total')
        ->and($body)->toContain('clinic_db_connections_in_use')
        ->and($body)->toContain('clinic_db_connections_limit')
        ->and($body)->toContain('clinic_horizon_queue_depth')
        ->and($body)->toContain('clinic_db_query_duration_seconds_bucket')
        ->and($body)->toContain('clinic_audit_chain_verification_ok')
        ->and($body)->toContain('clinic_audit_chain_verification_last_success_timestamp_seconds')
        ->and($body)->toContain('clinic_audit_chain_verification_failures_total')
        ->and($body)->toContain('clinic_audit_chain_verification_staleness_seconds')
        ->and($body)->not->toContain('patient_id');
});

it('echoes a valid traceparent and drops a hostile one', function () {
    $valid = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    $this->withHeaders(['traceparent' => $valid])
        ->getJson('/api/v1/health')
        ->assertOk()
        ->assertHeader('traceresponse', $valid);

    $hostile = 'not-a-traceparent<script>';
    $response = $this->withHeaders(['traceparent' => $hostile])->getJson('/api/v1/health');

    $response->assertOk();
    expect($response->headers->get('traceresponse'))->toBeNull()
        ->and((string) $response->headers->get('traceresponse'))->not->toContain('script');
});
