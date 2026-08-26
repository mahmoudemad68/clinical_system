<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Crypto;

use App\Modules\Auth\Domain\Contracts\PasswordHasher;
use Illuminate\Contracts\Hashing\Hasher;

final class Argon2idPasswordHasher implements PasswordHasher
{
    private readonly string $dummyHash;

    public function __construct(private readonly Hasher $hasher)
    {
        $this->dummyHash = $this->hasher->make('timing-balanced-unknown-user');
    }

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
        $this->hasher->check($plain, $this->dummyHash);
    }
}
