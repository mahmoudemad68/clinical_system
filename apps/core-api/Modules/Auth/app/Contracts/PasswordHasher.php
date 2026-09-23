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
     * Timing-balanced dummy verify for unknown users. Requires the unknown-user
     * dummy to already be primed, or primes once as a last-resort so FPM/CLI
     * still perform a real Argon2id check.
     */
    public function dummyVerify(string $plain): void;

    /**
     * One-time Argon2id make() of the unknown-user dummy hash. Idempotent.
     * Octane workers must call this during WorkerStarting before traffic.
     */
    public function primeUnknownUserDummy(): void;

    public function unknownUserDummyIsPrimed(): bool;
}
