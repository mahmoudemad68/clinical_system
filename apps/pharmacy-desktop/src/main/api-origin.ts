/**
 * Packaged API exact-origin allowlisting.
 *
 * Runtime `CLINIC_API_BASE_URL` selects which origin this process will call.
 * It is never the authority that decides which origins are trusted. Trusted
 * origins are the compile-time packed list passed in as
 * `packagedAllowedOrigins` — webpack bakes that list from a build-time env
 * that this module does not read.
 *
 * Comparison is `URL.origin` (scheme + hostname + effective port), never a
 * hostname suffix or substring match.
 */

export const DEVELOPMENT_API_BASE_URL = 'http://localhost:8080';

export type ApiOriginFailureCode =
  | 'INSECURE_TRANSPORT'
  | 'ORIGIN_REFUSED'
  | 'PACKAGED_ALLOWLIST_MISSING';

export class ApiOriginError extends Error {
  readonly code: ApiOriginFailureCode;

  constructor(code: ApiOriginFailureCode) {
    super(code);
    this.name = 'ApiOriginError';
    this.code = code;
  }
}

export type ResolveApiBaseUrlInput = {
  configuredUrl: string | undefined;
  isPackaged: boolean;
  packagedAllowedOrigins: readonly string[];
};

/**
 * Parse a configured API base as origin-only.
 *
 * Rejects credentials, query, hash, and any path other than `/`. The desktop
 * transport concatenates `/api/v1/...` onto this origin; a path on the base
 * would change those semantics.
 */
export function parseApiBaseCandidate(raw: string): URL {
  let url: URL;
  try {
    url = new URL(raw.trim());
  } catch {
    throw new ApiOriginError('ORIGIN_REFUSED');
  }

  if (url.username !== '' || url.password !== '') {
    throw new ApiOriginError('ORIGIN_REFUSED');
  }

  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new ApiOriginError('ORIGIN_REFUSED');
  }

  if (url.pathname !== '/' && url.pathname !== '') {
    throw new ApiOriginError('ORIGIN_REFUSED');
  }

  if (url.search !== '' || url.hash !== '') {
    throw new ApiOriginError('ORIGIN_REFUSED');
  }

  return url;
}

/**
 * Normalize one packaged allowlist entry to an HTTPS exact origin, or `null`
 * if it is not a valid packaged origin.
 */
export function normalizePackagedHttpsOrigin(raw: string): string | null {
  try {
    const url = parseApiBaseCandidate(raw);
    if (url.protocol !== 'https:') {
      return null;
    }
    return url.origin;
  } catch {
    return null;
  }
}

/**
 * Parse the comma-separated build-time allowlist.
 *
 * An empty string yields an empty list (packaged runtime fails closed).
 * A present but invalid entry fails the package/build rather than baking
 * a usable-looking wrong origin.
 */
export function parsePackagedAllowlistEntries(raw: string): string[] {
  const parts = raw
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part.length > 0);

  const origins: string[] = [];
  const seen = new Set<string>();
  for (const part of parts) {
    const origin = normalizePackagedHttpsOrigin(part);
    if (origin === null) {
      throw new Error('PACKAGED_ALLOWLIST_INVALID');
    }
    if (!seen.has(origin)) {
      seen.add(origin);
      origins.push(origin);
    }
  }
  return origins;
}

function approvedPackagedOrigins(rawEntries: readonly string[]): Set<string> {
  const approved = new Set<string>();
  for (const entry of rawEntries) {
    const origin = normalizePackagedHttpsOrigin(entry);
    if (origin !== null) {
      approved.add(origin);
    }
  }
  return approved;
}

/**
 * Resolve the API base origin this process may use.
 *
 * Unpackaged: `CLINIC_API_BASE_URL` or `http://localhost:8080`.
 * Packaged: HTTPS exact origin that is already in the baked allowlist.
 */
export function resolveApiBaseUrl(input: ResolveApiBaseUrlInput): string {
  if (!input.isPackaged) {
    const raw = input.configuredUrl?.trim() ? input.configuredUrl.trim() : DEVELOPMENT_API_BASE_URL;
    return parseApiBaseCandidate(raw).origin;
  }

  const approved = approvedPackagedOrigins(input.packagedAllowedOrigins);
  if (approved.size === 0) {
    throw new ApiOriginError('PACKAGED_ALLOWLIST_MISSING');
  }

  if (!input.configuredUrl?.trim()) {
    throw new ApiOriginError('ORIGIN_REFUSED');
  }

  const url = parseApiBaseCandidate(input.configuredUrl.trim());
  if (url.protocol !== 'https:') {
    throw new ApiOriginError('INSECURE_TRANSPORT');
  }

  if (!approved.has(url.origin)) {
    throw new ApiOriginError('ORIGIN_REFUSED');
  }

  return url.origin;
}
