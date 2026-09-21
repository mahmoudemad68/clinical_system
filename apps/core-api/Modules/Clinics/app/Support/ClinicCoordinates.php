<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * Legal WGS-84 latitude/longitude plus the V1 Egypt service-area check.
 *
 * Clinics-specific. Does not import PharmacyCoordinates. The database remains
 * country-ready (legal lat/lng only). Multi-country behavior is not enabled.
 * The Egypt bounding box is ENGINEERING_DEFAULT, not a legal/geographic
 * certification.
 */
final class ClinicCoordinates
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

        $latMin = (float) config('clinics_module.egypt_latitude_min', 22.0);
        $latMax = (float) config('clinics_module.egypt_latitude_max', 31.7);
        $lngMin = (float) config('clinics_module.egypt_longitude_min', 24.7);
        $lngMax = (float) config('clinics_module.egypt_longitude_max', 36.9);

        if ($latitude < $latMin || $latitude > $latMax || $longitude < $lngMin || $longitude > $lngMax) {
            throw new InvalidValueObject('Coordinates are not valid.');
        }
    }
}
