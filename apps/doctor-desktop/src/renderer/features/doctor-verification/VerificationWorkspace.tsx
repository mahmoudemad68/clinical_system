import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { Locale } from '@clinic/localization';
import type {
  DoctorEvidenceSelectResponse,
  DoctorProfileView,
  DoctorUploadStatus,
  DoctorVerificationStatus,
} from '@clinic/desktop-bridge-contracts';
import { doctorErrorMessage, doctorStrings } from '../../strings/doctor';

const UPLOAD_POLL_INTERVAL_MS = 2_000;
const UPLOAD_POLL_TIMEOUT_MS = 60_000;

const SCANNING_STATES: ReadonlySet<DoctorUploadStatus['state']> = new Set([
  'requested',
  'uploading',
  'quarantined',
  'validating',
  'scanning',
]);

function isUncertainOutcome(code: string | undefined): boolean {
  return code === 'TIMEOUT' || code === 'UPSTREAM_FAILED';
}

function isConflict(code: string | undefined): boolean {
  return code === 'VERSION_CONFLICT' || code === 'STATE_CONFLICT';
}

function uploadStatusCopy(locale: Locale, state: DoctorUploadStatus['state']): string {
  const t = doctorStrings[locale].verification;
  if (state === 'requested') {
    return t.requested;
  }
  if (state === 'uploading') {
    return t.uploadingState;
  }
  if (state === 'quarantined') {
    return t.quarantined;
  }
  if (state === 'validating') {
    return t.validating;
  }
  if (state === 'scanning') {
    return t.scanning;
  }
  if (state === 'available') {
    return t.available;
  }
  if (state === 'rejected') {
    return t.rejectedUpload;
  }
  return t.completeIsNotAvailable;
}

export function VerificationWorkspace({
  locale,
  profile,
}: {
  locale: Locale;
  profile: DoctorProfileView;
}) {
  const t = doctorStrings[locale];
  const client = useQueryClient();
  const [message, setMessage] = useState<string | null>(null);
  const [evidence, setEvidence] = useState<Extract<DoctorEvidenceSelectResponse, { selected: true }> | null>(
    null,
  );
  const [uploadId, setUploadId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [pollTimedOut, setPollTimedOut] = useState(false);
  const [pollGeneration, setPollGeneration] = useState(0);
  const alertRef = useRef<HTMLDivElement>(null);

  const statusQuery = useQuery({
    queryKey: ['doctor', 'verification', locale],
    queryFn: async () => {
      const result = await window.clinic.doctor.verificationStatus();
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
  });

  const uploadQuery = useQuery({
    queryKey: ['doctor', 'upload', uploadId, locale, pollGeneration],
    enabled: uploadId !== null,
    queryFn: async () => {
      if (uploadId === null) {
        throw new Error('NOT_FOUND');
      }
      const result = await window.clinic.doctor.uploadStatus(uploadId);
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
    refetchInterval: (query) => {
      if (pollTimedOut) {
        return false;
      }
      const state = query.state.data?.state;
      if (state !== undefined && SCANNING_STATES.has(state)) {
        return UPLOAD_POLL_INTERVAL_MS;
      }
      return false;
    },
  });

  useEffect(() => {
    if (uploadId === null) {
      return;
    }
    setPollTimedOut(false);
    const timer = window.setTimeout(() => {
      setPollTimedOut(true);
    }, UPLOAD_POLL_TIMEOUT_MS);
    return () => window.clearTimeout(timer);
  }, [uploadId, pollGeneration]);

  useEffect(() => {
    if (message) {
      alertRef.current?.focus();
    }
  }, [message]);

  const status: DoctorVerificationStatus | undefined = statusQuery.data;
  const requirementDocument = status?.documents.find(
    (document) => document.requirementCode === 'professional_id',
  );
  const reconciledUploadState: DoctorUploadStatus['state'] | null =
    uploadQuery.data?.state ??
    (requirementDocument === undefined
      ? null
      : requirementDocument.status === 'available'
        ? 'available'
        : requirementDocument.status === 'rejected'
          ? 'rejected'
          : 'quarantined');
  const evidenceReady =
    uploadQuery.data?.state === 'available' ||
    status?.documents.some((document) => document.status === 'available' && document.scanStatus === 'clean') ===
      true;
  const canResubmit =
    status?.profileVerificationStatus === 'changes_requested' ||
    status?.profileVerificationStatus === 'rejected';
  const pendingReview = status?.profileVerificationStatus === 'pending_review';
  const approved = status?.profileVerificationStatus === 'approved';
  const suspended = status?.profileVerificationStatus === 'suspended';
  const draftCase = status?.caseStatus === 'draft';

  async function refreshAuthoritative(): Promise<void> {
    await statusQuery.refetch();
    await client.invalidateQueries({ queryKey: ['doctor', 'profile'] });
    if (uploadId !== null) {
      await uploadQuery.refetch();
    }
  }

  useEffect(() => {
    if (approved) {
      void client.invalidateQueries({ queryKey: ['doctor', 'profile'] });
    }
  }, [approved, client]);

  async function openCase(): Promise<void> {
    setBusy(true);
    setMessage(null);
    setEvidence(null);
    setUploadId(null);
    const result = await window.clinic.doctor.openVerificationCase();
    setBusy(false);
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      if (isConflict(result.error.code) || isUncertainOutcome(result.error.code)) {
        await refreshAuthoritative();
      }
      return;
    }
    await refreshAuthoritative();
  }

  async function chooseFile(): Promise<void> {
    setMessage(null);
    const result = await window.clinic.doctor.selectEvidence();
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      return;
    }
    if (!result.value.selected) {
      return;
    }
    setEvidence(result.value);
  }

  async function upload(): Promise<void> {
    if (evidence === null || status?.caseId === null || status?.caseId === undefined) {
      return;
    }
    setBusy(true);
    setMessage(null);
    const result = await window.clinic.doctor.uploadEvidence({
      handleId: evidence.handleId,
      caseId: status.caseId,
    });
    setBusy(false);
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      if (isUncertainOutcome(result.error.code)) {
        await refreshAuthoritative();
      }
      return;
    }
    setEvidence(null);
    setUploadId(result.value.uploadId);
  }

  async function submit(): Promise<void> {
    if (status?.caseVersion === null || status?.caseVersion === undefined) {
      return;
    }
    setBusy(true);
    setMessage(null);
    const result = await window.clinic.doctor.submitVerification({
      caseVersion: status.caseVersion,
      profileVersion: status.profileVersion,
    });
    setBusy(false);
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      if (isConflict(result.error.code) || isUncertainOutcome(result.error.code)) {
        await refreshAuthoritative();
      }
      return;
    }
    await refreshAuthoritative();
  }

  return (
    <Stack spacing={2} data-testid="verification-workspace">
      <Typography variant="h5" component="h2">
        {t.verification.title}
      </Typography>
      <Alert severity="info">{t.engineeringDefault}</Alert>
      <Typography>{t.noClinicalNav}</Typography>
      <Typography data-testid="verification-case-status">
        {t.verification.case}: {status?.caseStatus ? t.statuses[status.caseStatus] : t.statuses.none}
      </Typography>
      <Typography data-testid="profile-public-status">
        {t.profile.publicStatus}: {status?.profilePublicStatus ?? profile.publicStatus}
      </Typography>
      {status?.reasonCode ? (
        <Typography data-testid="verification-reason">
          {t.verification.reason}: {status.reasonCode}
        </Typography>
      ) : null}

      {approved ? (
        <Alert severity="success" role="status" aria-live="polite" data-testid="approved-status">
          {t.status.approvedActive}
        </Alert>
      ) : null}
      {pendingReview ? (
        <Alert severity="info" role="status" data-testid="pending-status">
          {t.status.pending}
        </Alert>
      ) : null}
      {status?.profileVerificationStatus === 'changes_requested' ? (
        <Alert severity="warning" role="status" data-testid="changes-requested-status">
          {t.status.changesRequested}
        </Alert>
      ) : null}
      {status?.profileVerificationStatus === 'rejected' ? (
        <Alert severity="error" role="status" data-testid="rejected-status">
          {t.status.rejected}
        </Alert>
      ) : null}
      {suspended ? (
        <Alert severity="error" role="status" data-testid="suspended-status">
          {t.status.suspended}
        </Alert>
      ) : null}

      {statusQuery.isSuccess && !status?.caseId && !pendingReview && !approved && !suspended ? (
        <Button type="button" variant="contained" disabled={busy} onClick={() => void openCase()}>
          {busy ? t.verification.opening : t.verification.openCase}
        </Button>
      ) : null}

      {canResubmit ? (
        <Button type="button" variant="outlined" disabled={busy} onClick={() => void openCase()}>
          {t.verification.newCase}
        </Button>
      ) : null}

      {draftCase ? (
        <Stack spacing={1}>
          <Button type="button" onClick={() => void chooseFile()}>
            {t.verification.selectEvidence}
          </Button>
          {evidence ? (
            <Typography>
              {t.verification.selectedFile}: {evidence.displayName} ({t.verification.size}{' '}
              {String(evidence.sizeBytes)}, {t.verification.type} {evidence.candidateMediaType})
            </Typography>
          ) : null}
          {evidence ? (
            <Button
              type="button"
              onClick={() => {
                void window.clinic.doctor.clearEvidence(evidence.handleId).then(() => setEvidence(null));
              }}
            >
              {t.verification.clearFile}
            </Button>
          ) : null}
          <Button type="button" variant="contained" disabled={busy || evidence === null} onClick={() => void upload()}>
            {busy ? t.verification.uploading : t.verification.upload}
          </Button>
        </Stack>
      ) : null}

      {reconciledUploadState ? (
        <Alert severity="info" role="status" aria-live="polite" data-testid="upload-status">
          {uploadStatusCopy(locale, reconciledUploadState)}
        </Alert>
      ) : null}

      {pollTimedOut && uploadId !== null && !evidenceReady ? (
        <Alert severity="warning" role="status" data-testid="upload-poll-timeout">
          {t.verification.pollTimedOut}
          <Button
            type="button"
            data-testid="upload-poll-retry"
            onClick={() => {
              setPollTimedOut(false);
              setPollGeneration((current) => current + 1);
            }}
          >
            {t.verification.retryPoll}
          </Button>
        </Alert>
      ) : null}

      {draftCase ? (
        <Button
          type="button"
          variant="contained"
          disabled={busy || !evidenceReady}
          aria-describedby={evidenceReady ? undefined : 'submit-disabled-reason'}
          onClick={() => void submit()}
        >
          {busy ? t.verification.submitting : t.verification.submit}
        </Button>
      ) : null}
      {draftCase && !evidenceReady ? (
        <Typography id="submit-disabled-reason">{t.verification.disabledUntilAvailable}</Typography>
      ) : null}

      <Button type="button" onClick={() => void refreshAuthoritative()}>
        {t.verification.refresh}
      </Button>
      {message ? (
        <Alert ref={alertRef} tabIndex={-1} severity="warning" role="alert" data-testid="verification-message">
          {message}
        </Alert>
      ) : null}
    </Stack>
  );
}
