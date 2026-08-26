<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Application\Idempotency\CanonicalRequestHasher;
use App\Modules\Platform\Domain\ValueObjects\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 00 observable outcome, end to end.
 *
 * Proves that an authenticated request travels through the API, the database
 * adapter, and the outbox in one transaction, and returns immediately. Also
 * proves the gates in front of it: feature flag, environment allow-list,
 * synthetic token, request bounds, validation, and idempotency.
 *
 * Requires PostgreSQL: the migrations use jsonb, partial indexes, and regex
 * CHECK constraints that SQLite cannot express, and the constraints are a
 * material part of what is being tested.
 */
final class DiagnosticsSliceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'phase00-synthetic-diagnostics-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('platform.features.diagnostics_slice', true);
        config()->set('platform.diagnostics_environments', ['testing', 'local', 'development']);
        config()->set('platform.diagnostics_slice_token', self::TOKEN);
        putenv('DIAGNOSTICS_SLICE_TOKEN='.self::TOKEN);
    }

    // -------------------------------------------------------- happy path

    #[Test]
    public function it_commits_the_record_and_its_outbox_row_atomically(): void
    {
        $response = $this->postJson(
            '/api/v1/diagnostics/round-trip',
            ['label' => 'smoke-run-1'],
            $this->headers('key-atomic-commit-0001'),
        );

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['diagnostics_id', 'outbox_event_id', 'committed_at', 'idempotent_replay'],
                'meta',
                'errors',
                'request_id',
            ])
            ->assertJsonPath('data.idempotent_replay', false);

        $diagnosticsId = $response->json('data.diagnostics_id');
        $outboxEventId = $response->json('data.outbox_event_id');

        // Both rows exist and are linked. The foreign key was set inside the
        // transaction, so there is no window where one exists without the other.
        $row = DB::table('platform_diagnostics')->where('id', $diagnosticsId)->first();
        $this->assertNotNull($row);
        $this->assertSame($outboxEventId, $row->outbox_event_id);

        $event = DB::table('outbox_events')->where('event_id', $outboxEventId)->first();
        $this->assertNotNull($event);
        $this->assertSame('platform.diagnostics_round_trip_recorded', $event->event_type);
        $this->assertSame('PENDING', $event->status);
        $this->assertSame('internal', $event->classification);

        // The request did not wait for the worker.
        $this->assertNull($event->processed_at);
        $this->assertSame(0, (int) $event->attempts);
    }

    #[Test]
    public function the_outbox_row_carries_the_request_correlation_id(): void
    {
        $correlationId = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01';

        $response = $this->postJson(
            '/api/v1/diagnostics/round-trip',
            ['label' => 'correlated-run'],
            $this->headers('key-correlation-0001') + ['X-Request-Id' => $correlationId],
        );

        $event = DB::table('outbox_events')
            ->where('event_id', $response->json('data.outbox_event_id'))
            ->first();

        // An effect must be traceable to its cause.
        $this->assertSame($correlationId, $event->correlation_id);
    }

    #[Test]
    public function the_event_payload_carries_no_personal_data(): void
    {
        $response = $this->postJson(
            '/api/v1/diagnostics/round-trip',
            ['label' => 'payload-check'],
            $this->headers('key-payload-check-001'),
        );

        $payload = DB::table('outbox_events')
            ->where('event_id', $response->json('data.outbox_event_id'))
            ->value('payload');

        $decoded = json_decode((string) $payload, true);

        // Events carry identifiers and minimal facts, never a record.
        $this->assertSame(
            ['diagnostics_id', 'echo_delay_ms', 'label', 'recorded_at'],
            collect(array_keys($decoded))->sort()->values()->all(),
        );
    }

    // ------------------------------------------------------- idempotency

    #[Test]
    public function the_same_key_with_the_same_body_replays_the_original_outcome(): void
    {
        $headers = $this->headers('key-replay-identical-01');
        $body = ['label' => 'replay-me'];

        $first = $this->postJson('/api/v1/diagnostics/round-trip', $body, $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/diagnostics/round-trip', $body, $headers);

        // A replay returns the ORIGINAL outcome, status code included, so a
        // retrying client receives exactly what it would have received had the
        // first response arrived. The replay is signalled by the header, not by
        // a different status; branching on the status would be wrong.
        $second->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('meta.idempotent_replay', true);

        // The decisive assertion: one effect, not two.
        $this->assertSame(1, DB::table('platform_diagnostics')->count());
        $this->assertSame(1, DB::table('outbox_events')->count());
        $this->assertSame(
            $first->json('data.diagnostics_id'),
            $second->json('data.diagnostics_id'),
        );
    }

    #[Test]
    public function key_ordering_in_the_body_does_not_break_a_legitimate_retry(): void
    {
        // A client library that reorders JSON keys between attempts must not
        // turn a legitimate retry into a 409.
        $headers = $this->headers('key-ordering-stable-1');

        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'ordered', 'echo_delay_ms' => 5], $headers)
            ->assertCreated();

        $this->postJson('/api/v1/diagnostics/round-trip', ['echo_delay_ms' => 5, 'label' => 'ordered'], $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, DB::table('platform_diagnostics')->count());
    }

    #[Test]
    public function the_same_key_with_a_different_body_is_rejected(): void
    {
        $headers = $this->headers('key-different-body-1');

        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'original'], $headers)->assertCreated();

        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'different'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'IDEMPOTENCY_KEY_REUSED');

        // The second intent must not have taken effect.
        $this->assertSame(1, DB::table('platform_diagnostics')->count());
    }

    #[Test]
    public function a_missing_idempotency_key_is_rejected(): void
    {
        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'no-key'], [
            'Authorization' => 'Bearer '.self::TOKEN,
            'Accept' => 'application/json',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('platform_diagnostics')->count());
    }

    #[Test]
    public function a_permanent_validation_failure_is_not_cached_as_success(): void
    {
        $headers = $this->headers('key-not-cached-fail1');

        // A label that violates the slug rule.
        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'Not A Valid Label'], $headers)
            ->assertStatus(422);

        // The key must be free for a corrected request. Caching the failure as
        // an outcome would permanently block a client that simply typoed once.
        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'corrected-label'], $headers)
            ->assertCreated();
    }

    // ------------------------------------------------------------- gates

    #[Test]
    public function the_route_is_hidden_when_the_feature_flag_is_off(): void
    {
        config()->set('platform.features.diagnostics_slice', false);

        // 404, not 403: a 403 confirms the route exists.
        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'flag-off'], $this->headers('key-flag-off-000001'))
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'NOT_FOUND');
    }

    #[Test]
    public function the_route_is_hidden_outside_allowed_environments(): void
    {
        // Even with the flag on. The allow-list means a mis-set flag in
        // production still cannot expose a synthetic write endpoint.
        config()->set('platform.diagnostics_environments', ['local']);

        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'wrong-env'], $this->headers('key-wrong-env-00001'))
            ->assertNotFound();
    }

    #[Test]
    public function an_absent_or_wrong_token_is_rejected(): void
    {
        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'no-token'], [
            'Idempotency-Key' => 'key-no-token-0000001',
            'Accept' => 'application/json',
        ])->assertStatus(401);

        $this->postJson('/api/v1/diagnostics/round-trip', ['label' => 'bad-token'], [
            'Authorization' => 'Bearer wrong-token-entirely',
            'Idempotency-Key' => 'key-bad-token-000001',
            'Accept' => 'application/json',
        ])->assertStatus(401);

        $this->assertSame(0, DB::table('platform_diagnostics')->count());
    }

    // -------------------------------------------------------- validation

    #[Test]
    public function it_rejects_a_label_that_could_carry_an_identifier(): void
    {
        // Defence in depth with the database CHECK constraints. A national ID
        // (14 digits) or mobile number (11) must not ride along in a label.
        foreach (['run-29999999999999', 'x01099999999', 'has spaces here', 'UPPERCASE'] as $i => $label) {
            $this->postJson(
                '/api/v1/diagnostics/round-trip',
                ['label' => $label],
                $this->headers(sprintf('key-bad-label-%07d', $i)),
            )->assertStatus(422);
        }

        $this->assertSame(0, DB::table('platform_diagnostics')->count());
    }

    #[Test]
    public function it_rejects_unknown_properties(): void
    {
        // additionalProperties:false in the contract. Silently dropping an
        // unexpected field would let a caller believe it took effect.
        $this->postJson(
            '/api/v1/diagnostics/round-trip',
            ['label' => 'valid-label', 'national_id' => '29999999999999'],
            $this->headers('key-unknown-prop-001'),
        )->assertStatus(422);

        $this->assertSame(0, DB::table('platform_diagnostics')->count());
    }

    #[Test]
    public function it_rejects_an_out_of_range_echo_delay(): void
    {
        $this->postJson(
            '/api/v1/diagnostics/round-trip',
            ['label' => 'valid-label', 'echo_delay_ms' => 999999],
            $this->headers('key-bad-delay-000001'),
        )->assertStatus(422);
    }

    #[Test]
    public function an_error_response_never_leaks_internals(): void
    {
        $response = $this->postJson(
            '/api/v1/diagnostics/round-trip',
            ['label' => 'Bad Label'],
            $this->headers('key-error-shape-0001'),
        );

        $body = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach (['Illuminate\\', 'vendor/', 'SQLSTATE', '/app/', 'PDOException', 'stack'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "Error response leaked \"{$leak}\".");
        }

        $response->assertJsonStructure(['data', 'meta', 'errors' => [['code', 'message']], 'request_id']);
    }

    #[Test]
    public function a_concurrent_processing_record_does_not_start_a_second_transition(): void
    {
        $clientKey = 'key-concurrent-proc1';
        $key = IdempotencyKey::scope(
            $clientKey,
            'api.v1.diagnostics.round-trip',
            hash('sha256', self::TOKEN),
        );

        $body = ['label' => 'concurrent-wait'];
        $requestHash = (new CanonicalRequestHasher)->hash(
            'POST',
            '/api/v1/diagnostics/round-trip',
            json_encode($body),
        );

        $now = '2026-08-26T00:00:00.000000+00:00';
        DB::table('idempotency_keys')->insert([
            'key_hash' => $key->storageKey,
            'operation_id' => $key->operationId,
            'request_hash' => $requestHash,
            'state' => 'PROCESSING',
            'status_code' => null,
            'response_reference' => null,
            'safe_error_class' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => '2026-08-29T00:00:00.000000+00:00',
        ]);

        $this->postJson(
            '/api/v1/diagnostics/round-trip',
            $body,
            $this->headers($clientKey),
        )->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'IDEMPOTENCY_IN_PROGRESS');

        $this->assertSame(0, DB::table('platform_diagnostics')->count());
    }

    #[Test]
    public function an_expired_record_does_not_replay_and_the_key_can_be_reclaimed(): void
    {
        $clientKey = 'key-expired-reclaim01';
        $key = IdempotencyKey::scope(
            $clientKey,
            'api.v1.diagnostics.round-trip',
            hash('sha256', self::TOKEN),
        );

        $past = '2020-01-01T00:00:00.000000+00:00';
        DB::table('idempotency_keys')->insert([
            'key_hash' => $key->storageKey,
            'operation_id' => $key->operationId,
            'request_hash' => str_repeat('b', 64),
            'state' => 'SUCCEEDED',
            'status_code' => 201,
            'response_reference' => '{"stale":true}',
            'safe_error_class' => null,
            'created_at' => $past,
            'updated_at' => $past,
            'expires_at' => $past,
        ]);

        $this->postJson(
            '/api/v1/diagnostics/round-trip',
            ['label' => 'after-expiry'],
            $this->headers($clientKey),
        )->assertCreated()
            ->assertJsonPath('data.idempotent_replay', false);

        $this->assertSame(1, DB::table('platform_diagnostics')->count());
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $idempotencyKey): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ];
    }
}
