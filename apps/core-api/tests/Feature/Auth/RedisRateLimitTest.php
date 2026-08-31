<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Modules\Auth\Contracts\AuthenticationRateLimiter;
use Modules\Platform\Exceptions\RateLimited;
use Tests\TestCase;

uses(TestCase::class);

function redisRateLimitStoreLimiter(): RateLimiter
{
    return new RateLimiter(cache()->store((string) config('cache.auth_rate_limiter', 'ratelimit')));
}

/**
 * Clear only the Illuminate cache RateLimiter keys this file owns.
 * AuthRateLimiter writes the same logical names through this store.
 *
 * @param  list<string>  $keys
 */
function redisRateLimitClearKeys(array $keys): void
{
    $limiter = redisRateLimitStoreLimiter();
    foreach ($keys as $key) {
        $limiter->clear($key);
    }
}

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

    $subject = random_bytes(32);
    $ip = '203.0.113.0';
    $keys = [
        'auth-login-subject:'.bin2hex($subject),
        'auth-login-ip:'.$ip,
    ];
    redisRateLimitClearKeys($keys);

    try {
        $limiter = app(AuthenticationRateLimiter::class);
        $limiter->hitLogin($subject, $ip);
        $limiter->hitLogin($subject, $ip);

        expect(fn () => $limiter->hitLogin($subject, $ip))->toThrow(RateLimited::class);
    } finally {
        redisRateLimitClearKeys($keys);
    }
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

    $challenge = '01900000-0000-7000-8000-00000000c001';
    $ip = '198.51.100.0';
    $keys = [
        'auth-otp-verify-challenge:'.$challenge,
        'auth-otp-verify-ip:'.$ip,
        'auth-recovery-subject:'.hash('sha256', 'complete:'.$challenge),
        'auth-recovery-subject:'.hash('sha256', 'complete:other'),
        'auth-recovery-subject:'.hash('sha256', 'complete:third'),
        'auth-recovery-ip:'.$ip,
    ];
    redisRateLimitClearKeys($keys);

    try {
        $limiter = app(AuthenticationRateLimiter::class);
        $limiter->hitOtpVerify($challenge, $ip);
        $limiter->hitOtpVerify($challenge, $ip);

        expect(fn () => $limiter->hitOtpVerify($challenge, $ip))->toThrow(RateLimited::class);

        $limiter->hitRecovery('complete:'.$challenge, $ip);
        $limiter->hitRecovery('complete:other', $ip);

        expect(fn () => $limiter->hitRecovery('complete:third', $ip))->toThrow(RateLimited::class);
    } finally {
        redisRateLimitClearKeys($keys);
    }
});
