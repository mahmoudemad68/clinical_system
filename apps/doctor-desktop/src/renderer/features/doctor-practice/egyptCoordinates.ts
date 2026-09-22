/** Mirrors Clinics V1 Egypt service-area ENGINEERING_DEFAULT. Not a legal cadastral box. */
export const EGYPT_SERVICE_AREA = {
  latitudeMin: 22,
  latitudeMax: 31.7,
  longitudeMin: 24.7,
  longitudeMax: 36.9,
} as const;

export const CAIRO_COORDINATES = {
  latitude: 30.0444,
  longitude: 31.2357,
} as const;

export function isLegalWgs84(latitude: number, longitude: number): boolean {
  return (
    Number.isFinite(latitude) &&
    Number.isFinite(longitude) &&
    latitude >= -90 &&
    latitude <= 90 &&
    longitude >= -180 &&
    longitude <= 180
  );
}

export function isEgyptServiceArea(latitude: number, longitude: number): boolean {
  return (
    isLegalWgs84(latitude, longitude) &&
    latitude >= EGYPT_SERVICE_AREA.latitudeMin &&
    latitude <= EGYPT_SERVICE_AREA.latitudeMax &&
    longitude >= EGYPT_SERVICE_AREA.longitudeMin &&
    longitude <= EGYPT_SERVICE_AREA.longitudeMax
  );
}

export function parseCoordinate(raw: string): number | null {
  const trimmed = raw.trim();
  if (trimmed === '') {
    return null;
  }
  const value = Number(trimmed);
  return Number.isFinite(value) ? value : null;
}

export function pinPosition(
  latitude: number,
  longitude: number,
  width = 240,
  height = 180,
): { x: number; y: number } | null {
  if (!isEgyptServiceArea(latitude, longitude)) {
    return null;
  }
  const x =
    ((longitude - EGYPT_SERVICE_AREA.longitudeMin) /
      (EGYPT_SERVICE_AREA.longitudeMax - EGYPT_SERVICE_AREA.longitudeMin)) *
    width;
  const y =
    ((EGYPT_SERVICE_AREA.latitudeMax - latitude) /
      (EGYPT_SERVICE_AREA.latitudeMax - EGYPT_SERVICE_AREA.latitudeMin)) *
    height;
  return { x, y };
}
