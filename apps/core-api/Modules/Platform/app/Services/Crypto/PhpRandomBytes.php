<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Crypto;

use InvalidArgumentException;
use Modules\Platform\Contracts\RandomBytes;

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
