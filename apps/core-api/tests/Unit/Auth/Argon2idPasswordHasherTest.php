<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Services\Crypto\Argon2idPasswordHasher;
use Tests\TestCase;

uses(TestCase::class);

describe('argon2id password hasher', function () {
    it('does not kdf a dummy password until dummyVerify', function () {
        $probe = (object) ['makes' => 0];
        $hasher = new class($probe) implements Hasher
        {
            public function __construct(private object $probe) {}

            public function info($hashedValue): array
            {
                return [];
            }

            public function make($value, array $options = []): string
            {
                $this->probe->makes++;

                return 'hashed:'.$value;
            }

            public function check($value, $hashedValue, array $options = []): bool
            {
                return $hashedValue === 'hashed:'.$value;
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return false;
            }
        };

        $passwords = new Argon2idPasswordHasher($hasher);

        expect($probe->makes)->toBe(0);

        $passwords->dummyVerify('timing-balanced-unknown-user');
        expect($probe->makes)->toBe(1);

        $passwords->dummyVerify('timing-balanced-unknown-user');
        expect($probe->makes)->toBe(1);

        $passwords->hash('another-secret');
        expect($probe->makes)->toBe(2);
    });
});
