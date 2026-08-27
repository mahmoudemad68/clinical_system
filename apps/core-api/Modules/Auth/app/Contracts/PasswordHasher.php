<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts;

/**
 * Argon2id password hashing behind a port so domain tests do not boot Hash.
 */
interface PasswordHasher
{
    public function hash(string $plain): string;

    public function verify(string $plain, string $hash): bool;

    /**
     * Timing-balanced dummy verify for unknown users.
     */
    public function dummyVerify(string $plain): void;
}
