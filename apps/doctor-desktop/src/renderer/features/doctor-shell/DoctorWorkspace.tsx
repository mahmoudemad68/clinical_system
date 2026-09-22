import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { Locale } from '@clinic/localization';
import type { AuthMe } from '@clinic/desktop-bridge-contracts';
import { SessionPanel } from '../../components/SessionPanel';
import { doctorStrings } from '../../strings/doctor';
import { OnboardingWizard } from '../doctor-onboarding/OnboardingWizard';
import { DoctorProfileCard } from '../doctor-verification/DoctorProfileCard';
import { VerificationWorkspace } from '../doctor-verification/VerificationWorkspace';
import { canManageClinicLocations } from '../doctor-practice/eligibility';
import { PracticeWorkspace } from '../doctor-practice/PracticeWorkspace';
import { navigatePractice, parsePracticeHash } from '../doctor-practice/practiceRoute';

function usePracticeHash() {
  const [route, setRoute] = useState(() => parsePracticeHash(window.location.hash));

  useEffect(() => {
    const onChange = () => setRoute(parsePracticeHash(window.location.hash));
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);

  return route;
}

export function DoctorWorkspace({ locale, onSignedOut }: { locale: Locale; onSignedOut: () => void }) {
  const t = doctorStrings[locale];
  const route = usePracticeHash();
  const meQuery = useQuery({
    queryKey: ['auth', 'me', locale],
    queryFn: async () => {
      const result = await window.clinic.auth.me();
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });
  const profileQuery = useQuery({
    queryKey: ['doctor', 'profile', locale],
    enabled: meQuery.data?.accountType === 'doctor',
    queryFn: async () => {
      const result = await window.clinic.doctor.getOwnProfile();
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });

  const me: AuthMe | undefined = meQuery.data;
  const profile = profileQuery.data?.present === true ? profileQuery.data.profile : undefined;
  const canManage = canManageClinicLocations(me, profile);

  useEffect(() => {
    if (profileQuery.isPending || meQuery.isPending) {
      return;
    }
    if (!canManage && route.name !== 'home') {
      window.location.hash = '';
    }
  }, [canManage, route.name, profileQuery.isPending, meQuery.isPending]);

  if (meQuery.isPending) {
    return <Typography aria-busy="true">{t.workspace}</Typography>;
  }

  if (meQuery.error || !me) {
    return (
      <Alert severity="error" role="alert">
        {t.sessionExpired}
      </Alert>
    );
  }

  if (me.accountType !== 'doctor') {
    return (
      <Alert severity="error" role="alert" data-testid="account-denied">
        {t.accountDenied}
      </Alert>
    );
  }

  return (
    <Stack spacing={3} data-testid="doctor-workspace">
      <Typography variant="h4" component="h2">
        {t.workspace}
      </Typography>
      {profileQuery.isPending ? <Typography aria-busy="true">{t.loadingProfile}</Typography> : null}
      {profileQuery.data?.present === true ? (
        <>
          <DoctorProfileCard locale={locale} profile={profileQuery.data.profile} />
          <VerificationWorkspace locale={locale} profile={profileQuery.data.profile} />
        </>
      ) : profileQuery.isSuccess ? (
        <OnboardingWizard
          locale={locale}
          onReady={() => {
            void profileQuery.refetch();
          }}
        />
      ) : null}
      {canManage ? (
        <nav data-testid="practice-locations-nav">
          <Button
            type="button"
            data-testid="open-practice-locations"
            onClick={() => navigatePractice({ name: 'locations' })}
          >
            {t.practice.nav}
          </Button>
        </nav>
      ) : null}
      {canManage && route.name !== 'home' ? <PracticeWorkspace locale={locale} route={route} /> : null}
      <SessionPanel locale={locale} onSignedOut={onSignedOut} />
    </Stack>
  );
}
