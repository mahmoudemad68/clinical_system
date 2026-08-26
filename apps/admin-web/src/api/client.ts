import createClient from 'openapi-fetch';
import type { paths } from '@clinic/api-client/schema';

/**
 * The single transport wrapper for the admin application.
 *
 * One place owns credentials, CSRF, request IDs, and error mapping (phase file,
 * "React baseline"). Feature code never calls `fetch` directly: a second
 * transport path is how one request quietly ships without the CSRF header or
 * without credentials.
 *
 * Types come from the generated OpenAPI schema, so a contract change that
 * breaks this client is a compile error rather than a runtime surprise.
 */

/** Stable machine codes the server may return. Branch on these, never on the message. */
export type ErrorCode =
  paths['/api/v1/health']['get']['responses']['500']['content']['application/json']['errors'][number]['code'];

export interface ApiFailure {
  readonly code: ErrorCode | 'NETWORK_ERROR';
  readonly message: string;
  readonly field?: string | undefined;
  /** Correlation id. The only handle support needs to find the trace. */
  readonly requestId?: string | undefined;
  readonly status: number;
}

export class ApiError extends Error {
  constructor(readonly failure: ApiFailure) {
    super(failure.message);
    this.name = 'ApiError';
  }
}

/**
 * Admin authentication is a secure HTTP-only session cookie, never a token in
 * local storage (plan.md section 5). `credentials: 'same-origin'` is what
 * actually sends it, and it is set here so no caller can forget.
 */
/**
 * Absolute origin for API calls.
 *
 * A relative base such as '/' works in a browser, which resolves it against the
 * document origin, but fails anywhere without one — Node, jsdom, and any
 * server-side render — with "Failed to parse URL". Resolving to an absolute
 * origin here keeps one code path for every environment.
 *
 * Same-origin by default so the session cookie is sent; VITE_API_BASE_URL
 * exists for a deployment that fronts the API on a different host, which then
 * also needs that origin in the server's CORS allow-list.
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
  // Dereference globalThis.fetch per call rather than letting the client
  // capture it at construction time. Capturing it binds whatever `fetch`
  // existed when this module first loaded, which makes the transport
  // impossible to substitute in a test and impossible to wrap later without
  // editing this file. The indirection costs nothing at runtime.
  fetch: (...args: Parameters<typeof globalThis.fetch>) => globalThis.fetch(...args),
});

apiClient.use({
  onRequest({ request }) {
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
 * The server never sends a stack trace or internal detail, so whatever arrives
 * is safe to display. A network failure gets a synthetic code so callers do not
 * have to distinguish "no response" from "error response".
 */
export function toApiFailure(error: unknown, status = 0): ApiFailure {
  if (error && typeof error === 'object' && 'errors' in error) {
    const body = error as {
      errors?: { code: string; message: string; field?: string }[];
      request_id?: string;
    };
    const first = body.errors?.[0];

    if (first) {
      return {
        code: first.code as ErrorCode,
        message: first.message,
        field: first.field,
        requestId: body.request_id,
        status,
      };
    }
  }

  return {
    code: 'NETWORK_ERROR',
    message: 'The service could not be reached.',
    status,
  };
}
