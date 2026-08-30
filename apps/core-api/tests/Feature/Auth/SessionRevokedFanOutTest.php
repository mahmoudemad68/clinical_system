<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
use Modules\Auth\Services\Outbox\SessionRevokedConsumer;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Services\Outbox\OutboxDispatcher;
use Modules\Platform\Services\Testing\SyntheticEgyptianData;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'identity.rate_limits.otp_per_ip_per_hour' => 100000,
        'identity.rate_limits.otp_per_subject_per_hour' => 100000,
        'identity.rate_limits.login_per_ip_per_minute' => 100000,
        'identity.rate_limits.otp_verify_per_ip_per_minute' => 100000,
        'identity.rate_limits.otp_verify_per_challenge_per_minute' => 100000,
        'identity.otp.global_hourly_budget' => 100000,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);
});

/**
 * @return array{token: string, session_id: string, user_id: string, device_id: string, refresh_token: string, phone: string, password: string}
 */
function sessionRevokedIssue(string $idemKey): array
{
    Auth::forgetGuards();

    $synthetic = new SyntheticEgyptianData;
    $protector = app(NationalIdProtector::class);
    $phone = $synthetic->mobileNumber();
    $nationalId = $synthetic->nationalId();
    $protector->phone($phone);
    $protector->nationalId($nationalId);
    $password = 'correct-horse-battery';

    test()->postJson('/api/v1/auth/registrations', [
        'name' => 'Synthetic Patient',
        'phone' => $phone,
        'national_id' => $nationalId,
        'password' => $password,
        'language' => 'en',
    ], ['Idempotency-Key' => 'clinic-test-idem-rv-reg-'.$idemKey])->assertCreated();
    app(OutboxDispatcher::class)->dispatchBatch();

    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    $verify = test()->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => DB::table('otp_requests')->orderByDesc('created_at')->value('id'),
        'code' => $sms->lastCodeByPurpose['registration'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone-'.$idemKey,
    ], ['Idempotency-Key' => 'clinic-test-idem-rv-ver-'.$idemKey]);
    $verify->assertOk();

    return [
        'token' => (string) $verify->json('data.access_token'),
        'session_id' => (string) $verify->json('data.session_id'),
        'user_id' => (string) $verify->json('data.user_id'),
        'device_id' => (string) $verify->json('data.device_id'),
        'refresh_token' => (string) $verify->json('data.refresh_token'),
        'phone' => $phone,
        'password' => $password,
    ];
}

function sessionRevokedLogin(string $phone, string $password, string $idemKey): array
{
    Auth::forgetGuards();

    $login = test()->postJson('/api/v1/auth/login', [
        'phone' => $phone,
        'password' => $password,
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'login-'.$idemKey,
    ]);
    $login->assertOk();

    return [
        'token' => (string) $login->json('data.access_token'),
        'session_id' => (string) $login->json('data.session_id'),
        'device_id' => (string) $login->json('data.device_id'),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function sessionRevokedPayloads(): array
{
    return DB::table('outbox_events')
        ->where('event_type', 'auth.session_revoked')
        ->orderBy('created_at')
        ->get()
        ->map(function ($row): array {
            $payload = $row->payload;
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                expect($decoded)->toBeArray();

                return $decoded;
            }

            if (is_object($payload)) {
                $decoded = json_decode(json_encode($payload), true);
                expect($decoded)->toBeArray();

                return $decoded;
            }

            expect($payload)->toBeArray();

            return $payload;
        })
        ->all();
}

/**
 * @param  list<array<string, mixed>>  $payloads
 * @param  list<string>  $forbidden
 */
function sessionRevokedIdsAreNormalized(array $payloads, array $forbidden): void
{
    expect($payloads)->not->toBeEmpty();

    foreach ($payloads as $payload) {
        $sessionId = (string) ($payload['session_id'] ?? '');
        expect($sessionId)->not->toBe('')
            ->and(DB::table('auth_sessions')->where('id', $sessionId)->exists())->toBeTrue();

        foreach ($forbidden as $id) {
            expect($sessionId)->not->toBe($id);
        }
    }
}

it('records logout SessionRevoked against the normalized auth session id', function () {
    $session = sessionRevokedIssue('logout');

    test()->postJson('/api/v1/auth/logout', [], [
        'Authorization' => 'Bearer '.$session['token'],
    ])->assertOk();

    $payloads = sessionRevokedPayloads();
    sessionRevokedIdsAreNormalized($payloads, [$session['device_id'], $session['user_id']]);

    $ids = array_column($payloads, 'session_id');
    expect($ids)->toContain($session['session_id']);
});

it('records refresh-reuse SessionRevoked against the normalized auth session id', function () {
    $session = sessionRevokedIssue('reuse');

    $rotated = test()->postJson('/api/v1/auth/token/refresh', [
        'refresh_token' => $session['refresh_token'],
    ], ['Idempotency-Key' => 'clinic-test-idem-rv-ref-1']);
    $rotated->assertOk();

    test()->postJson('/api/v1/auth/token/refresh', [
        'refresh_token' => $session['refresh_token'],
    ], ['Idempotency-Key' => 'clinic-test-idem-rv-ref-2'])->assertUnauthorized();

    $payloads = sessionRevokedPayloads();
    sessionRevokedIdsAreNormalized($payloads, [$session['device_id'], $session['user_id']]);

    $ids = array_column($payloads, 'session_id');
    expect($ids)->toContain($session['session_id']);
});

it('emits one SessionRevoked event per actual session on revoke-all', function () {
    $first = sessionRevokedIssue('all-1');
    $second = sessionRevokedLogin($first['phone'], $first['password'], 'all-2');
    $third = sessionRevokedLogin($first['phone'], $first['password'], 'all-3');

    $sessionIds = [$first['session_id'], $second['session_id'], $third['session_id']];
    expect(array_unique($sessionIds))->toHaveCount(3);

    test()->postJson('/api/v1/auth/sessions/revoke-all', [], [
        'Authorization' => 'Bearer '.$third['token'],
        'Idempotency-Key' => 'clinic-test-idem-rv-all',
    ])->assertOk();

    $payloads = sessionRevokedPayloads();
    sessionRevokedIdsAreNormalized($payloads, [
        $first['user_id'],
        $first['device_id'],
        $second['device_id'],
        $third['device_id'],
    ]);

    $emitted = array_column($payloads, 'session_id');
    sort($emitted);
    $expected = $sessionIds;
    sort($expected);
    expect($emitted)->toBe($expected);
});

it('does not disconnect a sibling session when one session is revoked', function () {
    $first = sessionRevokedIssue('sib-a');
    $second = sessionRevokedLogin($first['phone'], $first['password'], 'sib-b');

    test()->deleteJson('/api/v1/auth/sessions/'.$second['session_id'], [], [
        'Authorization' => 'Bearer '.$first['token'],
    ])->assertOk();

    $payloads = sessionRevokedPayloads();
    sessionRevokedIdsAreNormalized($payloads, [$first['user_id'], $first['device_id'], $second['device_id']]);

    $ids = array_column($payloads, 'session_id');
    expect($ids)->toBe([$second['session_id']])
        ->and(DB::table('auth_sessions')->where('id', $first['session_id'])->whereNull('revoked_at')->exists())->toBeTrue()
        ->and(DB::table('auth_sessions')->where('id', $second['session_id'])->whereNotNull('revoked_at')->exists())->toBeTrue();
});

it('keeps duplicate SessionRevoked consumer delivery harmless', function () {
    try {
        Redis::connection('realtime')->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis realtime is not reachable.');
    }

    $session = sessionRevokedIssue('dup');

    test()->postJson('/api/v1/auth/logout', [], [
        'Authorization' => 'Bearer '.$session['token'],
    ])->assertOk();

    expect(DB::table('auth_sessions')->where('id', $session['session_id'])->whereNotNull('revoked_at')->exists())->toBeTrue();

    $row = DB::table('outbox_events')->where('event_type', 'auth.session_revoked')->first();
    expect($row)->not->toBeNull();

    $payload = is_string($row->payload) ? json_decode($row->payload, true) : (array) $row->payload;
    expect($payload)->toBeArray();

    app(OutboxDispatcher::class)->dispatchBatch();
    app(SessionRevokedConsumer::class)->consume((string) $row->event_id, $payload);

    expect(DB::table('auth_sessions')->where('id', $session['session_id'])->whereNotNull('revoked_at')->exists())->toBeTrue();
});
