<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Tests\Support\ReverbPusherClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function reverbSloRuntimeEnabled(): bool
{
    return getenv('CLINIC_REVERB_SLO_RUNTIME') === '1';
}

function reverbSloSamples(): int
{
    $raw = getenv('CLINIC_REVERB_SLO_SAMPLES');
    $samples = is_string($raw) && ctype_digit($raw) ? (int) $raw : 100;

    return max(20, min(500, $samples));
}

/**
 * @param  list<float>  $sorted
 */
function reverbSloPercentile(array $sorted, float $percentile): float
{
    $count = count($sorted);
    if ($count === 0) {
        return 0.0;
    }

    $rank = (int) ceil($percentile / 100 * $count) - 1;

    return $sorted[max(0, min($count - 1, $rank))];
}

beforeEach(function () {
    if (! reverbSloRuntimeEnabled()) {
        return;
    }

    config([
        'identity.rate_limits.otp_per_ip_per_hour' => 100000,
        'identity.rate_limits.otp_per_subject_per_hour' => 100000,
        'identity.rate_limits.login_per_ip_per_minute' => 100000,
        'identity.otp.global_hourly_budget' => 100000,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);
});

it('keeps an authorized sibling socket open when a different session is revoked', function () {
    if (! reverbSloRuntimeEnabled()) {
        $this->markTestSkipped('Set CLINIC_REVERB_SLO_RUNTIME=1 with live Reverb to run G-01-16.');
    }

    $keptSession = issueRestrictedSession($this, 'neg-keep');
    $revokedSession = issueRestrictedSession($this, 'neg-drop');
    $kept = attachReverbSocket($this, $keptSession);
    $revoked = attachReverbSocket($this, $revokedSession);

    expect($kept['client']->isOpen())->toBeTrue()
        ->and($revoked['client']->isOpen())->toBeTrue();

    $this->postJson('/api/v1/auth/logout', [], [
        'Authorization' => 'Bearer '.$revoked['token'],
    ])->assertOk();
    reverbSloDispatchOutbox();

    expect($revoked['client']->waitUntilClosed(5.0))->toBeTrue()
        ->and($kept['client']->isOpen())->toBeTrue();

    $kept['client']->close();
})->group('reverb-slo');

it('measures revoke-to-websocket-close against the phase 01 session slo', function () {
    if (! reverbSloRuntimeEnabled()) {
        $this->markTestSkipped('Set CLINIC_REVERB_SLO_RUNTIME=1 with live Reverb to run G-01-16.');
    }

    $samples = reverbSloSamples();
    $slo = (float) config('identity.session.revocation_slo_seconds');
    $timeout = max($slo * 2, 8.0);
    $latencies = [];
    $timeouts = 0;

    for ($i = 0; $i < $samples; $i++) {
        $connected = attachReverbSocket($this, issueRestrictedSession($this, 'slo-'.$i));
        $client = $connected['client'];

        $started = hrtime(true);
        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$connected['token'],
        ])->assertOk();
        reverbSloDispatchOutbox();

        $closed = $client->waitUntilClosed($timeout);
        $elapsed = (hrtime(true) - $started) / 1e9;

        if (! $closed) {
            $timeouts++;
            $client->close();
            $latencies[] = $timeout;

            continue;
        }

        $latencies[] = $elapsed;
    }

    sort($latencies);
    $p50 = reverbSloPercentile($latencies, 50);
    $p95 = reverbSloPercentile($latencies, 95);
    $p99 = reverbSloPercentile($latencies, 99);
    $max = $latencies === [] ? $timeout : $latencies[count($latencies) - 1];
    $passed = $timeouts === 0 && $max < $slo;

    $evidence = [
        'gate' => 'G-01-16',
        'measurement' => 'revoke_to_websocket_close',
        'generated_at' => gmdate('c'),
        'sample_size' => $samples,
        'timeouts' => $timeouts,
        'slo_seconds' => $slo,
        'p50_seconds' => round($p50, 6),
        'p95_seconds' => round($p95, 6),
        'p99_seconds' => round($p99, 6),
        'max_seconds' => round($max, 6),
        'result' => $passed ? 'PASS' : 'FAIL',
        'candidate_sha' => (string) (getenv('CLINIC_REVERB_SLO_SHA') ?: ''),
        'runtime' => [
            'reverb_host' => (string) env('REVERB_HOST', '127.0.0.1'),
            'reverb_port' => (int) env('REVERB_PORT', 8081),
            'reverb_scheme' => (string) env('REVERB_SCHEME', 'http'),
            'broadcast_connection' => 'reverb_channel_auth_and_optional_hint',
            'outbox' => 'in_process_dispatchBatch_after_logout_commit',
            'socket' => 'live_pusher_protocol_private_auth.session',
        ],
    ];

    $path = getenv('CLINIC_REVERB_SLO_EVIDENCE');
    if (is_string($path) && $path !== '') {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    expect($timeouts)->toBe(0)
        ->and($max)->toBeLessThan($slo)
        ->and($p99)->toBeLessThan($slo);
})->group('reverb-slo');

/**
 * @return array{token: string, session_id: string}
 */
function issueRestrictedSession(mixed $test, string $idemKey): array
{
    try {
        Redis::connection('realtime')->ping();
    } catch (Throwable) {
        $test->markTestSkipped('Redis realtime is not reachable.');
    }

    // OTP verify logs in the web guard on this process. Clear it so the next
    // identity is not treated as a cookie session that requires CSRF.
    Auth::forgetGuards();

    $payload = reverbSloIdentity();
    $register = $test->postJson('/api/v1/auth/registrations', $payload, reverbSloIdem('reg-'.$idemKey))->assertCreated();
    reverbSloDispatchOutbox();

    $verify = $test->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => $register->json('data.challenge_id'),
        'code' => reverbSloLastOtp('registration'),
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone',
    ], reverbSloIdem('ver-'.$idemKey));
    $verify->assertOk();

    $token = $verify->json('data.access_token');
    $sessionId = $verify->json('data.session_id');
    expect($token)->toBeString()->and($sessionId)->toBeString();

    return [
        'token' => $token,
        'session_id' => $sessionId,
    ];
}

/**
 * @param  array{token: string, session_id: string}  $session
 * @return array{client: ReverbPusherClient, token: string, session_id: string}
 */
function attachReverbSocket(mixed $test, array $session): array
{
    $host = (string) env('REVERB_HOST', '127.0.0.1');
    $port = (int) env('REVERB_PORT', 8081);
    $probe = @fsockopen($host, $port, $errno, $errstr, 1);
    if (! is_resource($probe)) {
        $test->fail('Reverb is not listening on '.$host.':'.$port.' ('.$errstr.').');
    }
    fclose($probe);

    $client = ReverbPusherClient::connect(
        $host,
        $port,
        (string) env('REVERB_APP_KEY', 'local_dev_only_not_a_secret'),
        'http://localhost:5173',
    );

    $channel = 'private-auth.session.'.$session['session_id'];
    $authorized = $test->postJson('/broadcasting/auth', [
        'socket_id' => $client->socketId,
        'channel_name' => $channel,
    ], ['Authorization' => 'Bearer '.$session['token']]);
    $authorized->assertOk();
    $auth = $authorized->json('auth') ?? $authorized->json('data.auth');
    expect($auth)->toBeString()->not->toBe('');

    $client->subscribePrivate($channel, $auth);

    return [
        'client' => $client,
        'token' => $session['token'],
        'session_id' => $session['session_id'],
    ];
}

function reverbSloIdentity(): array
{
    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $nationalId = $synthetic->nationalId();
    $protector->phone($phone);
    $protector->nationalId($nationalId);

    return [
        'name' => 'Synthetic Patient',
        'phone' => $phone,
        'national_id' => $nationalId,
        'password' => 'correct-horse-battery',
        'language' => 'en',
    ];
}

function reverbSloLastOtp(string $purpose): string
{
    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    return $sms->lastCodeByPurpose[$purpose];
}

function reverbSloDispatchOutbox(): void
{
    app(OutboxDispatcher::class)->dispatchBatch();
}

/**
 * @return array{Idempotency-Key: string}
 */
function reverbSloIdem(string $name): array
{
    return ['Idempotency-Key' => 'clinic-test-idem-reverb-'.$name];
}
