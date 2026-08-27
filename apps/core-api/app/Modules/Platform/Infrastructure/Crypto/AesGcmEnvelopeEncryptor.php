<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Crypto;

use App\Modules\Platform\Domain\Contracts\FieldEncryptor;
use RuntimeException;

/**
 * AES-256-GCM envelopes with a version prefix and purpose AAD (ADR 0013).
 *
 * Wire format (binary, stored in bytea):
 *   version uint16 big-endian | 12-byte IV | ciphertext | 16-byte GCM tag
 *
 * Decrypt tries the current key then any previous version present in config.
 */
final class AesGcmEnvelopeEncryptor implements FieldEncryptor
{
    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    /**
     * @param  array<int, string>  $keys  version => 32-byte key material (or longer, hashed down)
     */
    public function __construct(
        private readonly array $keys,
        private readonly int $currentVersion,
        private readonly int $minKeyLength = 32,
    ) {
        if ($this->currentVersion < 1 || ! isset($this->keys[$this->currentVersion]) || $this->keys[$this->currentVersion] === '') {
            throw new RuntimeException('Identity encryption current version has no key.');
        }

        $this->assertKey($this->keys[$this->currentVersion]);
    }

    public function encrypt(string $purpose, string $plaintext): string
    {
        $key = $this->binaryKey($this->currentVersion);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $purpose,
            self::TAG_LENGTH,
        );

        if ($cipher === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Envelope encryption failed.');
        }

        return pack('n', $this->currentVersion).$iv.$cipher.$tag;
    }

    public function decrypt(string $purpose, string $envelope): string
    {
        if (strlen($envelope) < 2 + self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Envelope is truncated.');
        }

        $unpacked = unpack('nversion', substr($envelope, 0, 2));
        $version = is_array($unpacked) ? (int) $unpacked['version'] : 0;

        if ($version < 1 || ! isset($this->keys[$version])) {
            throw new RuntimeException('Envelope version is not readable.');
        }

        $iv = substr($envelope, 2, self::IV_LENGTH);
        $tag = substr($envelope, -self::TAG_LENGTH);
        $cipher = substr($envelope, 2 + self::IV_LENGTH, -self::TAG_LENGTH);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $this->binaryKey($version),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $purpose,
        );

        if (! is_string($plain)) {
            throw new RuntimeException('Envelope decryption failed.');
        }

        return $plain;
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    private function binaryKey(int $version): string
    {
        $material = $this->keys[$version] ?? '';

        if ($material === '') {
            throw new RuntimeException('Identity encryption key is missing.');
        }

        $this->assertKey($material);

        return hash('sha256', $material, true);
    }

    private function assertKey(string $material): void
    {
        if (strlen($material) < $this->minKeyLength) {
            throw new RuntimeException('Identity encryption key entropy is below the approved minimum.');
        }
    }
}
