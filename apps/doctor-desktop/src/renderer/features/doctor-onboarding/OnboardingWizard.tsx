import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { Locale } from '@clinic/localization';
import type { DoctorOnboardRequest } from '@clinic/desktop-bridge-contracts';
import { doctorErrorMessage, doctorStrings } from '../../strings/doctor';

const EMPTY_ONBOARDING: DoctorOnboardRequest = {
  nationalId: '',
  professionalDisplayName: '',
  specialtyId: '',
  syndicateNumber: null,
};

function isUncertainOutcome(code: string | undefined): boolean {
  return code === 'TIMEOUT' || code === 'UPSTREAM_FAILED';
}

export function OnboardingWizard({
  locale,
  onReady,
}: {
  locale: Locale;
  onReady: () => void;
}) {
  const t = doctorStrings[locale];
  const [form, setForm] = useState<DoctorOnboardRequest>(EMPTY_ONBOARDING);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [manualReview, setManualReview] = useState(false);
  const alertRef = useRef<HTMLDivElement>(null);

  const specialtiesQuery = useQuery({
    queryKey: ['doctor', 'specialties', locale],
    queryFn: async () => {
      const result = await window.clinic.doctor.listSpecialties();
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value.specialties;
    },
  });

  useEffect(() => {
    if (message) {
      alertRef.current?.focus();
    }
  }, [message]);

  function update<K extends keyof DoctorOnboardRequest>(key: K, value: DoctorOnboardRequest[K]): void {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function clearSensitive(): void {
    setForm(EMPTY_ONBOARDING);
  }

  async function submit(): Promise<void> {
    if (form.specialtyId === '') {
      setMessage(t.errors.VALIDATION_FAILED);
      return;
    }
    setBusy(true);
    setMessage(null);
    const result = await window.clinic.doctor.onboard({
      nationalId: form.nationalId,
      professionalDisplayName: form.professionalDisplayName,
      specialtyId: form.specialtyId,
      syndicateNumber: form.syndicateNumber === '' ? null : form.syndicateNumber,
    });
    setBusy(false);
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      if (isUncertainOutcome(result.error.code)) {
        clearSensitive();
        onReady();
      }
      return;
    }
    clearSensitive();
    if (result.value.status === 'manual_review_required') {
      setManualReview(true);
      setMessage(t.manualReview);
      return;
    }
    onReady();
  }

  return (
    <Stack spacing={2} component="section" data-testid="onboarding-form">
      <Typography variant="h5" component="h2">
        {t.onboarding.title}
      </Typography>
      <Typography>{t.onboarding.intro}</Typography>
      {specialtiesQuery.isPending ? (
        <Typography aria-busy="true">{t.loadingSpecialties}</Typography>
      ) : null}
      {specialtiesQuery.isError ? (
        <Alert severity="error" role="alert">
          {t.specialtiesUnavailable}
          <Button type="button" onClick={() => void specialtiesQuery.refetch()}>
            {t.onboarding.retrySpecialties}
          </Button>
        </Alert>
      ) : null}
      <Stack
        spacing={2}
        component="form"
        onSubmit={(event) => {
          event.preventDefault();
          void submit();
        }}
      >
        <TextField
          label={t.onboarding.nationalId}
          value={form.nationalId}
          autoComplete="off"
          onChange={(event) => update('nationalId', event.target.value)}
        />
        <TextField
          label={t.onboarding.displayName}
          value={form.professionalDisplayName}
          autoComplete="off"
          onChange={(event) => update('professionalDisplayName', event.target.value)}
        />
        <label>
          {t.onboarding.specialty}
          <select
            data-testid="specialty-select"
            aria-label={t.onboarding.specialty}
            value={form.specialtyId}
            onChange={(event) => update('specialtyId', event.target.value)}
          >
            <option value="">{t.onboarding.specialtyPlaceholder}</option>
            {(specialtiesQuery.data ?? []).map((specialty) => (
              <option key={specialty.specialtyId} value={specialty.specialtyId}>
                {locale === 'ar' ? specialty.labelAr : specialty.labelEn}
              </option>
            ))}
          </select>
        </label>
        <TextField
          label={t.onboarding.syndicateNumber}
          value={form.syndicateNumber ?? ''}
          autoComplete="off"
          onChange={(event) => {
            const next = event.target.value;
            update('syndicateNumber', next === '' ? null : next);
          }}
        />
        <Button type="submit" variant="contained" disabled={busy || specialtiesQuery.isPending || manualReview}>
          {busy ? t.onboarding.submitting : t.onboarding.submit}
        </Button>
      </Stack>
      {manualReview ? (
        <Alert severity="info" role="status" data-testid="manual-review">
          {t.manualReview}
        </Alert>
      ) : null}
      {message ? (
        <Alert ref={alertRef} tabIndex={-1} severity="info" role="alert">
          {message}
        </Alert>
      ) : null}
    </Stack>
  );
}
