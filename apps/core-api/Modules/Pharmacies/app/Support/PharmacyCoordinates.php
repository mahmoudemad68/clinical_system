<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * Legal WGS-84 latitude/longitude plus the V1 Egypt service-area check.
 *
 * The database remains country-ready (legal lat/lng only). Multi-country
 * behavior is not enabled. The Egypt bounding box is ENGINEERING_DEFAULT.
 */
final class PharmacyCoordinates
{
    public static function assertLegal(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || ! is_finite($longitude)) {
            throw new InvalidValueObject('Coordinates are not valid.');
        }

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidValueObject('Coordinates are not valid.');
        }
    }

    public static function assertEgyptServiceArea(float $latitude, float $longitude): void
    {
        self::assertLegal($latitude, $longitude);

        $latMin = (float) config('pharmacies_module.egypt_latitude_min', 22.0);
        $latMax = (float) config('pharmacies_module.egypt_latitude_max', 31.7);
        $lngMin = (float) config('pharmacies_module.egypt_longitude_min', 24.7);
        $lngMax = (float) config('pharmacies_module.egypt_longitude_max', 36.9);

        if ($latitude < $latMin || $latitude > $latMax || $longitude < $lngMin || $longitude > $lngMax) {
            throw new InvalidValueObject('Coordinates are not valid.');
        }
    }
}
