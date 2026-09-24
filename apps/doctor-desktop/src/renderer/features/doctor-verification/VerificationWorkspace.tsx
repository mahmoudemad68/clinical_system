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
import {
  DOCTOR_REQUIREMENTS,
  applicantVisibleReasonCopy,
  requiredDocumentsReady,
  requirementLabel,
  type DoctorRequirement,
  type DoctorRequirementCode,
} from '@clinic/verification-policy';
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

type SlotState = {
  evidence: Extract<DoctorEvidenceSelectResponse, { selected: true }> | null;
  uploadId: string | null;
  pollTimedOut: boolean;
  pollGeneration: number;
};

function emptySlots(): Record<DoctorRequirementCode, SlotState> {
  return {
    medical_license: { evidence: null, uploadId: null, pollTimedOut: false, pollGeneration: 0 },
    national_id_or_passport: { evidence: null, uploadId: null, pollTimedOut: false, pollGeneration: 0 },
    syndicate_card: { evidence: null, uploadId: null, pollTimedOut: false, pollGeneration: 0 },
  };
}

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

function documentForRequirement(
  status: DoctorVerificationStatus | undefined,
  code: DoctorRequirementCode,
) {
  return status?.documents.find((document) => document.requirementCode === code);
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
  const [slots, setSlots] = useState(emptySlots);
  const [busy, setBusy] = useState(false);
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

  const status: DoctorVerificationStatus | undefined = statusQuery.data;
  const evidenceReady = requiredDocumentsReady(status?.documents ?? [], DOCTOR_REQUIREMENTS);
  const canResubmit =
    status?.profileVerificationStatus === 'changes_requested' ||
    status?.profileVerificationStatus === 'rejected';
  const pendingReview = status?.profileVerificationStatus === 'pending_review';
  const approved = status?.profileVerificationStatus === 'approved';
  const suspended = status?.profileVerificationStatus === 'suspended';
  const draftCase = status?.caseStatus === 'draft';
  const reasonCopy =
    status?.applicantSafeExplanation && status.applicantSafeExplanation.length > 0
      ? status.applicantSafeExplanation
      : applicantVisibleReasonCopy(status?.reasonCode, locale);

  useEffect(() => {
    if (message) {
      alertRef.current?.focus();
    }
  }, [message]);

  useEffect(() => {
    if (approved) {
      void client.invalidateQueries({ queryKey: ['doctor', 'profile'] });
    }
  }, [approved, client]);

  async function refreshAuthoritative(): Promise<void> {
    await statusQuery.refetch();
    await client.invalidateQueries({ queryKey: ['doctor', 'profile'] });
  }

  async function openCase(): Promise<void> {
    setBusy(true);
    setMessage(null);
    setSlots(emptySlots());
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

  async function chooseFile(code: DoctorRequirementCode): Promise<void> {
    setMessage(null);
    const result = await window.clinic.doctor.selectEvidence({ requirementCode: code });
    if (!result.ok) {
      setMessage(doctorErrorMessage(locale, result.error.code));
      return;
    }
    if (!result.value.selected) {
      return;
    }
    setSlots((current) => ({
      ...current,
      [code]: { ...current[code], evidence: result.value, uploadId: null, pollTimedOut: false },
    }));
  }

  async function upload(code: DoctorRequirementCode): Promise<void> {
    const evidence = slots[code].evidence;
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
    setSlots((current) => ({
      ...current,
      [code]: { ...current[code], evidence: null, uploadId: result.value.uploadId, pollTimedOut: false },
    }));
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
      <Alert severity="info">{t.verificationPolicyNote}</Alert>
      <Typography>{t.noClinicalNav}</Typography>
      <Typography data-testid="verification-case-status">
        {t.verification.case}: {status?.caseStatus ? t.statuses[status.caseStatus] : t.statuses.none}
      </Typography>
      <Typography data-testid="profile-public-status">
        {t.profile.publicStatus}: {status?.profilePublicStatus ?? profile.publicStatus}
      </Typography>
      {reasonCopy ? (
        <Typography data-testid="verification-reason">
          {t.verification.reason}: {reasonCopy}
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

      {draftCase
        ? DOCTOR_REQUIREMENTS.map((requirement) => (
            <RequirementSlot
              key={requirement.code}
              locale={locale}
              requirement={requirement}
              slot={slots[requirement.code]}
              status={status}
              busy={busy}
              onChoose={() => void chooseFile(requirement.code)}
              onClear={() => {
                const handleId = slots[requirement.code].evidence?.handleId;
                if (handleId === undefined) {
                  return;
                }
                void window.clinic.doctor.clearEvidence(handleId).then(() => {
                  setSlots((current) => ({
                    ...current,
                    [requirement.code]: { ...current[requirement.code], evidence: null },
                  }));
                });
              }}
              onUpload={() => void upload(requirement.code)}
              onRetryPoll={() => {
                setSlots((current) => ({
                  ...current,
                  [requirement.code]: {
                    ...current[requirement.code],
                    pollTimedOut: false,
                    pollGeneration: current[requirement.code].pollGeneration + 1,
                  },
                }));
              }}
              onPollTimeout={() => {
                setSlots((current) => ({
                  ...current,
                  [requirement.code]: { ...current[requirement.code], pollTimedOut: true },
                }));
              }}
            />
          ))
        : null}

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

function RequirementSlot({
  locale,
  requirement,
  slot,
  status,
  busy,
  onChoose,
  onClear,
  onUpload,
  onRetryPoll,
  onPollTimeout,
}: {
  locale: Locale;
  requirement: DoctorRequirement;
  slot: SlotState;
  status: DoctorVerificationStatus | undefined;
  busy: boolean;
  onChoose: () => void;
  onClear: () => void;
  onUpload: () => void;
  onRetryPoll: () => void;
  onPollTimeout: () => void;
}) {
  const t = doctorStrings[locale];
  const document = documentForRequirement(status, requirement.code);
  const uploadQuery = useQuery({
    queryKey: ['doctor', 'upload', requirement.code, slot.uploadId, locale, slot.pollGeneration],
    enabled: slot.uploadId !== null,
    queryFn: async () => {
      if (slot.uploadId === null) {
        throw new Error('NOT_FOUND');
      }
      const result = await window.clinic.doctor.uploadStatus(slot.uploadId);
      if (!result.ok) {
        throw new Error(result.error.code);
      }
      return result.value;
    },
    refetchInterval: (query) => {
      if (slot.pollTimedOut) {
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
    if (slot.uploadId === null) {
      return;
    }
    const timer = window.setTimeout(() => {
      onPollTimeout();
    }, UPLOAD_POLL_TIMEOUT_MS);
    return () => window.clearTimeout(timer);
  }, [slot.uploadId, slot.pollGeneration, onPollTimeout]);

  const reconciledUploadState: DoctorUploadStatus['state'] | null =
    uploadQuery.data?.state ??
    (document === undefined
      ? null
      : document.status === 'available'
        ? 'available'
        : document.status === 'rejected'
          ? 'rejected'
          : 'quarantined');
  const label = requirementLabel(requirement, locale);
  const requiredLabel = requirement.required ? t.verification.required : t.verification.optional;

  return (
    <Stack spacing={1} data-testid={`requirement-slot-${requirement.code}`}>
      <Typography>
        {label} ({requiredLabel})
      </Typography>
      <Button
        type="button"
        onClick={onChoose}
        data-testid={`select-evidence-${requirement.code}`}
      >
        {t.verification.selectEvidence}: {label}
      </Button>
      {slot.evidence ? (
        <Typography>
          {t.verification.selectedFile}: {slot.evidence.displayName} ({t.verification.size}{' '}
          {String(slot.evidence.sizeBytes)}, {t.verification.type} {slot.evidence.candidateMediaType})
        </Typography>
      ) : null}
      {slot.evidence ? (
        <Button type="button" onClick={onClear}>
          {t.verification.clearFile}
        </Button>
      ) : null}
      <Button
        type="button"
        variant="contained"
        disabled={busy || slot.evidence === null}
        data-testid={`upload-evidence-${requirement.code}`}
        onClick={onUpload}
      >
        {busy ? t.verification.uploading : t.verification.upload}
      </Button>
      {reconciledUploadState ? (
        <Alert
          severity="info"
          role="status"
          aria-live="polite"
          data-testid={`upload-status-${requirement.code}`}
        >
          {uploadStatusCopy(locale, reconciledUploadState)}
        </Alert>
      ) : null}
      {slot.pollTimedOut && slot.uploadId !== null && uploadQuery.data?.state !== 'available' ? (
        <Alert severity="warning" role="status" data-testid={`upload-poll-timeout-${requirement.code}`}>
          {t.verification.pollTimedOut}
          <Button type="button" data-testid={`upload-poll-retry-${requirement.code}`} onClick={onRetryPoll}>
            {t.verification.retryPoll}
          </Button>
        </Alert>
      ) : null}
    </Stack>
  );
}
