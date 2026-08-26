<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Crypto;

use App\Modules\Platform\Domain\Contracts\HmacHasher;
use RuntimeException;

/**
 * HKDF-SHA-256 purpose keys plus HMAC-SHA-256 digests (ADR 0013).
 */
final class HkdfHmacHasher implements HmacHasher
{
    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(
        private readonly array $keys,
        private readonly int $currentVersion,
    ) {
        if ($this->currentVersion < 1 || ! isset($this->keys[$this->currentVersion])) {
            throw new RuntimeException('Identity HMAC current version has no key.');
        }
    }

    public function digest(string $purpose, string $canonical): string
    {
        $master = $this->keys[$this->currentVersion] ?? '';

        if ($master === '') {
            throw new RuntimeException('Identity HMAC key is missing.');
        }

        $purposeKey = hash_hkdf(
            'sha256',
            $master,
            32,
            $purpose,
            'clinic-identity-v'.$this->currentVersion,
        );

        return hash_hmac('sha256', $canonical, $purposeKey, true);
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }
}
