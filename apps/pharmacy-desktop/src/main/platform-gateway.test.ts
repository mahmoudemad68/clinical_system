import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const APPROVED = 'https://pharmacy.example.com';

const { fetchMock, persistMock, loadMock, clearMock, appState, allowlistState } = vi.hoisted(() => ({
  fetchMock: vi.fn(),
  persistMock: vi.fn(),
  loadMock: vi.fn((): { access: string; refresh: string } | null => null),
  clearMock: vi.fn(),
  appState: { isPackaged: false },
  allowlistState: { origins: ['https://pharmacy.example.com'] as string[] },
}));

vi.mock('electron', () => ({
  app: {
    get isPackaged() {
      return appState.isPackaged;
    },
    getPath: () => '/tmp/clinic-pharmacy-origin-test',
  },
  net: {
    fetch: fetchMock,
  },
}));

vi.mock('./device-credentials', () => ({
  persistDeviceTokens: persistMock,
  loadDeviceTokens: loadMock,
  clearDeviceTokens: clearMock,
  secureStorageStatus: () => ({ available: true, backend: 'gnome_libsecret' }),
  SecureStorageUnavailableError: class SecureStorageUnavailableError extends Error {
    readonly code = 'CAPABILITY_NOT_AVAILABLE' as const;
  },
}));

vi.mock('./packaged-api-allowlist', () => ({
  PACKAGED_API_ALLOWED_ORIGINS: allowlistState.origins,
}));

import { platformGateway, resetPlatformGatewaySession } from './platform-gateway';

function envelope(status: number, data: unknown, extra: Record<string, unknown> = {}) {
  return {
    status,
    json: async () => extra['raw'] ?? {
      data,
      meta: {},
      errors: extra['errors'] ?? [],
      request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
    },
  };
}

function healthOk() {
  return envelope(200, {
    status: 'operational',
    message: 'ok',
    components: { core: 'operational', realtime: 'operational', ai: 'operational' },
    version: '0.1.0',
    server_time: '2026-08-25T10:00:00Z',
  });
}

function headerBag(callIndex: number): Record<string, string> {
  const init = fetchMock.mock.calls[callIndex]?.[1] as { headers?: Record<string, string> } | undefined;
  return init?.headers ?? {};
}

describe('Clinic Pharmacy — platform gateway origin and refresh', () => {
  const previousApi = process.env['CLINIC_API_BASE_URL'];

  beforeEach(() => {
    fetchMock.mockReset();
    persistMock.mockReset();
    loadMock.mockReset();
    loadMock.mockReturnValue(null);
    clearMock.mockReset();
    appState.isPackaged = false;
    allowlistState.origins.splice(0, allowlistState.origins.length, APPROVED);
    delete process.env['CLINIC_API_BASE_URL'];
    resetPlatformGatewaySession();
  });

  afterEach(() => {
    if (previousApi === undefined) {
      delete process.env['CLINIC_API_BASE_URL'];
    } else {
      process.env['CLINIC_API_BASE_URL'] = previousApi;
    }
  });

  async function expectDeniedBeforeFetch(url: string, packaged = true): Promise<void> {
    appState.isPackaged = packaged;
    process.env['CLINIC_API_BASE_URL'] = url;
    await expect(platformGateway.health('en')).rejects.toThrow();
    expect(fetchMock).not.toHaveBeenCalled();
  }

  it('A. approved exact HTTPS origin is fetched', async () => {
    appState.isPackaged = true;
    process.env['CLINIC_API_BASE_URL'] = APPROVED;
    fetchMock.mockResolvedValueOnce(healthOk());

    const health = await platformGateway.health('en');
    expect(health.status).toBe('operational');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe(`${APPROVED}/api/v1/health`);
  });

  it('B. arbitrary HTTPS origin is refused before net.fetch', async () => {
    await expectDeniedBeforeFetch('https://evil.example');
  });

  it('C. HTTP approved hostname is refused before net.fetch when packaged', async () => {
    await expectDeniedBeforeFetch('http://pharmacy.example.com');
  });

  it('D. localhost HTTP is refused before net.fetch when packaged', async () => {
    await expectDeniedBeforeFetch('http://localhost:8080');
  });

  it('E. localhost HTTPS is refused before net.fetch unless baked', async () => {
    await expectDeniedBeforeFetch('https://localhost');
  });

  it('F. lookalike hostname is refused before net.fetch', async () => {
    await expectDeniedBeforeFetch('https://pharmacy.example.com.evil.test');
  });

  it('G. credentialed URL is refused before net.fetch', async () => {
    await expectDeniedBeforeFetch('https://user:pass@api.example.com');
  });

  it('H. wrong explicit port is refused before net.fetch', async () => {
    await expectDeniedBeforeFetch('https://pharmacy.example.com:444');
  });

  it('I. missing packaged allowlist is refused before net.fetch', async () => {
    allowlistState.origins.splice(0, allowlistState.origins.length);
    await expectDeniedBeforeFetch(APPROVED);
  });

  it('J. development localhost remains usable', async () => {
    appState.isPackaged = false;
    fetchMock.mockResolvedValueOnce(healthOk());
    await platformGateway.health('en');
    expect(String(fetchMock.mock.calls[0]?.[0])).toBe('http://localhost:8080/api/v1/health');
  });

  it('A. complete refresh persists atomically, clears the key, and succeeds', async () => {
    appState.isPackaged = false;
    loadMock.mockReturnValue({ access: 'old-access', refresh: 'old-refresh' });
    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { access_token: 'new-access', refresh_token: 'new-refresh' }))
      .mockResolvedValueOnce(
        envelope(200, {
          user_id: 'u1',
          account_type: 'pharmacy',
          status: 'active',
          language: 'en',
          assurance_level: 'aal1',
          profile_links: [],
        }),
      )
      .mockResolvedValueOnce(envelope(200, { capabilities: [] }));

    const me = await platformGateway.me('en');
    expect(me.userId).toBe('u1');
    expect(persistMock).toHaveBeenCalledWith({ access: 'new-access', refresh: 'new-refresh' });
    const refreshKey = headerBag(1)['Idempotency-Key'];
    expect(refreshKey).toMatch(/^[0-9a-f-]{36}$/i);
  });

  it('B/C. token-less 2xx refresh fails, keeps old credentials, and retains the key', async () => {
    appState.isPackaged = false;
    loadMock.mockReturnValue({ access: 'old-access', refresh: 'old-refresh' });
    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { refresh_token: 'only-refresh' }));

    await expect(platformGateway.me('en')).rejects.toThrow();
    expect(persistMock).not.toHaveBeenCalled();
    const firstKey = headerBag(1)['Idempotency-Key'];
    expect(firstKey).toBeTruthy();

    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { access_token: 'only-access' }));

    await expect(platformGateway.me('en')).rejects.toThrow();
    expect(persistMock).not.toHaveBeenCalled();
    expect(headerBag(3)['Idempotency-Key']).toBe(firstKey);
  });

  it('D. malformed 2xx refresh body fails and retains the Idempotency-Key', async () => {
    appState.isPackaged = false;
    loadMock.mockReturnValue({ access: 'old-access', refresh: 'old-refresh' });
    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, null, { raw: 'not-an-envelope' }));

    await expect(platformGateway.me('en')).rejects.toThrow();
    expect(persistMock).not.toHaveBeenCalled();
    const firstKey = headerBag(1)['Idempotency-Key'];

    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { access_token: 'new-access', refresh_token: 'new-refresh' }))
      .mockResolvedValueOnce(
        envelope(200, {
          user_id: 'u1',
          account_type: 'pharmacy',
          status: 'active',
          language: 'en',
          assurance_level: 'aal1',
          profile_links: [],
        }),
      )
      .mockResolvedValueOnce(envelope(200, { capabilities: [] }));

    await platformGateway.me('en');
    expect(headerBag(3)['Idempotency-Key']).toBe(firstKey);
    expect(persistMock).toHaveBeenCalledWith({ access: 'new-access', refresh: 'new-refresh' });
  });

  it('E. persistence failure after valid 2xx keeps the key and old credentials', async () => {
    appState.isPackaged = false;
    loadMock.mockReturnValue({ access: 'old-access', refresh: 'old-refresh' });
    persistMock.mockImplementation(() => {
      throw new Error('atomic rename failed');
    });
    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { access_token: 'new-access', refresh_token: 'new-refresh' }));

    await expect(platformGateway.me('en')).rejects.toThrow();
    expect(persistMock).toHaveBeenCalledTimes(1);
    const firstKey = headerBag(1)['Idempotency-Key'];

    persistMock.mockReset();
    persistMock.mockImplementation(() => undefined);
    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { access_token: 'new-access', refresh_token: 'new-refresh' }))
      .mockResolvedValueOnce(
        envelope(200, {
          user_id: 'u1',
          account_type: 'pharmacy',
          status: 'active',
          language: 'en',
          assurance_level: 'aal1',
          profile_links: [],
        }),
      )
      .mockResolvedValueOnce(envelope(200, { capabilities: [] }));

    await platformGateway.me('en');
    expect(headerBag(3)['Idempotency-Key']).toBe(firstKey);
  });

  it('G. a later successful refresh uses a new key after the previous one was cleared', async () => {
    appState.isPackaged = false;
    loadMock.mockReturnValue({ access: 'old-access', refresh: 'old-refresh' });
    fetchMock
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { access_token: 'new-access', refresh_token: 'new-refresh' }))
      .mockResolvedValueOnce(
        envelope(200, {
          user_id: 'u1',
          account_type: 'pharmacy',
          status: 'active',
          language: 'en',
          assurance_level: 'aal1',
          profile_links: [],
        }),
      )
      .mockResolvedValueOnce(envelope(200, { capabilities: [] }))
      .mockResolvedValueOnce(envelope(401, null, { errors: [{ code: 'UNAUTHENTICATED' }] }))
      .mockResolvedValueOnce(envelope(200, { access_token: 'newer-access', refresh_token: 'newer-refresh' }))
      .mockResolvedValueOnce(
        envelope(200, {
          user_id: 'u1',
          account_type: 'pharmacy',
          status: 'active',
          language: 'en',
          assurance_level: 'aal1',
          profile_links: [],
        }),
      )
      .mockResolvedValueOnce(envelope(200, { capabilities: [] }));

    await platformGateway.me('en');
    const firstKey = headerBag(1)['Idempotency-Key'];
    await platformGateway.me('en');
    const secondKey = headerBag(5)['Idempotency-Key'];
    expect(firstKey).toBeTruthy();
    expect(secondKey).toBeTruthy();
    expect(secondKey).not.toBe(firstKey);
  });

  it('H. logout does not report success when server revoke fails', async () => {
    appState.isPackaged = false;
    loadMock.mockReturnValue({ access: 'old-access', refresh: 'old-refresh' });
    fetchMock.mockResolvedValueOnce(envelope(500, null, { errors: [{ code: 'UPSTREAM_FAILURE' }] }));

    await expect(platformGateway.logout('en')).rejects.toThrow();
    expect(clearMock).not.toHaveBeenCalled();
  });
});
