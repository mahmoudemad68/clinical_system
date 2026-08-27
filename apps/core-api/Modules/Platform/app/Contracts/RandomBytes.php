<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * Cryptographic random bytes. Domain code never calls random_bytes() so tests
 * can inject a sequence and production can swap the source.
 */
interface RandomBytes
{
    /**
     * @param  positive-int  $length
     * @return non-empty-string
     */
    public function next(int $length): string;
}
