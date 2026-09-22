import { useEffect, useRef, useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { Locale } from '@clinic/localization';
import type { PharmacyBranchPrivateView } from '@clinic/desktop-bridge-contracts';
import { pharmacyErrorMessage, pharmacyStrings } from '../../strings';
import { CoordinatePreview } from './CoordinatePreview';
import { isEgyptServiceArea, parseCoordinate } from './egyptCoordinates';

export type BranchFormValues = {
  publicName: string;
  address: string;
  latitude: string;
  longitude: string;
  phone: string;
  confirmed: boolean;
};

const EMPTY: BranchFormValues = {
  publicName: '',
  address: '',
  latitude: '',
  longitude: '',
  phone: '',
  confirmed: false,
};

export function valuesFromBranch(branch: PharmacyBranchPrivateView): BranchFormValues {
  return {
    publicName: branch.publicName,
    address: branch.address ?? '',
    latitude: branch.latitude === undefined ? '' : String(branch.latitude),
    longitude: branch.longitude === undefined ? '' : String(branch.longitude),
    phone: '',
    confirmed: false,
  };
}

export function BranchForm({
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
  initial?: BranchFormValues;
  revision?: string | number;
  busy: boolean;
  message: string | null;
  onSubmit: (input: {
    publicName: string;
    address: string;
    countryCode: 'EG';
    latitude: number;
    longitude: number;
    phone: string;
  }) => Promise<void>;
  onCancel: () => void;
}) {
  const t = pharmacyStrings[locale];
  const [form, setForm] = useState<BranchFormValues>(initial ?? EMPTY);
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
  const phoneReady = mode === 'create' ? form.phone.trim().length >= 8 : true;

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
    if (mode === 'create' && form.phone.trim() === '') {
      setLocalError(t.practice.phoneRequired);
      return;
    }
    await onSubmit({
      publicName: form.publicName,
      address: form.address,
      countryCode: 'EG',
      latitude,
      longitude,
      phone: form.phone.trim(),
    });
  }

  return (
    <Stack
      spacing={2}
      component="form"
      data-testid={mode === 'create' ? 'branch-create-form' : 'branch-edit-form'}
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
          data-testid="branch-form-message"
        >
          {localError ?? message}
        </Alert>
      ) : null}
      <TextField
        id="branch-public-name"
        label={t.practice.publicName}
        value={form.publicName}
        required
        autoComplete="off"
        onChange={(event) => setForm((current) => ({ ...current, publicName: event.target.value }))}
      />
      <TextField
        id="branch-address"
        label={t.practice.address}
        value={form.address}
        required
        multiline
        minRows={2}
        autoComplete="off"
        helperText={t.practice.addressHint}
        onChange={(event) => setForm((current) => ({ ...current, address: event.target.value }))}
      />
      <TextField id="branch-country" label={t.practice.country} value={t.practice.countryEg} disabled helperText="EG" />
      <TextField
        id="branch-latitude"
        label={t.practice.latitude}
        value={form.latitude}
        required
        autoComplete="off"
        helperText={t.practice.locationHint}
        onChange={(event) => setForm((current) => ({ ...current, latitude: event.target.value }))}
      />
      <TextField
        id="branch-longitude"
        label={t.practice.longitude}
        value={form.longitude}
        required
        autoComplete="off"
        onChange={(event) => setForm((current) => ({ ...current, longitude: event.target.value }))}
      />
      <TextField
        id="branch-phone"
        label={t.practice.phone}
        value={form.phone}
        type="tel"
        required={mode === 'create'}
        autoComplete="off"
        helperText={t.practice.phoneHint}
        slotProps={{ htmlInput: { 'data-testid': 'branch-phone' } }}
        onChange={(event) => setForm((current) => ({ ...current, phone: event.target.value }))}
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
      <Stack direction="row" spacing={2} sx={{ flexWrap: 'wrap' }}>
        <Button
          type="submit"
          variant="contained"
          disabled={busy || !coordinatesReady || !phoneReady}
          data-testid="branch-submit"
        >
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

export function branchFormError(locale: Locale, code: string | undefined): string {
  if (code === 'VERSION_CONFLICT') {
    return pharmacyStrings[locale].practice.versionConflict;
  }
  return pharmacyErrorMessage(locale, code);
}
