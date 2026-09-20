import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { requestUrl, stubApi } from '@/test/apiStub';
import {
  capabilitiesBody,
  envelope,
  errorEnvelope,
  jsonResponse,
  meBody,
  queueItem,
  REVIEW_CAPABILITY,
} from '@/test/fixtures';
import i18n from '@/i18n';

function reviewerSession(queueHandler?: (request: Request) => Response) {
  return stubApi({
    'GET /api/v1/me': () => jsonResponse(meBody()),
    'GET /api/v1/me/capabilities': () => jsonResponse(capabilitiesBody([REVIEW_CAPABILITY])),
    'GET /api/v1/health': () =>
      jsonResponse(
        envelope({
          status: 'operational',
          message: 'ok',
          components: { core: 'operational', realtime: 'operational', ai: 'operational' },
          version: '0.1.0-test',
          server_time: '2026-09-20T00:00:00Z',
        }),
      ),
    'GET /api/v1/admin/verification-cases': (request) =>
      queueHandler ? queueHandler(request) : jsonResponse(envelope([], { meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } } })),
    'POST /api/v1/auth/logout': () => jsonResponse(envelope({ revoked: true })),
    'GET /api/v1/auth/csrf': () => jsonResponse(envelope({ csrf: true })),
  });
}

describe('session bootstrap', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('shows login when unauthenticated', async () => {
    const fetchMock = stubApi({
      'GET /api/v1/me': () => jsonResponse(errorEnvelope('UNAUTHENTICATED', 'unauthenticated', 401).body, 401),
      'GET /api/v1/health': () => jsonResponse(envelope({ status: 'operational', message: 'ok', components: {}, version: '0', server_time: '2026-09-20T00:00:00Z' })),
    });

    renderApp('/');

    expect(await screen.findByRole('heading', { name: 'Admin sign in' })).toBeInTheDocument();
    expect(fetchMock.mock.calls.some((call) => requestUrl(call).includes('/admin/verification-cases'))).toBe(false);
  });

  it('refetches /me after remount so a valid cookie session survives bootstrap', async () => {
    reviewerSession();
    const first = renderApp('/');
    expect(await screen.findByRole('heading', { name: 'Pending doctor verification' })).toBeInTheDocument();
    first.unmount();

    reviewerSession();
    renderApp('/');
    expect(await screen.findByRole('heading', { name: 'Pending doctor verification' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Admin sign in' })).not.toBeInTheDocument();
  });

  it('shows the workspace when the reviewer capability is present', async () => {
    reviewerSession();
    renderApp('/');
    expect(await screen.findByRole('heading', { name: 'Pending doctor verification' })).toBeInTheDocument();
  });

  it('does not fetch the queue when the actor lacks verification.case.review', async () => {
    const fetchMock = stubApi({
      'GET /api/v1/me': () => jsonResponse(meBody()),
      'GET /api/v1/me/capabilities': () => jsonResponse(capabilitiesBody(['identity.me.read'])),
      'GET /api/v1/health': () =>
        jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        ),
    });

    renderApp('/verification');
    expect(await screen.findByRole('heading', { name: 'Verification review is not available' })).toBeInTheDocument();
    expect(fetchMock.mock.calls.some((call) => requestUrl(call).includes('/admin/verification-cases'))).toBe(false);
  });

  it('completes password then MFA and refreshes session queries', async () => {
    const user = userEvent.setup();
    let signedIn = false;
    const fetchMock = stubApi({
      'GET /api/v1/me': () =>
        jsonResponse(
          signedIn ? meBody() : errorEnvelope('UNAUTHENTICATED', 'unauthenticated', 401).body,
          signedIn ? 200 : 401,
        ),
      'GET /api/v1/me/capabilities': () => jsonResponse(capabilitiesBody([REVIEW_CAPABILITY])),
      'GET /api/v1/auth/csrf': () => jsonResponse(envelope({})),
      'POST /api/v1/auth/login': () =>
        jsonResponse(
          envelope({
            mfa_required: true,
            challenge_id: '0199a5c8-0000-7000-8000-000000000099',
            status: 'mfa_required',
          }),
        ),
      'POST /api/v1/auth/mfa/challenges/0199a5c8-0000-7000-8000-000000000099/verify': () => {
        signedIn = true;
        return jsonResponse(envelope({ session_kind: 'admin_cookie', status: 'authenticated' }));
      },
      'GET /api/v1/health': () =>
        jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        ),
      'GET /api/v1/admin/verification-cases': () =>
        jsonResponse(envelope([], { meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } } })),
    });

    renderApp('/');
    expect(await screen.findByRole('heading', { name: 'Admin sign in' })).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Mobile number/), '01000000000');
    await user.type(screen.getByLabelText(/^Password/), 'correct-horse-battery');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByLabelText(/Authenticator code/)).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Authenticator code/), '123456');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('heading', { name: 'Pending doctor verification' })).toBeInTheDocument();
    expect(fetchMock.mock.calls.some((call) => requestUrl(call).includes('/mfa/challenges'))).toBe(true);
  });

  it('logout clears verification queries and returns to sign-in', async () => {
    const user = userEvent.setup();
    let signedIn = true;
    const fetchMock = stubApi({
      'GET /api/v1/me': () =>
        jsonResponse(
          signedIn ? meBody() : errorEnvelope('UNAUTHENTICATED', 'unauthenticated', 401).body,
          signedIn ? 200 : 401,
        ),
      'GET /api/v1/me/capabilities': () => jsonResponse(capabilitiesBody([REVIEW_CAPABILITY])),
      'GET /api/v1/health': () =>
        jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        ),
      'GET /api/v1/admin/verification-cases': () =>
        jsonResponse(
          envelope([queueItem()], {
            meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } },
          }),
        ),
      'POST /api/v1/auth/logout': () => {
        signedIn = false;
        return jsonResponse(envelope({ revoked: true }));
      },
    });

    const view = renderApp('/');
    expect(await screen.findByText('Dr Synthetic Review')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Admin sign in' })).toBeInTheDocument();
    });
    expect(view.queryClient.getQueryData(['verification', 'queue', 'unassigned', null])).toBeUndefined();
    expect(fetchMock.mock.calls.some((call) => requestUrl(call).includes('/auth/logout'))).toBe(true);
  });
});
