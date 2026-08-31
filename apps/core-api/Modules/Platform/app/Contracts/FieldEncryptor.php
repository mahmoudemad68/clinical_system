<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

/**
 * Versioned envelope encryption for classified fields (ADR 0013).
 *
 * Implementations must not log plaintext or ciphertext. Associated data binds
 * a ciphertext to its column purpose so a phone blob cannot be decrypted as a
 * National ID.
 */
interface FieldEncryptor
{
    /**
     * @param  non-empty-string  $purpose
     */
    public function encrypt(string $purpose, string $plaintext): string;

    /**
     * @param  non-empty-string  $purpose
     */
    public function decrypt(string $purpose, string $envelope): string;

    /**
     * Envelope key version prefix. Does not decrypt. Fails closed on a
     * truncated or unknown version.
     */
    public function envelopeVersion(string $envelope): int;

    public function currentVersion(): int;
}
