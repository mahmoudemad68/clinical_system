<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Crypto;

use App\Modules\Platform\Domain\Contracts\RandomBytes;
use InvalidArgumentException;

final class PhpRandomBytes implements RandomBytes
{
    public function next(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Random length must be positive.');
        }

        return random_bytes($length);
    }
}
