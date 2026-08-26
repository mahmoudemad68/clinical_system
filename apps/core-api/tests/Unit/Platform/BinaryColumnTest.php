<?php

declare(strict_types=1);

use App\Modules\Platform\Infrastructure\Persistence\BinaryColumn;

it('round-trips raw hmac bytes through the postgres hex bind format', function () {
    $raw = hash('sha256', 'synthetic-hmac-input', true);

    expect($raw)->not->toBe('')
        ->and(BinaryColumn::bind($raw))->toStartWith('\\x')
        ->and(BinaryColumn::asString(BinaryColumn::bind($raw)))->toBe($raw)
        ->and(BinaryColumn::asString($raw))->toBe($raw);
});
