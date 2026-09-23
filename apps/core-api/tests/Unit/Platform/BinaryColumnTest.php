<?php

declare(strict_types=1);

use Modules\Platform\Services\Persistence\BinaryColumn;

it('round-trips raw hmac bytes through the postgres hex bind format', function () {
    $raw = hash('sha256', 'synthetic-hmac-input', true);

    expect($raw)->not->toBe('')
        ->and(BinaryColumn::bind($raw))->toStartWith('\\x')
        ->and(BinaryColumn::asString(BinaryColumn::bind($raw)))->toBe($raw)
        ->and(BinaryColumn::asString($raw))->toBe($raw);
});

it('leaves raw bytes that happen to start with the postgres hex prefix unchanged', function () {
    $raw = '\\xnot-hex';
    $hmacShaped = '\\x'.str_repeat("\0", 30);

    expect(BinaryColumn::asString($raw))->toBe($raw)
        ->and(BinaryColumn::asString($hmacShaped))->toBe($hmacShaped)
        ->and(BinaryColumn::asString(BinaryColumn::bind($hmacShaped)))->toBe($hmacShaped);
});
