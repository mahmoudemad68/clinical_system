<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Platform\Exceptions\RateLimited;
use Tests\TestCase;

uses(TestCase::class);

it('enforces auth rate limits on the redis ratelimit store', function () {
    try {
        $pong = Redis::connection('ratelimit')->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis ratelimit connection is unavailable');
    }

    if ($pong === false) {
        $this->markTestSkipped('Redis ratelimit connection is unavailable');
    }

    config([
        'cache.stores.ratelimit.driver' => 'redis',
        'identity.rate_limits.login_per_subject_per_minute' => 2,
        'identity.rate_limits.login_per_ip_per_minute' => 100,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);
    $limiter = app(AuthenticationRateLimiter::class);
    $subject = random_bytes(32);

    $limiter->hitLogin($subject, '203.0.113.0');
    $limiter->hitLogin($subject, '203.0.113.0');

    expect(fn () => $limiter->hitLogin($subject, '203.0.113.0'))->toThrow(RateLimited::class);
});

it('enforces otp-verify and recovery ip limits on the redis ratelimit store', function () {
    try {
        $pong = Redis::connection('ratelimit')->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis ratelimit connection is unavailable');
    }

    if ($pong === false) {
        $this->markTestSkipped('Redis ratelimit connection is unavailable');
    }

    config([
        'cache.stores.ratelimit.driver' => 'redis',
        'identity.rate_limits.otp_verify_per_challenge_per_minute' => 2,
        'identity.rate_limits.otp_verify_per_ip_per_minute' => 100,
        'identity.rate_limits.recovery_per_subject_per_hour' => 100,
        'identity.rate_limits.recovery_per_ip_per_hour' => 2,
    ]);
    app()->forgetInstance(AuthenticationRateLimiter::class);
    $limiter = app(AuthenticationRateLimiter::class);
    $challenge = '01900000-0000-7000-8000-00000000c001';

    $limiter->hitOtpVerify($challenge, '198.51.100.0');
    $limiter->hitOtpVerify($challenge, '198.51.100.0');

    expect(fn () => $limiter->hitOtpVerify($challenge, '198.51.100.0'))->toThrow(RateLimited::class);

    $limiter->hitRecovery('complete:'.$challenge, '198.51.100.0');
    $limiter->hitRecovery('complete:other', '198.51.100.0');

    expect(fn () => $limiter->hitRecovery('complete:third', '198.51.100.0'))->toThrow(RateLimited::class);
});
