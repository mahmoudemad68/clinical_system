<?php

declare(strict_types=1);

namespace Modules\Auth\Services\Crypto;

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Contracts\PasswordHasher;

final class Argon2idPasswordHasher implements PasswordHasher
{
    private const DUMMY_PLAIN = 'timing-balanced-unknown-user';

    private ?string $dummyHash = null;

    public function __construct(private readonly Hasher $hasher) {}

    public function hash(string $plain): string
    {
        return $this->hasher->make($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        return $this->hasher->check($plain, $hash);
    }

    public function dummyVerify(string $plain): void
    {
        $this->hasher->check($plain, $this->dummyHash());
    }

    /**
     * Argon2id of the dummy plaintext is only needed for unknown-user timing
     * balance. Computing it in the constructor made every Octane sandbox that
     * first-resolved PasswordHasher (including doctor verification-status,
     * which constructs CreateAdminDoctorApplicant) pay a full KDF per request.
     */
    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasher->make(self::DUMMY_PLAIN);
    }
}
