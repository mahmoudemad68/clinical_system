/**
 * Generated OpenAPI types and pure envelope mapping.
 *
 * **This package does not export a configured client, and that is deliberate.**
 * The three TypeScript surfaces authenticate differently:
 *
 *   admin web        HttpOnly session cookie + CSRF header, same-origin
 *   doctor desktop   device token held in the Electron main process
 *   pharmacy desktop device token, separate credential namespace
 *
 * A shared, pre-configured transport would have to satisfy all three, which in
 * practice means satisfying none of them safely — and it would put an
 * authenticated HTTP client on the renderer's dependency graph, which ADR 0010
 * forbids outright. Each application owns its adapter; this package owns the
 * types and the mapping both adapters need.
 */

import { toApiFailure, type ApiFailure } from '@clinic/error-handling';

export type { paths, components, operations } from './generated/schema';

/** The response envelope every endpoint uses (plan.md section 106). */
export interface Envelope<T> {
  data: T;
  meta: Record<string, unknown>;
  errors: unknown[];
  request_id: string;
}

/**
 * Unwrap a success envelope.
 *
 * Returns a discriminated result rather than throwing, so a caller in a
 * renderer, a main process, or a test handles one shape. Throwing across an IPC
 * boundary loses the error type anyway.
 */
export function unwrapEnvelope<T>(
  body: unknown,
  status: number,
): { ok: true; data: T; requestId: string } | { ok: false; failure: ApiFailure } {
  if (status >= 200 && status < 300 && body && typeof body === 'object' && 'data' in body) {
    const envelope = body as Envelope<T>;

    if (envelope.data !== undefined && envelope.data !== null) {
      return {
        ok: true,
        data: envelope.data,
        requestId: typeof envelope.request_id === 'string' ? envelope.request_id : '',
      };
    }
  }

  return { ok: false, failure: toApiFailure(body, status) };
}

/**
 * Generate a UUIDv7 for correlation and idempotency.
 *
 * v7, not v4: the server accepts a client `X-Request-Id` only when it is a
 * well-formed UUIDv7, and silently replaces anything else. A v4 still works but
 * loses the ability to tie a client log line to a server trace.
 *
 * Uses `crypto.getRandomValues`, which exists in browsers, Node 19+, and
 * Electron. `Math.random` would be unacceptable for an idempotency key: a
 * predictable key is one an attacker can collide with to block or replay
 * someone else's operation.
 */
export function uuidV7(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);

  const timestamp = BigInt(Date.now());
  for (let i = 0; i < 6; i++) {
    bytes[i] = Number((timestamp >> BigInt(8 * (5 - i))) & 0xffn);
  }

  // Version 7 and the RFC 4122 variant bits.
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x70;
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;

  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

  return [
    hex.slice(0, 8),
    hex.slice(8, 12),
    hex.slice(12, 16),
    hex.slice(16, 20),
    hex.slice(20, 32),
  ].join('-');
}

/**
 * Headers every request carries regardless of which application sends it.
 *
 * Authentication is absent on purpose: it is the part that differs per
 * application, and the type system should not let a caller believe this
 * function authenticated the request.
 */
export function baseRequestHeaders(locale: string, requestId = uuidV7()): Record<string, string> {
  return {
    Accept: 'application/json',
    'Accept-Language': locale,
    'X-Request-Id': requestId,
  };
}
