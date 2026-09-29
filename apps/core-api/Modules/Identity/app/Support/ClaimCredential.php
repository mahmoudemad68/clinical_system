<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Platform\Contracts\RandomBytes;

/**
 * Option B clinic-issued claim credential (frozen Policy v1).
 *
 * Crockford Base32 alphabet without ILOU, 16 characters, ~80 bits.
 * Plaintext is never persisted; callers must show it at most once.
 */
final class ClaimCredential
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 16;

    public const HMAC_PURPOSE = 'profile_claim_credential';

    public static function generate(RandomBytes $random): string
    {
        $raw = $random->next(self::LENGTH);
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[ord($raw[$i]) & 31];
        }

        return $out;
    }

    public static function canonicalize(string $input): ?string
    {
        $stripped = strtoupper((string) preg_replace('/[\s\-_]+/', '', $input));
        if (strlen($stripped) !== self::LENGTH) {
            return null;
        }

        if (strspn($stripped, self::ALPHABET) !== self::LENGTH) {
            return null;
        }

        return $stripped;
    }

    public static function display(string $canonical): string
    {
        return substr($canonical, 0, 4).'-'.substr($canonical, 4, 4).'-'.substr($canonical, 8, 4).'-'.substr($canonical, 12, 4);
    }
}
