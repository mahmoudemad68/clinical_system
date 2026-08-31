<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * ISO 3166-1 alpha-2 country codes the platform recognises.
 *
 * Fixed to Egypt in V1. The type exists so that country is an explicit column
 * and an explicit contract field from day one; multi-country is a listed V1
 * exclusion (plan.md section 171) and stays behind the `multi_country` flag.
 */
enum CountryCode: string
{
    case EG = 'EG';

    public function defaultCurrency(): Currency
    {
        return match ($this) {
            self::EG => Currency::EGP,
        };
    }

    public function timeZoneIdentifier(): string
    {
        return match ($this) {
            self::EG => 'Africa/Cairo',
        };
    }

    /**
     * Egyptian national ID: 14 digits.
     *
     * Format only. This is not a validity check and never a verification: a
     * well-formed number is not a real person's number. Identity proofing is
     * Phase 01 and requires far more than a regular expression.
     */
    public function nationalIdPattern(): string
    {
        return match ($this) {
            self::EG => '/^[0-9]{14}$/',
        };
    }
}
