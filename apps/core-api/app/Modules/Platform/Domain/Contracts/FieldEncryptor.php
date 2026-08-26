<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

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

    public function currentVersion(): int;
}
