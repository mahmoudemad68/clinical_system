/**
 * Validate a Core-issued verification upload target before main sends bytes.
 *
 * The renderer never supplies this URL. Only the authenticated Core create
 * response for this exact upload intent may be consumed. The URL is not logged
 * and is not persisted.
 */

export class UploadTargetError extends Error {
  constructor(readonly code: 'INVALID_REQUEST' | 'UPSTREAM_FAILED' = 'UPSTREAM_FAILED') {
    super(code);
    this.name = 'UploadTargetError';
  }
}

export type IssuedUploadTarget = {
  method: 'PUT';
  url: string;
  headers: Record<string, string>;
};

const HEADER_NAME = /^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/;
const MAX_HEADER_VALUE = 4_096;
const MAX_HEADER_COUNT = 16;

export function parseIssuedUploadTarget(
  raw: unknown,
  isPackaged: boolean,
): IssuedUploadTarget {
  if (typeof raw !== 'object' || raw === null) {
    throw new UploadTargetError();
  }
  const record = raw as Record<string, unknown>;
  if (record['method'] !== 'PUT') {
    throw new UploadTargetError();
  }
  if (typeof record['url'] !== 'string' || record['url'].length === 0 || record['url'].length > 4_096) {
    throw new UploadTargetError();
  }

  let parsed: URL;
  try {
    parsed = new URL(record['url']);
  } catch {
    throw new UploadTargetError();
  }

  if (parsed.username !== '' || parsed.password !== '') {
    throw new UploadTargetError();
  }

  const localhost = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
  if (parsed.protocol === 'https:') {
    // Packaged and unpackaged HTTPS targets from Core are acceptable.
  } else if (parsed.protocol === 'http:' && !isPackaged && localhost) {
    // Local MinIO/emulator only.
  } else {
    throw new UploadTargetError();
  }

  const headers = parseIssuedHeaders(record['headers']);
  return {
    method: 'PUT',
    url: parsed.toString(),
    headers,
  };
}

function parseIssuedHeaders(raw: unknown): Record<string, string> {
  if (raw === undefined) {
    return {};
  }
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    throw new UploadTargetError();
  }
  const entries = Object.entries(raw as Record<string, unknown>);
  if (entries.length > MAX_HEADER_COUNT) {
    throw new UploadTargetError();
  }
  const headers: Record<string, string> = {};
  for (const [name, value] of entries) {
    if (!HEADER_NAME.test(name) || typeof value !== 'string' || value.length > MAX_HEADER_VALUE) {
      throw new UploadTargetError();
    }
    const lower = name.toLowerCase();
    if (lower === 'host' || lower === 'connection' || lower === 'transfer-encoding') {
      throw new UploadTargetError();
    }
    headers[name] = value;
  }
  return headers;
}
