<?php

declare(strict_types=1);

use Modules\Identity\Support\ClaimCredential;
use Modules\Platform\Contracts\RandomBytes;
use Tests\TestCase;

uses(TestCase::class);

it('generates a 16-character Crockford Base32 Option B secret without ILOU', function () {
    $random = new class implements RandomBytes
    {
        public function next(int $length): string
        {
            return str_repeat("\x1F", $length);
        }
    };

    $secret = ClaimCredential::generate($random);

    expect(strlen($secret))->toBe(16)
        ->and(strspn($secret, ClaimCredential::ALPHABET))->toBe(16)
        ->and($secret)->not->toContain('I')
        ->and($secret)->not->toContain('L')
        ->and($secret)->not->toContain('O')
        ->and($secret)->not->toContain('U')
        ->and(ClaimCredential::ALPHABET)->toBe('0123456789ABCDEFGHJKMNPQRSTVWXYZ')
        ->and(ClaimCredential::HMAC_PURPOSE)->toBe('profile_claim_credential');
});

it('canonicalizes display hyphens and case without treating hyphens as secret bytes', function () {
    expect(ClaimCredential::canonicalize('0123-4567-89ab-cdef'))->toBe('0123456789ABCDEF')
        ->and(ClaimCredential::canonicalize('0123456789abcdef'))->toBe('0123456789ABCDEF')
        ->and(ClaimCredential::canonicalize('0123456789ABCDE'))->toBeNull()
        ->and(ClaimCredential::canonicalize('0123456789ABCDEF0'))->toBeNull()
        ->and(ClaimCredential::canonicalize('I023456789ABCDEF'))->toBeNull()
        ->and(ClaimCredential::display('0123456789ABCDEF'))->toBe('0123-4567-89AB-CDEF');
});
