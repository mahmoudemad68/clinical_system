import { useEffect, useRef, useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { Locale } from '@clinic/localization';
import type { DoctorClinicLocationView } from '@clinic/desktop-bridge-contracts';
import { doctorErrorMessage, doctorStrings } from '../../strings/doctor';
import { CoordinatePreview } from './CoordinatePreview';
import { isEgyptServiceArea, parseCoordinate } from './egyptCoordinates';

export type LocationFormValues = {
  publicName: string;
  address: string;
  latitude: string;
  longitude: string;
  confirmed: boolean;
};

const EMPTY: LocationFormValues = {
  publicName: '',
  address: '',
  latitude: '',
  longitude: '',
  confirmed: false,
};

export function valuesFromLocation(location: DoctorClinicLocationView): LocationFormValues {
  return {
    publicName: location.publicName,
    address: location.address ?? '',
    latitude: location.latitude === undefined ? '' : String(location.latitude),
    longitude: location.longitude === undefined ? '' : String(location.longitude),
    confirmed: false,
  };
}

export function LocationForm({
  locale,
  mode,
  initial,
  revision,
  busy,
  message,
  onSubmit,
  onCancel,
}: {
  locale: Locale;
  mode: 'create' | 'edit';
  initial?: LocationFormValues;
  revision?: string | number;
  busy: boolean;
  message: string | null;
  onSubmit: (input: {
    publicName: string;
    address: string;
    countryCode: 'EG';
    latitude: number;
    longitude: number;
  }) => Promise<void>;
  onCancel: () => void;
}) {
  const t = doctorStrings[locale];
  const [form, setForm] = useState<LocationFormValues>(initial ?? EMPTY);
  const alertRef = useRef<HTMLDivElement>(null);
  const initialRef = useRef(initial);
  const [localError, setLocalError] = useState<string | null>(null);
  initialRef.current = initial;

  useEffect(() => {
    setForm(initialRef.current ?? EMPTY);
    setLocalError(null);
  }, [revision]);

  useEffect(() => {
    if (message || localError) {
      alertRef.current?.focus();
    }
  }, [message, localError]);

  const latitude = parseCoordinate(form.latitude);
  const longitude = parseCoordinate(form.longitude);
  const coordinatesReady =
    latitude !== null && longitude !== null && isEgyptServiceArea(latitude, longitude) && form.confirmed;

  async function submit(): Promise<void> {
    setLocalError(null);
    if (
      latitude === null ||
      longitude === null ||
      !isEgyptServiceArea(latitude, longitude) ||
      !form.confirmed
    ) {
      setLocalError(t.practice.invalidCoordinates);
      return;
    }
    await onSubmit({
      publicName: form.publicName,
      address: form.address,
      countryCode: 'EG',
      latitude,
      longitude,
    });
  }

  return (
    <Stack
      spacing={2}
      component="form"
      data-testid={mode === 'create' ? 'location-create-form' : 'location-edit-form'}
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      {message || localError ? (
        <Alert
          ref={alertRef}
          tabIndex={-1}
          severity="error"
          role="alert"
          data-testid="location-form-message"
        >
          {localError ?? message}
        </Alert>
      ) : null}
      <TextField
        id="location-public-name"
        label={t.practice.publicName}
        value={form.publicName}
        required
        autoComplete="off"
        onChange={(event) => setForm((current) => ({ ...current, publicName: event.target.value }))}
      />
      <TextField
        id="location-address"
        label={t.practice.address}
        value={form.address}
        required
        multiline
        minRows={2}
        autoComplete="off"
        onChange={(event) => setForm((current) => ({ ...current, address: event.target.value }))}
      />
      <TextField id="location-country" label={t.practice.country} value={t.practice.countryEg} disabled />
      <TextField
        id="location-latitude"
        label={t.practice.latitude}
        value={form.latitude}
        required
        autoComplete="off"
        onChange={(event) => setForm((current) => ({ ...current, latitude: event.target.value }))}
      />
      <TextField
        id="location-longitude"
        label={t.practice.longitude}
        value={form.longitude}
        required
        autoComplete="off"
        onChange={(event) => setForm((current) => ({ ...current, longitude: event.target.value }))}
      />
      <FormControlLabel
        control={
          <Checkbox
            checked={form.confirmed}
            onChange={(event) => setForm((current) => ({ ...current, confirmed: event.target.checked }))}
            data-testid="confirm-coordinates"
          />
        }
        label={t.practice.confirmCoordinates}
      />
      <CoordinatePreview locale={locale} latitude={latitude} longitude={longitude} />
      <Typography>{t.practice.coordinatePreview}</Typography>
      <Stack direction="row" spacing={2}>
        <Button type="submit" variant="contained" disabled={busy || !coordinatesReady} data-testid="location-submit">
          {busy
            ? mode === 'create'
              ? t.practice.creating
              : t.practice.saving
            : mode === 'create'
              ? t.practice.create
              : t.practice.save}
        </Button>
        <Button type="button" onClick={onCancel} disabled={busy}>
          {t.practice.backToList}
        </Button>
      </Stack>
    </Stack>
  );
}

export function locationFormError(locale: Locale, code: string | undefined): string {
  if (code === 'VERSION_CONFLICT') {
    return doctorStrings[locale].practice.versionConflict;
  }
  return doctorErrorMessage(locale, code);
}
