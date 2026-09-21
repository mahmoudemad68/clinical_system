import Typography from '@mui/material/Typography';
import Stack from '@mui/material/Stack';
import type { Locale } from '@clinic/localization';
import type { DoctorProfileView } from '@clinic/desktop-bridge-contracts';
import { doctorStrings } from '../../strings/doctor';

export function DoctorProfileCard({
  locale,
  profile,
}: {
  locale: Locale;
  profile: DoctorProfileView;
}) {
  const t = doctorStrings[locale];
  const specialty = locale === 'ar' ? profile.specialtyLabelAr : profile.specialtyLabelEn;
  const verification = t.statuses[profile.verificationStatus];
  const publicStatus = profile.publicStatus === 'listed' ? t.profile.listed : t.profile.hidden;

  return (
    <Stack spacing={1} component="section" data-testid="doctor-profile">
      <Typography variant="h5" component="h2">
        {t.profile.title}
      </Typography>
      <Typography>
        {t.profile.displayName}: {profile.professionalDisplayName}
      </Typography>
      <Typography>
        {t.profile.specialty}: {specialty}
      </Typography>
      <Typography data-testid="profile-verification-status">
        {t.profile.verification}: {verification}
      </Typography>
      <Typography>
        {t.profile.publicStatus}: {publicStatus}
      </Typography>
      <Typography>
        {t.profile.version}: {String(profile.version)}
      </Typography>
      {profile.approvedAt ? (
        <Typography>
          {t.profile.approved}: <time dateTime={profile.approvedAt}>{profile.approvedAt}</time>
        </Typography>
      ) : null}
      {profile.suspendedAt ? (
        <Typography data-testid="profile-suspended">
          {t.profile.suspended}: <time dateTime={profile.suspendedAt}>{profile.suspendedAt}</time>
        </Typography>
      ) : null}
    </Stack>
  );
}
