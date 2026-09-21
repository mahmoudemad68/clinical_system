import { useQuery } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { Locale } from '@clinic/localization';
import type { AuthMe } from '@clinic/desktop-bridge-contracts';
import { SessionPanel } from '../../components/SessionPanel';
import { doctorStrings } from '../../strings/doctor';
import { OnboardingWizard } from '../doctor-onboarding/OnboardingWizard';
import { DoctorProfileCard } from '../doctor-verification/DoctorProfileCard';
import { VerificationWorkspace } from '../doctor-verification/VerificationWorkspace';

export function DoctorWorkspace({ locale, onSignedOut }: { locale: Locale; onSignedOut: () => void }) {
  const t = doctorStrings[locale];
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
      <SessionPanel locale={locale} onSignedOut={onSignedOut} />
    </Stack>
  );
}
