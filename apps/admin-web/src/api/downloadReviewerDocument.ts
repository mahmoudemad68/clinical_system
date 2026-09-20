import { ApiError, apiClient, toApiFailure } from '@/api/client';
import { registerBlobUrl, unregisterBlobUrl } from '@/api/blobUrls';

export class ReviewerDownloadError extends ApiError {
  readonly grantIssued: boolean;

  constructor(failure: ApiError['failure'], grantIssued: boolean) {
    super(failure);
    this.name = 'ReviewerDownloadError';
    this.grantIssued = grantIssued;
  }
}

function genericFilename(mime: string): string {
  if (mime === 'application/pdf') {
    return 'verification-document.pdf';
  }
  if (mime === 'image/jpeg') {
    return 'verification-document.jpg';
  }
  if (mime === 'image/png') {
    return 'verification-document.png';
  }

  return 'verification-document.bin';
}

function filenameFromDisposition(header: string | null, mime: string): string {
  const fallback = genericFilename(mime);
  if (header === null || header === '') {
    return fallback;
  }

  const match = /filename\*?=(?:UTF-8'')?"?([A-Za-z0-9._-]+)"?/i.exec(header);
  const name = match?.[1];
  if (name !== undefined && /^verification-document\.[A-Za-z0-9]+$/.test(name)) {
    return name;
  }

  return fallback;
}

function triggerAttachmentDownload(objectUrl: string, filename: string): void {
  const anchor = document.createElement('a');
  anchor.href = objectUrl;
  anchor.download = filename;
  anchor.rel = 'noopener noreferrer';
  anchor.style.display = 'none';
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
}

/**
 * Rewrite an application-owned reviewer URL onto the current origin.
 *
 * The grant returns an absolute URL. The browser must consume it as a
 * same-trusted-application GET (Vite proxy or same host), never as navigation
 * to an arbitrary backend-controlled host, and never via an iframe or a new browsing context.
 */
function applicationReviewerDownloadUrl(signedUrl: string): string {
  let parsed: URL;
  try {
    parsed = new URL(signedUrl);
  } catch {
    throw new ReviewerDownloadError(
      {
        code: 'NOT_FOUND',
        message: 'The document could not be downloaded.',
        status: 0,
      },
      true,
    );
  }

  if (
    parsed.username !== '' ||
    parsed.password !== '' ||
    !/^\/api\/v1\/verification-review-files\/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
      parsed.pathname,
    )
  ) {
    throw new ReviewerDownloadError(
      {
        code: 'NOT_FOUND',
        message: 'The document could not be downloaded.',
        status: 0,
      },
      true,
    );
  }

  const origin = typeof window === 'undefined' ? parsed.origin : window.location.origin;
  return `${origin}${parsed.pathname}${parsed.search}`;
}

/**
 * Consume a short-lived application-signed reviewer URL.
 *
 * The URL exists only in this function scope. It is never written to React
 * Query, component state, storage, the document, or logs.
 */
async function downloadSignedReviewerFile(
  signedUrl: string,
  mime: string,
  expectedBytes: number,
): Promise<void> {
  const response = await globalThis.fetch(applicationReviewerDownloadUrl(signedUrl), {
    method: 'GET',
    cache: 'no-store',
    referrerPolicy: 'no-referrer',
    credentials: 'omit',
    redirect: 'error',
  });

  if (!response.ok) {
    throw new ReviewerDownloadError(
      {
        code: response.status === 401 ? 'UNAUTHENTICATED' : 'NOT_FOUND',
        message: 'The document could not be downloaded.',
        status: response.status,
      },
      true,
    );
  }

  const buffer = await response.arrayBuffer();
  const contentLengthHeader = response.headers.get('Content-Length');
  const declaredLength =
    contentLengthHeader !== null && contentLengthHeader !== ''
      ? Number.parseInt(contentLengthHeader, 10)
      : expectedBytes;

  if (
    !Number.isFinite(declaredLength) ||
    buffer.byteLength !== expectedBytes ||
    buffer.byteLength !== declaredLength
  ) {
    throw new ReviewerDownloadError(
      {
        code: 'DEPENDENCY_UNAVAILABLE',
        message: 'The document could not be downloaded.',
        status: response.status,
      },
      true,
    );
  }

  const filename = filenameFromDisposition(response.headers.get('Content-Disposition'), mime);
  const blob = new Blob([buffer], { type: mime });
  const objectUrl = URL.createObjectURL(blob);
  registerBlobUrl(objectUrl);

  try {
    triggerAttachmentDownload(objectUrl, filename);
  } finally {
    URL.revokeObjectURL(objectUrl);
    unregisterBlobUrl(objectUrl);
  }
}

/**
 * Explicit reviewer document access: one click mints one grant, then the
 * signed URL is consumed immediately as a same-trusted-application download.
 */
export async function grantAndDownloadReviewerDocument(input: {
  caseId: string;
  documentId: string;
  onGranted?: () => void;
}): Promise<void> {
  await apiClient.GET('/api/v1/auth/csrf');
  const { data, error, response } = await apiClient.POST(
    '/api/v1/admin/verification-cases/{case_id}/documents/{document_id}/access',
    {
      params: {
        path: {
          case_id: input.caseId,
          document_id: input.documentId,
        },
      },
      body: {},
    },
  );

  if (error || !data.data) {
    throw new ReviewerDownloadError(toApiFailure(error, response.status), false);
  }

  input.onGranted?.();

  const signedUrl = data.data.url;
  const mime = data.data.detected_mime;
  const expectedBytes = data.data.size_bytes;

  await downloadSignedReviewerFile(signedUrl, mime, expectedBytes);
}
