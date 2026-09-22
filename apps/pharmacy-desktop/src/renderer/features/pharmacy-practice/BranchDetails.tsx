import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import Typography from '@mui/material/Typography';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { Locale } from '@clinic/localization';
import { pharmacyErrorMessage, pharmacyStrings } from '../../strings';
import { BranchForm, branchFormError, valuesFromBranch } from './BranchForm';
import { TeamSection } from './TeamSection';
import { navigatePractice, type PracticeTab } from './practiceRoute';

export function BranchDetails({
  locale,
  branchId,
  tab,
}: {
  locale: Locale;
  branchId: string;
  tab: PracticeTab;
}) {
  const t = pharmacyStrings[locale];
  const client = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);

  const branchQuery = useQuery({
    queryKey: ['pharmacy', 'branch', branchId, locale],
    queryFn: async () => {
      const result = await window.clinic.pharmacy.getBranch({ branchId });
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });

  const branch = branchQuery.data;

  return (
    <Stack spacing={2} data-testid="practice-branch-details">
      <Button type="button" onClick={() => navigatePractice({ name: 'branches' })}>
        {t.practice.backToList}
      </Button>
      {branchQuery.isPending ? (
        <Typography aria-busy="true">{t.practice.loading}</Typography>
      ) : null}
      {branchQuery.isError ? (
        <Alert severity="error" role="alert" data-testid="branch-unavailable">
          {branchQuery.error instanceof Error && branchQuery.error.message === 'NOT_FOUND'
            ? t.practice.notAvailable
            : pharmacyErrorMessage(
                locale,
                branchQuery.error instanceof Error ? branchQuery.error.message : undefined,
              )}
        </Alert>
      ) : null}
      {branch ? (
        <>
          <Typography variant="h5" component="h2" data-testid="branch-public-name">
            {branch.publicName}
          </Typography>
          <Typography data-testid="branch-status">
            {t.practice.status}: {t.practice.branchStatuses[branch.status]}
          </Typography>
          <Typography data-testid="branch-version">
            {t.practice.version}: {String(branch.version)}
          </Typography>
          <Tabs
            value={tab}
            onChange={(_event, next: PracticeTab) =>
              navigatePractice({ name: 'detail', branchId, tab: next })
            }
            aria-label={t.practice.detailsTitle}
          >
            <Tab value="details" label={t.practice.tabDetails} data-testid="tab-details" />
            <Tab value="location" label={t.practice.tabLocation} data-testid="tab-location" />
            <Tab value="team" label={t.practice.tabTeam} data-testid="tab-team" />
          </Tabs>
          {tab === 'details' ? (
            <Stack spacing={1} data-testid="branch-details-panel">
              <Typography>
                {t.practice.country}: {t.practice.countryEg}
              </Typography>
              <Typography>
                {t.practice.createdAt}: {branch.createdAt}
              </Typography>
              <Typography>
                {t.practice.updatedAt}: {branch.updatedAt}
              </Typography>
              {branch.address ? (
                <Typography data-testid="branch-address">{branch.address}</Typography>
              ) : null}
              {branch.latitude !== undefined && branch.longitude !== undefined ? (
                <Typography data-testid="branch-coordinates">
                  {t.practice.latitude}: {String(branch.latitude)} {t.practice.longitude}:{' '}
                  {String(branch.longitude)}
                </Typography>
              ) : null}
            </Stack>
          ) : null}
          {tab === 'location' ? (
            <Stack spacing={2} data-testid="branch-edit-panel">
              {conflict ? (
                <Alert severity="warning" role="alert" data-testid="version-conflict">
                  {t.practice.versionConflict}
                  <Button
                    type="button"
                    data-testid="refresh-branch"
                    onClick={() => {
                      setConflict(false);
                      setMessage(null);
                      void branchQuery.refetch();
                    }}
                  >
                    {t.practice.refreshLatest}
                  </Button>
                </Alert>
              ) : null}
              <BranchForm
                locale={locale}
                mode="edit"
                initial={valuesFromBranch(branch)}
                revision={branch.version}
                busy={busy}
                message={message}
                onCancel={() => navigatePractice({ name: 'branches' })}
                onSubmit={async (input) => {
                  setBusy(true);
                  setMessage(null);
                  setConflict(false);
                  const payload: Parameters<typeof window.clinic.pharmacy.updateBranch>[0] = {
                    branchId,
                    expectedVersion: branch.version,
                    publicName: input.publicName,
                    address: input.address,
                    countryCode: input.countryCode,
                    latitude: input.latitude,
                    longitude: input.longitude,
                  };
                  if (input.phone !== '') {
                    payload.phone = input.phone;
                  }
                  const result = await window.clinic.pharmacy.updateBranch(payload);
                  setBusy(false);
                  if (!result.ok) {
                    if (result.error.code === 'VERSION_CONFLICT') {
                      setConflict(true);
                      setMessage(null);
                      return;
                    }
                    setMessage(branchFormError(locale, result.error.code));
                    return;
                  }
                  client.setQueryData(['pharmacy', 'branch', branchId, locale], result.value);
                  await client.invalidateQueries({ queryKey: ['pharmacy', 'branches'] });
                }}
              />
            </Stack>
          ) : null}
          {tab === 'team' ? <TeamSection locale={locale} branchId={branchId} /> : null}
        </>
      ) : null}
    </Stack>
  );
}
