<?php

declare(strict_types=1);

namespace Modules\Platform\Enums;

/**
 * Supported currencies.
 *
 * V1 is Egypt only (plan.md section 149), but currency is carried explicitly on
 * every money value so that a second country is a data change rather than a
 * schema rewrite.
 */
enum Currency: string
{
    case EGP = 'EGP';

    /**
     * Digits after the decimal separator for display.
     *
     * EGP has 100 piastres to the pound, so the scale is 2. This exists because
     * not every currency has a scale of 2, and hard-coding 2 is exactly the
     * assumption that breaks when the second country arrives.
     */
    public function minorUnitScale(): int
    {
        return match ($this) {
            self::EGP => 2,
        };
    }

    public function country(): CountryCode
    {
        return match ($this) {
            self::EGP => CountryCode::EG,
        };
    }
}
