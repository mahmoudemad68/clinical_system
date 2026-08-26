<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Purpose-separated keyed HMAC for exact lookup (ADR 0013).
 *
 * The canonical input is already normalized. Callers never pass a raw secret
 * as the key; the adapter derives a purpose key from the versioned master.
 */
interface HmacHasher
{
    /**
     * @param  non-empty-string  $purpose
     * @return non-empty-string binary digest
     */
    public function digest(string $purpose, string $canonical): string;

    public function currentVersion(): int;
}
