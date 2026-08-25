import { net } from 'electron';
import {
  baseRequestHeaders,
  unwrapEnvelope,
  type paths,
} from '@clinic/api-client';
import type { PlatformHealth, PlatformVersion } from '@clinic/desktop-bridge-contracts';

/**
 * Main-process HTTP transport.
 *
 * Uses Electron's `net` module rather than `fetch` so the request follows the
 * operating system's proxy configuration and certificate store (ADR 0010). A
 * clinic behind a corporate proxy or an intercepting TLS appliance is a normal
 * deployment, and a Node-level fetch would simply fail there.
 *
 * The renderer never owns an authenticated connection. It calls a capability;
 * this runs the request.
 */

const BASE_URL = process.env['CLINIC_API_BASE_URL'] ?? 'http://localhost:8080';

/**
 * Device token.
 *
 * Held in this process only, never sent to the renderer. Phase 01 replaces this
 * with a real token obtained through the auth flow and wrapped by Electron
 * `safeStorage`; ADR 0010 requires it to fail closed when the OS backend is
 * weak (notably Linux `basic_text`).
 */
let deviceToken: string | null = process.env['CLINIC_DEVICE_TOKEN'] ?? null;

export function setDeviceToken(token: string | null): void {
  deviceToken = token;
}

async function request<T>(path: string, locale: string): Promise<T> {
  const headers: Record<string, string> = baseRequestHeaders(locale);

  if (deviceToken !== null) {
    headers['Authorization'] = `Bearer ${deviceToken}`;
  }

  const response = await net.fetch(`${BASE_URL}${path}`, {
    method: 'GET',
    headers,
    // No credentials mode: the desktop authenticates with a bearer token from
    // this process, not with cookies. Admin is the surface that uses cookies.
  });

  const body: unknown = await response.json().catch(() => null);
  const result = unwrapEnvelope<T>(body, response.status);

  if (!result.ok) {
    // Throwing here is caught by the capability handler, which converts it into
    // a safe BridgeError. The failure detail never reaches the renderer raw.
    throw new Error(result.failure.code);
  }

  return result.data;
}

export const platformGateway = {
  async health(locale: string): Promise<PlatformHealth> {
    const data = await request<{
      status: PlatformHealth['status'];
      message: string;
      components: PlatformHealth['components'];
      version: string;
      server_time: string;
    }>('/api/v1/health', locale);

    // Map at the transport edge. Wire shape (snake_case) stops here; the
    // renderer sees only the bridge contract type.
    return {
      status: data.status,
      message: data.message,
      components: data.components,
      version: data.version,
      serverTime: data.server_time,
    };
  },

  async version(locale: string): Promise<PlatformVersion> {
    const data = await request<{
      service: string;
      version: string;
      api_version: 'v1';
      environment: PlatformVersion['environment'];
    }>('/api/v1/meta/version', locale);

    return {
      service: data.service,
      version: data.version,
      apiVersion: data.api_version,
      environment: data.environment,
    };
  },
};

/** Referenced so the generated paths type stays wired to this transport. */
export type ApiPaths = paths;
