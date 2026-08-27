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
