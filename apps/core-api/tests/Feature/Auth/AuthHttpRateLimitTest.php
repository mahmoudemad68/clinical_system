<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    cache()->store((string) config('cache.auth_rate_limiter', 'ratelimit'))->flush();
    app()->forgetInstance(AuthenticationRateLimiter::class);
});

function authRateLimitLoginPayload(): array
{
    return [
        'phone' => '01000000000',
        'password' => 'not-the-password-12',
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'test',
    ];
}

it('returns 401 below the login threshold and 429 with Retry-After after it', function () {
    config([
        'identity.rate_limits.login_per_ip_per_minute' => 3,
        'identity.rate_limits.login_per_subject_per_minute' => 100,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);

    $this->postJson('/api/v1/auth/login', authRateLimitLoginPayload())->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', authRateLimitLoginPayload())->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', authRateLimitLoginPayload())->assertUnauthorized();

    $limited = $this->postJson('/api/v1/auth/login', authRateLimitLoginPayload());
    $limited->assertStatus(429)->assertHeader('Retry-After');
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('rate-limits otp request, resend, and verification', function () {
    config([
        'identity.rate_limits.otp_per_ip_per_hour' => 2,
        'identity.rate_limits.otp_per_subject_per_hour' => 100,
        'identity.otp.global_hourly_budget' => 1000,
        'identity.rate_limits.otp_verify_per_ip_per_minute' => 2,
        'identity.rate_limits.otp_verify_per_challenge_per_minute' => 100,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);

    $otpHeaders = fn (string $key): array => ['Idempotency-Key' => $key];
    $request = [
        'phone' => '01000000001',
        'purpose' => 'registration',
        'language' => 'en',
    ];

    $this->postJson('/api/v1/auth/otp-requests', $request, $otpHeaders('clinic-test-idem-otp-a1'))->assertOk();
    $this->postJson('/api/v1/auth/otp-requests', $request, $otpHeaders('clinic-test-idem-otp-a2'))->assertOk();
    $this->postJson('/api/v1/auth/otp-requests', $request, $otpHeaders('clinic-test-idem-otp-a3'))
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    cache()->store((string) config('cache.auth_rate_limiter'))->flush();
    app()->forgetInstance(AuthenticationRateLimiter::class);

    $verify = [
        'challenge_id' => '01900000-0000-7000-8000-00000000c002',
        'code' => '246801',
        'client_class' => 'patient_mobile',
        'platform' => 'android',
        'device_label' => 'test',
    ];

    $this->postJson('/api/v1/auth/otp-verifications', $verify, $otpHeaders('clinic-test-idem-ver-a1'))->assertStatus(422);
    $this->postJson('/api/v1/auth/otp-verifications', $verify, $otpHeaders('clinic-test-idem-ver-a2'))->assertStatus(422);
    $this->postJson('/api/v1/auth/otp-verifications', $verify, $otpHeaders('clinic-test-idem-ver-a3'))
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('rate-limits invalid refresh, mfa verify, and recovery complete', function () {
    config([
        'identity.rate_limits.refresh_per_ip_per_minute' => 2,
        'identity.rate_limits.refresh_per_device_per_minute' => 100,
        'identity.rate_limits.login_per_ip_per_minute' => 2,
        'identity.rate_limits.mfa_per_challenge_per_minute' => 100,
        'identity.rate_limits.recovery_per_ip_per_hour' => 2,
        'identity.rate_limits.recovery_per_subject_per_hour' => 100,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);

    $refreshHeaders = fn (string $key): array => ['Idempotency-Key' => $key];
    $this->postJson('/api/v1/auth/token/refresh', ['refresh_token' => 'not-a-refresh-token'], $refreshHeaders('clinic-test-idem-ref-a1'))
        ->assertUnauthorized();
    $this->postJson('/api/v1/auth/token/refresh', ['refresh_token' => 'not-a-refresh-token'], $refreshHeaders('clinic-test-idem-ref-a2'))
        ->assertUnauthorized();
    $this->postJson('/api/v1/auth/token/refresh', ['refresh_token' => 'not-a-refresh-token'], $refreshHeaders('clinic-test-idem-ref-a3'))
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    cache()->store((string) config('cache.auth_rate_limiter'))->flush();
    app()->forgetInstance(AuthenticationRateLimiter::class);

    $challenge = '01900000-0000-7000-8000-00000000c003';
    $this->postJson('/api/v1/auth/mfa/challenges/'.$challenge.'/verify', ['code' => '246801'])->assertStatus(422);
    $this->postJson('/api/v1/auth/mfa/challenges/'.$challenge.'/verify', ['code' => '246801'])->assertStatus(422);
    $this->postJson('/api/v1/auth/mfa/challenges/'.$challenge.'/verify', ['code' => '246801'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    cache()->store((string) config('cache.auth_rate_limiter'))->flush();
    app()->forgetInstance(AuthenticationRateLimiter::class);

    $recovery = [
        'challenge_id' => '01900000-0000-7000-8000-00000000c004',
        'code' => '246801',
        'password' => 'RecoveredHorse12',
    ];
    $this->postJson('/api/v1/auth/recovery/complete', $recovery, $refreshHeaders('clinic-test-idem-rec-a1'))->assertStatus(422);
    $this->postJson('/api/v1/auth/recovery/complete', $recovery, $refreshHeaders('clinic-test-idem-rec-a2'))->assertStatus(422);
    $this->postJson('/api/v1/auth/recovery/complete', $recovery, $refreshHeaders('clinic-test-idem-rec-a3'))
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});
