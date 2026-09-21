<?php

declare(strict_types=1);

use Modules\Identity\Support\InvitationPhoneBinding;

it('orders unique lookup HMAC candidates deterministically', function () {
    $current = hex2bin('ff01') ?: '';
    $previous = hex2bin('00aa') ?: '';
    $duplicate = hex2bin('ff01') ?: '';

    $binding = new InvitationPhoneBinding($current, 2, [$duplicate, $previous, $current]);
    $ordered = $binding->orderedLookupHmacs();

    expect($ordered)->toHaveCount(2)
        ->and(bin2hex($ordered[0]))->toBe('00aa')
        ->and(bin2hex($ordered[1]))->toBe('ff01')
        ->and($binding->hmacVersion)->toBe(2)
        ->and(bin2hex($binding->phoneLookupHmac))->toBe('ff01');
});
