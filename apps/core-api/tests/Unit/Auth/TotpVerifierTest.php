<?php

declare(strict_types=1);

use Modules\Auth\Contracts\TotpVerifier;
use Modules\Platform\Contracts\Clock;
use Tests\TestCase;

uses(TestCase::class);

describe('totp verifier', function () {
    it('accepts a current code and rejects a replayed counter', function () {
        $totp = app(TotpVerifier::class);
        $clock = app(Clock::class);
        $secret = $totp->generateSecret();
        $now = $clock->now();
        $code = $totp->codeAt($secret, $now);

        $first = $totp->verify($secret, $code, $now, null);
        expect($first->valid)->toBeTrue()
            ->and($first->acceptedCounter)->toBeInt();

        $replay = $totp->verify($secret, $code, $now, $first->acceptedCounter);
        expect($replay->valid)->toBeFalse();
    });
});
