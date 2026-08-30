<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Auth\Contracts\DeliverOtpSms;
use Modules\Auth\Services\Adapters\RecordingDeliverOtpSms;
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
 * @return array{token: string, session_id: string, user_id: string, phone: string, password: string}
 */
function privateSessionIssue(string $idemKey): array
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
    ], ['Idempotency-Key' => 'clinic-test-idem-ch-reg-'.$idemKey])->assertCreated();
    app(OutboxDispatcher::class)->dispatchBatch();

    $sms = app(DeliverOtpSms::class);
    expect($sms)->toBeInstanceOf(RecordingDeliverOtpSms::class);

    $verify = test()->postJson('/api/v1/auth/otp-verifications', [
        'challenge_id' => DB::table('otp_requests')->orderByDesc('created_at')->value('id'),
        'code' => $sms->lastCodeByPurpose['registration'],
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'phone-'.$idemKey,
    ], ['Idempotency-Key' => 'clinic-test-idem-ch-ver-'.$idemKey]);
    $verify->assertOk();

    return [
        'token' => (string) $verify->json('data.access_token'),
        'session_id' => (string) $verify->json('data.session_id'),
        'user_id' => (string) $verify->json('data.user_id'),
        'phone' => $phone,
        'password' => $password,
    ];
}

function privateSessionLogin(string $phone, string $password, string $idemKey): array
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
    ];
}

function privateSessionAuth(string $token, string $sessionId)
{
    return test()->postJson('/broadcasting/auth', [
        'socket_id' => '1.1',
        'channel_name' => 'private-auth.session.'.$sessionId,
    ], ['Authorization' => 'Bearer '.$token]);
}

function privateSessionDeniedWithoutAuth($response): void
{
    expect($response->status())->toBeIn([403, 404]);

    $body = (string) $response->getContent();
    expect($body)->not->toContain('true')
        ->and($body)->not->toContain('"auth"');
}

it('allows the presenting session to subscribe to its own private channel', function () {
    $session = privateSessionIssue('allow-own');

    privateSessionAuth($session['token'], $session['session_id'])->assertOk();
});

it('denies the same user presenting session A from authorizing session B 403 or 404', function () {
    $first = privateSessionIssue('same-user-a');
    $second = privateSessionLogin($first['phone'], $first['password'], 'same-user-b');

    expect($second['session_id'])->not->toBe($first['session_id']);

    privateSessionAuth($first['token'], $first['session_id'])->assertOk();
    privateSessionDeniedWithoutAuth(privateSessionAuth($first['token'], $second['session_id']));
    privateSessionDeniedWithoutAuth(privateSessionAuth($second['token'], $first['session_id']));
    privateSessionAuth($second['token'], $second['session_id'])->assertOk();
});

it('denies a different user from authorizing another users session channel 403 or 404', function () {
    $alice = privateSessionIssue('cross-user-a');
    $bob = privateSessionIssue('cross-user-b');

    privateSessionDeniedWithoutAuth(privateSessionAuth($alice['token'], $bob['session_id']));
});

it('denies a forged or nonexistent session id 403 or 404', function () {
    $session = privateSessionIssue('forged');
    $forged = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c09';

    expect(DB::table('auth_sessions')->where('id', $forged)->exists())->toBeFalse();

    privateSessionDeniedWithoutAuth(privateSessionAuth($session['token'], $forged));
});

it('denies a revoked session from authorizing its own channel 401', function () {
    $session = privateSessionIssue('revoked-own');

    test()->postJson('/api/v1/auth/logout', [], [
        'Authorization' => 'Bearer '.$session['token'],
    ])->assertOk();

    $response = privateSessionAuth($session['token'], $session['session_id']);

    expect($response->status())->toBe(401);

    $body = (string) $response->getContent();
    expect($body)->not->toContain('true')
        ->and($body)->not->toContain('"auth"');
});

it('denies an unauthenticated subscriber to a private session channel 401 403 or 404', function () {
    $response = test()->postJson('/broadcasting/auth', [
        'socket_id' => '1.1',
        'channel_name' => 'private-auth.session.0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c09',
    ]);

    expect($response->status())->toBeIn([401, 403, 404]);

    $body = (string) $response->getContent();
    expect($body)->not->toContain('true')
        ->and($body)->not->toContain('"auth"');
});
