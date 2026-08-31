import { app, net } from 'electron';
import os from 'node:os';
import { baseRequestHeaders, unwrapEnvelope, type paths } from '@clinic/api-client';
import type {
  AuthMe,
  AuthSecureStatus,
  AuthSessionView,
  PlatformHealth,
  PlatformVersion,
} from '@clinic/desktop-bridge-contracts';
import { APP_CONFIG } from '../shared/app-config';
import {
  clearDeviceTokens,
  loadDeviceTokens,
  persistDeviceTokens,
  SecureStorageUnavailableError,
  secureStorageStatus,
} from './device-credentials';
import { resolveApiBaseUrl } from './api-origin';
import { PACKAGED_API_ALLOWED_ORIGINS } from './packaged-api-allowlist';
import { TokenRefreshSession } from './token-refresh';

/**
 * Main-process HTTP transport.
 *
 * Uses Electron's `net` module rather than `fetch` so the request follows the
 * operating system's proxy configuration and certificate store (ADR 0010).
 *
 * Tokens stay in this process. The renderer never receives them.
 *
 * Packaged API origin trust comes from `PACKAGED_API_ALLOWED_ORIGINS` (baked
 * into this bundle). Runtime `CLINIC_API_BASE_URL` only selects among that
 * list; it cannot add a host.
 */

let memoryAccess: string | null = null;
let memoryRefresh: string | null = null;
const tokenRefresh = new TokenRefreshSession();

function restoreFromDisk(): void {
  if (memoryAccess !== null) {
    return;
  }
  const stored = loadDeviceTokens();
  if (stored) {
    memoryAccess = stored.access;
    memoryRefresh = stored.refresh;
  }
}

function platformName(): 'windows' | 'macos' | 'linux' {
  if (process.platform === 'win32') {
    return 'windows';
  }
  if (process.platform === 'darwin') {
    return 'macos';
  }
  return 'linux';
}

function deviceLabel(explicit?: string): string {
  const label = (explicit ?? os.hostname()).trim();
  return label.slice(0, 120) || 'desktop';
}

async function requestJson<T>(
  method: string,
  path: string,
  locale: string,
  body?: Record<string, unknown>,
  extraHeaders: Record<string, string> = {},
  allowRefresh = true,
): Promise<T> {
  restoreFromDisk();
  const headers: Record<string, string> = {
    ...baseRequestHeaders(locale),
    ...extraHeaders,
  };

  if (memoryAccess !== null && !path.startsWith('/api/v1/auth/login') && !path.includes('/auth/mfa/') && path !== '/api/v1/auth/token/refresh') {
    headers['Authorization'] = `Bearer ${memoryAccess}`;
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const baseUrl = resolveApiBaseUrl({
    configuredUrl: process.env['CLINIC_API_BASE_URL'],
    isPackaged: app.isPackaged,
    packagedAllowedOrigins: PACKAGED_API_ALLOWED_ORIGINS,
  });
  const base = new URL(baseUrl);
  const target = new URL(`${baseUrl}${path}`);
  if (target.origin !== base.origin) {
    throw new Error('ORIGIN_REFUSED');
  }

  // exactOptionalPropertyTypes: omit `body` entirely when the request has none.
  // Passing `body: undefined` is not assignable to RequestInit.
  const response = await net.fetch(
    target.toString(),
    body === undefined
      ? { method, headers }
      : { method, headers, body: JSON.stringify(body) },
  );

  if (response.status === 401 && allowRefresh && memoryRefresh !== null && path !== '/api/v1/auth/token/refresh' && path !== '/api/v1/auth/logout') {
    const rotated = await refreshTokens(locale);
    if (rotated) {
      return requestJson<T>(method, path, locale, body, extraHeaders, false);
    }
  }

  const json: unknown = await response.json().catch(() => null);
  const result = unwrapEnvelope<T>(json, response.status);

  if (!result.ok) {
    throw new Error(result.failure.code);
  }

  return result.data;
}

async function refreshTokens(locale: string): Promise<boolean> {
  if (memoryRefresh === null) {
    return false;
  }
  return tokenRefresh.run({
    request: (idempotencyKey) =>
      requestJson<{
        access_token?: string;
        refresh_token?: string;
      }>(
        'POST',
        '/api/v1/auth/token/refresh',
        locale,
        {
          refresh_token: memoryRefresh,
        },
        {
          'Idempotency-Key': idempotencyKey,
        },
        false,
      ),
    persist: persistDeviceTokens,
    remember: (tokens) => {
      memoryAccess = tokens.access;
      memoryRefresh = tokens.refresh;
    },
  });
}

function persistIssued(data: {
  access_token?: string;
  refresh_token?: string;
}): void {
  if (typeof data.access_token !== 'string' || typeof data.refresh_token !== 'string') {
    return;
  }
  persistDeviceTokens({ access: data.access_token, refresh: data.refresh_token });
  memoryAccess = data.access_token;
  memoryRefresh = data.refresh_token;
}

function toSessionView(data: {
  status?: string;
  mfa_required?: boolean;
  challenge_id?: string;
  session_kind?: 'device' | 'admin_cookie';
  user_id?: string;
  account_type?: string;
}): AuthSessionView {
  return {
    status: data.status ?? '',
    mfaRequired: data.mfa_required === true,
    ...(data.challenge_id ? { challengeId: data.challenge_id } : {}),
    ...(data.session_kind ? { sessionKind: data.session_kind } : {}),
    ...(data.user_id ? { userId: data.user_id } : {}),
    ...(data.account_type ? { accountType: data.account_type } : {}),
  };
}

export const platformGateway = {
  secureStatus(): AuthSecureStatus {
    return secureStorageStatus();
  },

  async health(locale: string): Promise<PlatformHealth> {
    const data = await requestJson<{
      status: PlatformHealth['status'];
      message: string;
      components: PlatformHealth['components'];
      version: string;
      server_time: string;
    }>('GET', '/api/v1/health', locale);

    return {
      status: data.status,
      message: data.message,
      components: data.components,
      version: data.version,
      serverTime: data.server_time,
    };
  },

  async version(locale: string): Promise<PlatformVersion> {
    const data = await requestJson<{
      service: string;
      version: string;
      api_version: 'v1';
      environment: PlatformVersion['environment'];
    }>('GET', '/api/v1/meta/version', locale);

    return {
      service: data.service,
      version: data.version,
      apiVersion: data.api_version,
      environment: data.environment,
    };
  },

  async login(
    locale: string,
    input: { phone: string; password: string; deviceLabel: string },
  ): Promise<AuthSessionView> {
    if (!secureStorageStatus().available) {
      throw new SecureStorageUnavailableError();
    }

    const data = await requestJson<{
      status?: string;
      mfa_required?: boolean;
      challenge_id?: string;
      session_kind?: 'device' | 'admin_cookie';
      user_id?: string;
      account_type?: string;
      access_token?: string;
      refresh_token?: string;
    }>('POST', '/api/v1/auth/login', locale, {
      phone: input.phone,
      password: input.password,
      client_class: APP_CONFIG.apiClientClass,
      platform: platformName(),
      device_label: deviceLabel(input.deviceLabel),
    });

    persistIssued(data);
    return toSessionView(data);
  },

  async verifyMfa(locale: string, input: { challengeId: string; code: string }): Promise<AuthSessionView> {
    if (!secureStorageStatus().available) {
      throw new SecureStorageUnavailableError();
    }

    const data = await requestJson<{
      status?: string;
      mfa_required?: boolean;
      challenge_id?: string;
      session_kind?: 'device' | 'admin_cookie';
      user_id?: string;
      account_type?: string;
      access_token?: string;
      refresh_token?: string;
    }>('POST', `/api/v1/auth/mfa/challenges/${input.challengeId}/verify`, locale, {
      code: input.code,
    });

    persistIssued(data);
    return toSessionView(data);
  },

  async logout(locale: string): Promise<{ revoked: true }> {
    await requestJson<{ revoked: boolean }>('POST', '/api/v1/auth/logout', locale, {});
    clearDeviceTokens();
    memoryAccess = null;
    memoryRefresh = null;
    tokenRefresh.clear();
    return { revoked: true };
  },

  async me(locale: string): Promise<AuthMe> {
    restoreFromDisk();
    if (memoryAccess === null) {
      throw new Error('UNAUTHENTICATED');
    }

    const data = await requestJson<{
      user_id: string;
      account_type: string;
      status: string;
      language: 'ar' | 'en';
      assurance_level: string;
      profile_links: string[];
    }>('GET', '/api/v1/me', locale);

    const caps = await requestJson<{ capabilities: string[] }>('GET', '/api/v1/me/capabilities', locale);

    return {
      userId: data.user_id,
      accountType: data.account_type,
      status: data.status,
      language: data.language,
      assuranceLevel: data.assurance_level,
      capabilities: caps.capabilities,
    };
  },

  async sessions(locale: string): Promise<{
    sessions: Array<{
      sessionId: string;
      sessionKind: 'device' | 'admin_cookie';
      assuranceLevel: string;
      lastSeenAt?: string;
      createdAt?: string;
    }>;
  }> {
    const rows = await requestJson<
      Array<{
        session_id: string;
        session_kind: 'device' | 'admin_cookie';
        assurance_level: string;
        last_seen_at?: string;
        created_at?: string;
      }>
    >('GET', '/api/v1/auth/sessions', locale);

    return {
      sessions: rows.map((row) => ({
        sessionId: row.session_id,
        sessionKind: row.session_kind,
        assuranceLevel: row.assurance_level,
        ...(row.last_seen_at ? { lastSeenAt: row.last_seen_at } : {}),
        ...(row.created_at ? { createdAt: row.created_at } : {}),
      })),
    };
  },

  async revokeSession(locale: string, sessionId: string): Promise<{ revoked: true }> {
    await requestJson<{ revoked: boolean }>('DELETE', `/api/v1/auth/sessions/${sessionId}`, locale);
    return { revoked: true };
  },
};

/** Referenced so the generated paths type stays wired to this transport. */
export type ApiPaths = paths;

export { SecureStorageUnavailableError };

/** Clears in-memory tokens and the retained refresh key. Production IPC does not call this. */
export function resetPlatformGatewaySession(): void {
  memoryAccess = null;
  memoryRefresh = null;
  tokenRefresh.clear();
}
