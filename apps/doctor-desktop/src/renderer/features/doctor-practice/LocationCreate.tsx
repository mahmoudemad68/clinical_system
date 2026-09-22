import { useState } from 'react';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useQueryClient } from '@tanstack/react-query';
import type { Locale } from '@clinic/localization';
import { doctorStrings } from '../../strings/doctor';
import { LocationForm, locationFormError } from './LocationForm';
import { navigatePractice } from './practiceRoute';

export function LocationCreate({ locale }: { locale: Locale }) {
  const t = doctorStrings[locale];
  const client = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  return (
    <Stack spacing={2} data-testid="practice-location-create">
      <Typography variant="h5" component="h2">
        {t.practice.createTitle}
      </Typography>
      <LocationForm
        locale={locale}
        mode="create"
        busy={busy}
        message={message}
        onCancel={() => navigatePractice({ name: 'locations' })}
        onSubmit={async (input) => {
          setBusy(true);
          setMessage(null);
          const created = await window.clinic.doctor.createLocation(input);
          if (!created.ok) {
            setBusy(false);
            setMessage(locationFormError(locale, created.error.code));
            return;
          }
          await client.invalidateQueries({ queryKey: ['doctor', 'locations'] });
          setBusy(false);
          navigatePractice({
            name: 'detail',
            locationId: created.value.locationId,
            tab: 'details',
          });
        }}
      />
    </Stack>
  );
}
