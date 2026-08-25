/**
 * Normalized failures shared by the admin web app and both Electron desktops.
 *
 * Shared deliberately and narrowly. These are pure types and pure functions
 * with no transport, no storage, and no privilege: exactly the category ADR
 * 0010 permits across the admin/desktop boundary. Anything that touches
 * credentials, cookies, IPC, or the filesystem stays in its own application,
 * because admin uses cookie/CSRF semantics and desktop uses device tokens
 * behind an IPC boundary.
 */

/**
 * Stable machine codes the server may return.
 *
 * Mirrors the `ErrorCode` enum in `packages/contracts/openapi/openapi.yaml`.
 * Clients branch on the code, never on the message: messages are localized and
 * may be reworded without that being a breaking change.
 */
export const API_ERROR_CODES = [
  'MALFORMED_REQUEST',
  'UNSUPPORTED_MEDIA_TYPE',
  'REQUEST_TOO_LARGE',
  'UNAUTHENTICATED',
  'TOKEN_EXPIRED',
  'PERMISSION_DENIED',
  'NOT_FOUND',
  'STATE_CONFLICT',
  'VERSION_CONFLICT',
  'IDEMPOTENCY_KEY_REUSED',
  'IDEMPOTENCY_IN_PROGRESS',
  'VALIDATION_FAILED',
  'CURSOR_INVALID',
  'UNSUPPORTED_SCHEMA_VERSION',
  'RATE_LIMITED',
  'DEPENDENCY_UNAVAILABLE',
  'INTERNAL_ERROR',
] as const;

export type ApiErrorCode = (typeof API_ERROR_CODES)[number];

/** Synthesised locally when no response arrived at all. */
export type ClientErrorCode = 'NETWORK_UNAVAILABLE';

export type FailureCode = ApiErrorCode | ClientErrorCode;

export interface ApiFailure {
  readonly code: FailureCode;
  /** Safe, localized message from the server. Never a stack trace. */
  readonly message: string;
  /** 0 when no response arrived. */
  readonly status: number;
  /** Field path for a validation failure, for binding to a form control. */
  readonly field?: string | undefined;
  /** Correlation id — the only handle support needs to find the trace. */
  readonly requestId?: string | undefined;
}

export class ApiError extends Error {
  constructor(readonly failure: ApiFailure) {
    super(failure.message);
    this.name = 'ApiError';
  }
}

export function isApiErrorCode(value: unknown): value is ApiErrorCode {
  return typeof value === 'string' && (API_ERROR_CODES as readonly string[]).includes(value);
}

/** Normalize an unknown error-envelope body into one shape the UI can render. */
export function toApiFailure(body: unknown, status = 0): ApiFailure {
  if (body && typeof body === 'object' && 'errors' in body) {
    const envelope = body as {
      errors?: Array<{ code?: unknown; message?: unknown; field?: unknown }>;
      request_id?: unknown;
    };
    const first = envelope.errors?.[0];

    if (first && isApiErrorCode(first.code)) {
      return {
        code: first.code,
        message: typeof first.message === 'string' ? first.message : 'The request failed.',
        status,
        field: typeof first.field === 'string' ? first.field : undefined,
        requestId: typeof envelope.request_id === 'string' ? envelope.request_id : undefined,
      };
    }
  }

  return { code: 'NETWORK_UNAVAILABLE', message: 'The service could not be reached.', status };
}

export const isAuthenticationFailure = (f: ApiFailure): boolean =>
  f.code === 'UNAUTHENTICATED' || f.code === 'TOKEN_EXPIRED';

export const isValidationFailure = (f: ApiFailure): boolean => f.code === 'VALIDATION_FAILED';

export const isConflictFailure = (f: ApiFailure): boolean =>
  f.code === 'STATE_CONFLICT' ||
  f.code === 'VERSION_CONFLICT' ||
  f.code === 'IDEMPOTENCY_KEY_REUSED' ||
  f.code === 'IDEMPOTENCY_IN_PROGRESS';

/**
 * Should a failed request be retried automatically?
 *
 * `isIdempotent` must be true only for a safe method or a request carrying an
 * `Idempotency-Key`. It has no default: a blind retry of a non-idempotent write
 * is how a patient gets two appointments or a customer is charged twice
 * (plan.md section 152), so every caller must state which it is.
 */
export function shouldRetry(
  failure: ApiFailure,
  attempt: number,
  isIdempotent: boolean,
  maxAttempts = 3,
): boolean {
  if (attempt >= maxAttempts) {
    return false;
  }

  switch (failure.code) {
    case 'NETWORK_UNAVAILABLE':
      return isIdempotent;
    case 'INTERNAL_ERROR':
    case 'DEPENDENCY_UNAVAILABLE':
      return isIdempotent && failure.status >= 500;
    case 'RATE_LIMITED':
    case 'IDEMPOTENCY_IN_PROGRESS':
      // Throttling explicitly invites a later retry, and an in-progress
      // duplicate should be polled rather than restarted.
      return true;
    default:
      // Every other code is a decision the server already made. Retrying an
      // authorization denial or a validation failure adds load and never wins.
      return false;
  }
}

/** Exponential backoff, capped, with full jitter. */
export function retryDelayMs(attempt: number, baseMs = 300, maxMs = 10_000, random = Math.random): number {
  const ceiling = Math.min(baseMs * 2 ** attempt, maxMs);

  // Full jitter, not a fixed delay: without it every client that failed during
  // an outage retries in the same instant and re-topples the recovering service.
  return Math.floor(random() * (ceiling + 1));
}
