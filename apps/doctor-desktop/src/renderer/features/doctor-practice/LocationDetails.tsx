import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import Typography from '@mui/material/Typography';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { Locale } from '@clinic/localization';
import { doctorErrorMessage, doctorStrings } from '../../strings/doctor';
import { LocationForm, locationFormError, valuesFromLocation } from './LocationForm';
import { StaffSection } from './StaffSection';
import { navigatePractice, type PracticeTab } from './practiceRoute';

export function LocationDetails({
  locale,
  locationId,
  tab,
}: {
  locale: Locale;
  locationId: string;
  tab: PracticeTab;
}) {
  const t = doctorStrings[locale];
  const client = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);

  const locationQuery = useQuery({
    queryKey: ['doctor', 'location', locationId, locale],
    queryFn: async () => {
      const result = await window.clinic.doctor.getLocation({ locationId });
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });

  const location = locationQuery.data;

  return (
    <Stack spacing={2} data-testid="practice-location-details">
      <Button type="button" onClick={() => navigatePractice({ name: 'locations' })}>
        {t.practice.backToList}
      </Button>
      {locationQuery.isPending ? (
        <Typography aria-busy="true">{t.practice.loading}</Typography>
      ) : null}
      {locationQuery.isError ? (
        <Alert severity="error" role="alert" data-testid="location-unavailable">
          {locationQuery.error instanceof Error && locationQuery.error.message === 'NOT_FOUND'
            ? t.practice.notAvailable
            : doctorErrorMessage(
                locale,
                locationQuery.error instanceof Error ? locationQuery.error.message : undefined,
              )}
        </Alert>
      ) : null}
      {location ? (
        <>
          <Typography variant="h5" component="h2" data-testid="location-public-name">
            {location.publicName}
          </Typography>
          <Typography data-testid="location-status">
            {t.practice.status}: {t.practice.locationStatuses[location.status]}
          </Typography>
          <Typography data-testid="location-version">
            {t.practice.version}: {String(location.version)}
          </Typography>
          <Tabs
            value={tab}
            onChange={(_event, next: PracticeTab) =>
              navigatePractice({ name: 'detail', locationId, tab: next })
            }
            aria-label={t.practice.detailsTitle}
          >
            <Tab value="details" label={t.practice.tabDetails} data-testid="tab-details" />
            <Tab value="location" label={t.practice.tabLocation} data-testid="tab-location" />
            <Tab value="staff" label={t.practice.tabStaff} data-testid="tab-staff" />
          </Tabs>
          {tab === 'details' ? (
            <Stack spacing={1} data-testid="location-details-panel">
              <Typography>
                {t.practice.country}: {t.practice.countryEg}
              </Typography>
              <Typography>
                {t.practice.createdAt}: {location.createdAt}
              </Typography>
              <Typography>
                {t.practice.updatedAt}: {location.updatedAt}
              </Typography>
              {location.address ? (
                <Typography data-testid="location-address">{location.address}</Typography>
              ) : null}
              {location.latitude !== undefined && location.longitude !== undefined ? (
                <Typography data-testid="location-coordinates">
                  {t.practice.latitude}: {String(location.latitude)} {t.practice.longitude}:{' '}
                  {String(location.longitude)}
                </Typography>
              ) : null}
            </Stack>
          ) : null}
          {tab === 'location' ? (
            <Stack spacing={2} data-testid="location-edit-panel">
              {conflict ? (
                <Alert severity="warning" role="alert" data-testid="version-conflict">
                  {t.practice.versionConflict}
                  <Button
                    type="button"
                    data-testid="refresh-location"
                    onClick={() => {
                      setConflict(false);
                      setMessage(null);
                      void locationQuery.refetch();
                    }}
                  >
                    {t.practice.refreshLatest}
                  </Button>
                </Alert>
              ) : null}
              <LocationForm
                locale={locale}
                mode="edit"
                initial={valuesFromLocation(location)}
                revision={location.version}
                busy={busy}
                message={message}
                onCancel={() => navigatePractice({ name: 'locations' })}
                onSubmit={async (input) => {
                  setBusy(true);
                  setMessage(null);
                  setConflict(false);
                  const result = await window.clinic.doctor.updateLocation({
                    locationId,
                    expectedVersion: location.version,
                    publicName: input.publicName,
                    address: input.address,
                    countryCode: input.countryCode,
                    latitude: input.latitude,
                    longitude: input.longitude,
                  });
                  setBusy(false);
                  if (!result.ok) {
                    if (result.error.code === 'VERSION_CONFLICT') {
                      setConflict(true);
                      setMessage(null);
                      return;
                    }
                    setMessage(locationFormError(locale, result.error.code));
                    return;
                  }
                  client.setQueryData(['doctor', 'location', locationId, locale], result.value);
                  await client.invalidateQueries({ queryKey: ['doctor', 'locations'] });
                }}
              />
            </Stack>
          ) : null}
          {tab === 'staff' ? <StaffSection locale={locale} locationId={locationId} /> : null}
        </>
      ) : null}
    </Stack>
  );
}
