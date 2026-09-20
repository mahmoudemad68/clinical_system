import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { components } from '@clinic/api-client/schema';
import { ApiError } from '@/api/client';
import {
  grantAndDownloadReviewerDocument,
  ReviewerDownloadError,
} from '@/api/downloadReviewerDocument';
import { SafeError } from '@/app/SafeError';
import { StatusChip } from '@/features/verification/StatusChip';

type ReviewDocument = components['schemas']['AdminVerificationReviewDocument'];

function formatBytes(size: number): string {
  if (size < 1024) {
    return `${String(size)} B`;
  }

  return `${(size / 1024).toFixed(1)} KB`;
}

interface DocumentListProps {
  caseId: string;
  documents: readonly ReviewDocument[];
  canAccess: boolean;
}

export function DocumentList({ caseId, documents, canAccess }: DocumentListProps) {
  const { t, i18n } = useTranslation();
  const [busyId, setBusyId] = useState<string | null>(null);
  const [grantedId, setGrantedId] = useState<string | null>(null);
  const [failure, setFailure] = useState<{ documentId: string; error: ApiError } | null>(null);

  if (documents.length === 0) {
    return (
      <Alert severity="info" role="status">
        {t('case.noDocuments')}
      </Alert>
    );
  }

  return (
    <Stack spacing={2} component="section" aria-labelledby="documents-heading">
      <Typography id="documents-heading" variant="h6" component="h2">
        {t('case.documents')}
      </Typography>
      {documents.map((document) => (
        <Stack
          key={document.document_id}
          spacing={1}
          sx={{ border: 1, borderColor: 'divider', borderRadius: 1, p: 2 }}
        >
          <Typography>
            {t('case.requirement')}: {document.requirement_code}
          </Typography>
          <Typography>
            {t('case.detectedType')}: {document.detected_mime}
          </Typography>
          <Typography>
            {t('case.size')}: {formatBytes(document.size_bytes)}
          </Typography>
          <Typography>
            {t('case.uploaded')}:{' '}
            <time dateTime={document.uploaded_at}>
              {new Date(document.uploaded_at).toLocaleString(
                (i18n.resolvedLanguage ?? 'en').startsWith('ar') ? 'ar' : 'en',
              )}
            </time>
          </Typography>
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
            <StatusChip value={document.status} />
            <StatusChip value={document.scan_status} />
          </Stack>
          <details>
            <summary>{t('case.integrity')}</summary>
            <Typography variant="caption" component="p" sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>
              {document.sha256}
            </Typography>
          </details>
          {canAccess ? (
            <Button
              type="button"
              variant="outlined"
              disabled={busyId === document.document_id}
              onClick={() => {
                setFailure(null);
                setBusyId(document.document_id);
                void grantAndDownloadReviewerDocument({
                  caseId,
                  documentId: document.document_id,
                  onGranted: () => {
                    setGrantedId(document.document_id);
                  },
                })
                  .catch((error: unknown) => {
                    const apiError =
                      error instanceof ApiError
                        ? error
                        : new ApiError({
                            code: 'DEPENDENCY_UNAVAILABLE',
                            message: 'The document could not be downloaded.',
                            status: 0,
                          });
                    setFailure({ documentId: document.document_id, error: apiError });
                    if (error instanceof ReviewerDownloadError && error.grantIssued) {
                      setGrantedId(document.document_id);
                    }
                  })
                  .finally(() => {
                    setBusyId(null);
                  });
              }}
            >
              {busyId === document.document_id ? t('case.accessing') : t('case.viewDocument')}
            </Button>
          ) : null}
          {busyId === document.document_id ? (
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }} aria-busy="true" aria-live="polite">
              <CircularProgress size={16} aria-hidden />
              <Typography variant="body2">{t('case.accessing')}</Typography>
            </Stack>
          ) : null}
          {grantedId === document.document_id ? (
            <Alert severity="success" role="status">
              {t('case.accessRecorded')} {t('case.accessRecordedHint')}
            </Alert>
          ) : null}
          {failure?.documentId === document.document_id ? (
            <SafeError failure={failure.error.failure} fallbackKey="case.downloadFailed" />
          ) : null}
        </Stack>
      ))}
    </Stack>
  );
}
