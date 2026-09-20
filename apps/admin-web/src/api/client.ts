import createClient from 'openapi-fetch';
import type { paths } from '@clinic/api-client/schema';
import { uuidV7 } from '@clinic/api-client';
import {
  ApiError,
  isApiErrorCode,
  toApiFailure as toSharedApiFailure,
  type ApiFailure,
} from '@clinic/error-handling';
import { clientLocale } from './locale';

export { ApiError, type ApiFailure };

/**
 * The single transport wrapper for the admin application.
 *
 * One place owns credentials, CSRF, request IDs, and error mapping. Feature
 * code never calls `fetch` directly: a second transport path is how one request
 * quietly ships without the CSRF header or without credentials.
 *
 * Types come from the generated OpenAPI schema, so a contract change that
 * breaks this client is a compile error rather than a runtime surprise.
 */

function resolveBaseUrl(): string {
  const configured = import.meta.env.VITE_API_BASE_URL;

  if (typeof configured === 'string' && configured !== '') {
    return configured;
  }

  return typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
}

export const apiClient = createClient<paths>({
  baseUrl: resolveBaseUrl(),
  credentials: 'same-origin',
  headers: {
    Accept: 'application/json',
  },
  fetch: (...args: Parameters<typeof globalThis.fetch>) => globalThis.fetch(...args),
});

apiClient.use({
  onRequest({ request }) {
    request.headers.set('Accept-Language', clientLocale());
    if (!request.headers.has('X-Request-Id')) {
      request.headers.set('X-Request-Id', uuidV7());
    }

    if (request.method !== 'GET' && request.method !== 'HEAD' && request.method !== 'OPTIONS') {
      for (const [header, value] of Object.entries(csrfHeader())) {
        request.headers.set(header, value);
      }
    }
  },
});

/**
 * Read the CSRF token the server set as a cookie and echo it as a header.
 *
 * The cookie is readable by design; the header is what a cross-site request
 * cannot forge, because a third-party page cannot read our cookie to copy it.
 */
export function csrfHeader(): Record<string, string> {
  const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);

  return match?.[1] ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

/**
 * Normalize any failure into one shape the UI can render.
 *
 * Branch on machine codes, never on message text. Unknown server codes stay
 * out of the typed union and are shown with a generic i18n fallback plus
 * request id.
 */
export function toApiFailure(error: unknown, status = 0): ApiFailure {
  if (error && typeof error === 'object' && 'errors' in error) {
    const body = error as {
      errors?: { code?: unknown; message?: unknown; field?: unknown }[];
      request_id?: unknown;
    };
    const first = body.errors?.[0];

    if (first && typeof first.code === 'string' && isApiErrorCode(first.code)) {
      return {
        code: first.code,
        message: typeof first.message === 'string' ? first.message : 'The request failed.',
        status,
        ...(typeof first.field === 'string' ? { field: first.field } : {}),
        ...(typeof body.request_id === 'string' ? { requestId: body.request_id } : {}),
      };
    }
  }

  return toSharedApiFailure(error, status);
}

export function isAuthFailure(failure: ApiFailure): boolean {
  return (
    failure.status === 401 ||
    failure.code === 'UNAUTHENTICATED' ||
    failure.code === 'TOKEN_EXPIRED'
  );
}

export function isAuthError(error: unknown): boolean {
  return error instanceof ApiError && isAuthFailure(error.failure);
}
