<?php

declare(strict_types=1);

namespace Modules\Platform\Services\Crypto;

use Modules\Platform\Contracts\HmacHasher;
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
        private readonly int $minKeyLength = 32,
    ) {
        if ($this->currentVersion < 1 || ! isset($this->keys[$this->currentVersion]) || $this->keys[$this->currentVersion] === '') {
            throw new RuntimeException('Identity HMAC current version has no key.');
        }

        $this->assertKey($this->keys[$this->currentVersion]);
    }

    public function digest(string $purpose, string $canonical): string
    {
        return $this->digestVersion($purpose, $canonical, $this->currentVersion);
    }

    public function lookupDigests(string $purpose, string $canonical): array
    {
        $out = [];

        foreach ($this->keys as $version => $master) {
            if (! is_string($master) || $master === '') {
                continue;
            }

            $this->assertKey($master);
            $out[] = $this->digestVersion($purpose, $canonical, (int) $version);
        }

        return $out;
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    private function digestVersion(string $purpose, string $canonical, int $version): string
    {
        $master = $this->keys[$version] ?? '';

        if ($master === '') {
            throw new RuntimeException('Identity HMAC key is missing.');
        }

        $purposeKey = hash_hkdf(
            'sha256',
            $master,
            32,
            $purpose,
            'clinic-identity-v'.$version,
        );

        return hash_hmac('sha256', $canonical, $purposeKey, true);
    }

    private function assertKey(string $master): void
    {
        if (strlen($master) < $this->minKeyLength) {
            throw new RuntimeException('Identity HMAC key entropy is below the approved minimum.');
        }
    }
}
