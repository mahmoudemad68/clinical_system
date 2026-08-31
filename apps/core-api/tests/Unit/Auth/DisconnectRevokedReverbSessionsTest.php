<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Modules\Auth\Services\Realtime\DisconnectRevokedReverbSessions;
use Tests\TestCase;

uses(TestCase::class);

it('names the private session channel that reverb must close', function () {
    $sessionId = '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c09';

    expect(DisconnectRevokedReverbSessions::channelNamesForSession($sessionId))
        ->toBe([
            'private-auth.session.'.$sessionId,
            'presence-auth.session.'.$sessionId,
        ]);
});

it('drains a disconnect instruction without throwing when reverb channels are unbound', function () {
    try {
        Redis::connection('realtime')->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis realtime is not reachable.');
    }

    Redis::connection('realtime')->rpush(DisconnectRevokedReverbSessions::QUEUE_KEY, json_encode([
        'session_id' => '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c09',
        'reason_code' => 'user_logout',
    ], JSON_THROW_ON_ERROR));

    $closed = app(DisconnectRevokedReverbSessions::class)->drainQueue();

    expect($closed)->toBe(0);
});
