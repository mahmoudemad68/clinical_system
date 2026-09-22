import Box from '@mui/material/Box';
import type { Locale } from '@clinic/localization';
import { doctorStrings } from '../../strings/doctor';
import { pinPosition } from './egyptCoordinates';

export function CoordinatePreview({
  locale,
  latitude,
  longitude,
}: {
  locale: Locale;
  latitude: number | null;
  longitude: number | null;
}) {
  const t = doctorStrings[locale].practice;
  const pin =
    latitude !== null && longitude !== null ? pinPosition(latitude, longitude) : null;

  return (
    <Box component="figure" sx={{ m: 0 }} data-testid="coordinate-preview">
      <svg viewBox="0 0 240 180" width="240" height="180" role="img" aria-label={t.coordinatePreview}>
        <rect x="0" y="0" width="240" height="180" fill="#e7eee7" stroke="#4d6b4d" />
        <text x="12" y="22" fontSize="11" fill="#355035">
          EG
        </text>
        {pin ? <circle cx={pin.x} cy={pin.y} r="7" fill="#1b4d1b" /> : null}
      </svg>
    </Box>
  );
}
