<?php

declare(strict_types=1);

use Modules\Platform\Support\OriginHost;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes a bracketed IPv6 loopback host without treating the brackets as part of the address', function () {
    expect(OriginHost::normalize('[::1]'))->toBe('::1')
        ->and(OriginHost::isDeniedInProduction('[::1]'))->toBeTrue()
        ->and(OriginHost::isDeniedInProduction('0:0:0:0:0:0:0:1'))->toBeTrue();
});

it('does not classify a documentation IPv6 address as loopback', function () {
    expect(OriginHost::isDeniedInProduction('[2001:db8::10]'))->toBeFalse()
        ->and(OriginHost::isDeniedInProduction('2001:db8::10'))->toBeFalse();
});

it('does not turn a malformed URL into an accepted host', function (string $origin) {
    $host = OriginHost::fromConfiguredValue($origin);

    expect($host)->not->toBeNull()
        ->and($host)->not->toBe('::1')
        ->and($host)->not->toBe('[::1]')
        ->and(OriginHost::isDeniedInProduction((string) $host))->toBeTrue();
})->with([
    'https://::1',
    'https://[::1',
]);
