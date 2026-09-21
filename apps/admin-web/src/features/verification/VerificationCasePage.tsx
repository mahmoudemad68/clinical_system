import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { ApiError } from '@/api/client';
import { SafeError } from '@/app/SafeError';
import { ADMIN_ROUTE_PATHS } from '@/app/routes';
import { DecisionForm } from '@/features/verification/DecisionForm';
import { DocumentList } from '@/features/verification/DocumentList';
import { StatusChip } from '@/features/verification/StatusChip';
import {
  useClaimVerificationCase,
  useVerificationCase,
} from '@/features/verification/useVerificationCase';
import { isDoctorVerificationCase } from '@/features/verification/doctorVerification';
import { isRtl } from '@/i18n';

export function VerificationCasePage() {
  const { caseId } = useParams<{ caseId: string }>();
  const { t, i18n } = useTranslation();
  const detail = useVerificationCase(caseId);
  const claim = useClaimVerificationCase(caseId ?? '');
  const [conflictNotice, setConflictNotice] = useState(false);
  const language = i18n.resolvedLanguage ?? 'en';

  if (detail.isPending) {
    return (
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }} aria-busy="true" aria-live="polite">
        <CircularProgress size={20} aria-hidden />
        <Typography>{t('case.loading')}</Typography>
      </Stack>
    );
  }

  if (detail.error instanceof ApiError) {
    return (
      <Stack spacing={2}>
        <SafeError
          failure={detail.error.failure}
          fallbackKey={detail.error.failure.status === 404 ? 'case.notFound' : 'errors.generic'}
        />
        <Button component={RouterLink} to={ADMIN_ROUTE_PATHS.verificationQueue}>
          {t('case.backToQueue')}
        </Button>
      </Stack>
    );
  }

  const data = detail.data;
  if (!data || caseId === undefined) {
    return <SafeError fallbackKey="case.notFound" />;
  }

  const caseVersion = data.case_version;
  const canClaim = data.case_status === 'pending_review' && data.assignment === 'unassigned';
  const assignedToMe = data.assigned_to_me && data.assignment === 'mine';
  const canDecide = data.case_status === 'pending_review' && assignedToMe;
  const foreignAssigned = data.assignment === 'other';

  async function onClaim(): Promise<void> {
    setConflictNotice(false);
    try {
      await claim.mutateAsync(caseVersion);
    } catch (error) {
      if (
        error instanceof ApiError &&
        (error.failure.code === 'VERSION_CONFLICT' || error.failure.code === 'STATE_CONFLICT')
      ) {
        setConflictNotice(true);
        await detail.refetch();
      }
    }
  }

  async function onDecisionConflict(): Promise<void> {
    setConflictNotice(true);
    await detail.refetch();
  }

  return (
    <Stack spacing={3}>
      <Button component={RouterLink} to={ADMIN_ROUTE_PATHS.verificationQueue} sx={{ alignSelf: 'flex-start' }}>
        {t('case.backToQueue')}
      </Button>
      <Typography variant="h4" component="h1">
        {t('case.title')}
      </Typography>
      {conflictNotice ? (
        <Alert severity="warning" role="status">
          {t('case.changed')}
        </Alert>
      ) : null}

      <Stack spacing={1} component="section">
        {isDoctorVerificationCase(data) ? (
          <>
            <Typography variant="h5" component="h2">
              {data.professional_display_name}
            </Typography>
            <Typography>
              {t('queue.specialty')}: {isRtl(language) ? data.specialty.label_ar : data.specialty.label_en} (
              {data.specialty.code})
            </Typography>
            <Typography>
              {t('case.doctorId')}: {data.doctor_id}
            </Typography>
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
              <StatusChip value={data.case_status} />
              <StatusChip value={data.doctor_verification_status} />
              <StatusChip value={data.doctor_public_status} />
              <StatusChip value={data.assignment} />
            </Stack>
          </>
        ) : (
          <>
            <Typography variant="h5" component="h2">
              {data.public_name}
            </Typography>
            <Typography>
              {t('queue.publicName')}: {data.public_name}
            </Typography>
            <Typography>
              {t('queue.branchName')}: {data.initial_branch.public_name}
            </Typography>
            <Typography>
              {t('queue.branchCountry')}: {data.initial_branch.country_code}
            </Typography>
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
              <StatusChip value={data.case_status} />
              <StatusChip value={data.verification_status} />
              <StatusChip value={data.status} />
              <StatusChip value={data.initial_branch.status} />
              <StatusChip value={data.assignment} />
            </Stack>
            <Alert severity="info" role="status">
              {t('case.pharmacyActivationNote')}
            </Alert>
          </>
        )}
        <Typography>
          {t('queue.submitted')}:{' '}
          <time dateTime={data.submitted_at ?? undefined}>
            {data.submitted_at
              ? new Date(data.submitted_at).toLocaleString(language.startsWith('ar') ? 'ar' : 'en')
              : '—'}
          </time>
        </Typography>
        <Typography>
          {t('queue.version')}: {String(data.case_version)}
        </Typography>
        {data.decision !== null ? (
          <Typography>
            {t('case.decision')}: {t(`decision.${data.decision}`)}
            {data.reason_code ? ` — ${t(`decision.reasons.${data.reason_code}`, { defaultValue: data.reason_code })}` : ''}
          </Typography>
        ) : null}
        {data.decided_at !== null ? (
          <Typography>
            {t('case.decidedAt')}: {new Date(data.decided_at).toLocaleString(language.startsWith('ar') ? 'ar' : 'en')}
          </Typography>
        ) : null}
      </Stack>

      {foreignAssigned ? (
        <Alert severity="info" role="status">
          {t('case.readOnlyOther')}
        </Alert>
      ) : null}

      {canClaim ? (
        <Button
          type="button"
          variant="contained"
          disabled={claim.isPending}
          onClick={() => {
            void onClaim();
          }}
        >
          {claim.isPending ? t('case.claiming') : t('case.claim')}
        </Button>
      ) : null}
      {claim.error instanceof ApiError &&
      claim.error.failure.code !== 'VERSION_CONFLICT' &&
      claim.error.failure.code !== 'STATE_CONFLICT' ? (
        <SafeError failure={claim.error.failure} />
      ) : null}
      {assignedToMe && data.case_status === 'pending_review' ? (
        <Alert severity="success" role="status">
          {t('case.claimed')}
        </Alert>
      ) : null}

      {assignedToMe ? (
        <DocumentList caseId={data.case_id} documents={data.documents} canAccess={canDecide} />
      ) : null}

      {canDecide ? (
        <DecisionForm
          caseId={data.case_id}
          professionalDisplayName={
            isDoctorVerificationCase(data) ? data.professional_display_name : data.public_name
          }
          expectedCaseVersion={data.case_version}
          applicantType={isDoctorVerificationCase(data) ? 'doctor' : 'pharmacy'}
          onConflict={() => {
            void onDecisionConflict();
          }}
        />
      ) : null}

      {data.case_status !== 'pending_review' ? (
        <Alert severity="info" role="status">
          {t('case.decided')}
        </Alert>
      ) : null}
    </Stack>
  );
}
