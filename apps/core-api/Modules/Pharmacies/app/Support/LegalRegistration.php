<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * Legal registration identifiers are stored protected. Canonicalization is
 * ENGINEERING_DEFAULT: trim, collapse internal whitespace, uppercase. No
 * official checksum or commercial-registry algorithm is applied.
 */
final class LegalRegistration
{
    public static function canonical(string $raw): string
    {
        $collapsed = preg_replace('/\s+/u', '', trim($raw)) ?? '';
        $canonical = mb_strtoupper($collapsed);
        if ($canonical === '') {
            throw new InvalidValueObject('Legal registration identifier is not valid.');
        }

        $max = (int) config('pharmacies_module.legal_registration_max_length', 64);
        if (mb_strlen($canonical) > $max) {
            throw new InvalidValueObject('Legal registration identifier is not valid.');
        }

        return $canonical;
    }
}
