import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { stubApi } from '@/test/apiStub';
import {
  CANARIES,
  capabilitiesBody,
  envelope,
  errorEnvelope,
  jsonResponse,
  meBody,
  queueItem,
  REVIEW_CAPABILITY,
} from '@/test/fixtures';
import i18n from '@/i18n';

function reviewerRoutes(
  queue: (request: Request) => Response,
  extra: Record<string, (request: Request) => Response> = {},
) {
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
    'GET /api/v1/admin/verification-cases': queue,
    ...extra,
  });
}

describe('verification queue', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('defaults to unassigned and hides prohibited fields', async () => {
    const fetchMock = reviewerRoutes((request) => {
      const url = new URL(request.url);
      expect(url.searchParams.get('assignment')).toBe('unassigned');
      expect(url.searchParams.get('case_type')).toBe('doctor_verification');
      expect(url.searchParams.get('status')).toBe('pending_review');
      expect(url.searchParams.has('offset')).toBe(false);
      expect(url.searchParams.has('page')).toBe(false);

      return jsonResponse(
        envelope(
          [
            queueItem({
              professional_display_name: 'Dr Safe Name',
            }),
          ],
          {
            meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } },
          },
        ),
      );
    });

    renderApp('/verification');
    expect(await screen.findByText('Dr Safe Name')).toBeInTheDocument();
    expect(screen.getByText('General Practice')).toBeInTheDocument();
    const html = document.body.textContent ?? '';
    expect(html).not.toContain(CANARIES.nationalId);
    expect(html).not.toContain(CANARIES.syndicate);
    expect(html).not.toContain(CANARIES.phone);
    expect(html).not.toContain(CANARIES.canonicalLocator);
    expect(html).not.toContain(CANARIES.notes);
    expect(fetchMock).toHaveBeenCalled();
  });

  it('localizes specialty in Arabic', async () => {
    await i18n.changeLanguage('ar');
    stubApi({
      'GET /api/v1/me': () => jsonResponse(meBody({ language: 'ar' })),
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
            meta: { locale: 'ar', pagination: { has_more: false, next: null, limit: 25 } },
          }),
        ),
    });

    renderApp('/verification');
    expect(await screen.findByText('طب الأسرة')).toBeInTheDocument();
    expect(document.documentElement.getAttribute('dir')).toBe('rtl');
  });

  it('requests mine and all assignment filters and resets pagination', async () => {
    const user = userEvent.setup();
    const seen: string[] = [];
    reviewerRoutes((request) => {
      const url = new URL(request.url);
      seen.push(`${url.searchParams.get('assignment')}:${url.searchParams.get('cursor') ?? ''}`);
      return jsonResponse(
        envelope([queueItem({ assignment: (url.searchParams.get('assignment') as 'unassigned') ?? 'unassigned' })], {
          meta: {
            locale: 'en',
            pagination: { has_more: true, next: 'signed-cursor-1', limit: 25 },
          },
        }),
      );
    });

    renderApp('/verification');
    expect(await screen.findByText('Dr Synthetic Review')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Next page' }));
    await waitFor(() => {
      expect(seen.some((entry) => entry.startsWith('unassigned:signed-cursor-1'))).toBe(true);
    });
    await user.click(screen.getByRole('button', { name: 'Assigned to me' }));
    await waitFor(() => {
      expect(seen.some((entry) => entry === 'mine:')).toBe(true);
    });
    await user.click(screen.getByRole('button', { name: 'All pending' }));
    await waitFor(() => {
      expect(seen.some((entry) => entry === 'all:')).toBe(true);
    });
  });

  it('recovers from CURSOR_INVALID by refetching the first page', async () => {
    const user = userEvent.setup();
    reviewerRoutes((request) => {
      const url = new URL(request.url);
      if (url.searchParams.get('cursor')) {
        return jsonResponse(errorEnvelope('CURSOR_INVALID', 'invalid cursor', 422).body, 422);
      }

      return jsonResponse(
        envelope([queueItem({ professional_display_name: 'Dr First Page' })], {
          meta: { locale: 'en', pagination: { has_more: true, next: 'signed-cursor-stale', limit: 25 } },
        }),
      );
    });

    renderApp('/verification');
    expect(await screen.findByText('Dr First Page')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Next page' }));
    expect(
      await screen.findByText('The previous page token was no longer valid. Showing the first page.'),
    ).toBeInTheDocument();
    expect(screen.getByText('Dr First Page')).toBeInTheDocument();
  });

  it('shows an empty state', async () => {
    reviewerRoutes(() =>
      jsonResponse(envelope([], { meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } } })),
    );
    renderApp('/verification');
    expect(await screen.findByText('No pending verification cases match this filter.')).toBeInTheDocument();
  });

  it('shows a network error without crashing', async () => {
    reviewerRoutes(() => {
      throw new TypeError('network down');
    });
    renderApp('/verification');
    expect(await screen.findByRole('alert')).toBeInTheDocument();
  });

  it('manual refresh refetches the current page', async () => {
    const user = userEvent.setup();
    let calls = 0;
    reviewerRoutes(() => {
      calls += 1;
      return jsonResponse(
        envelope([queueItem()], {
          meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } },
        }),
      );
    });

    renderApp('/verification');
    expect(await screen.findByText('Dr Synthetic Review')).toBeInTheDocument();
    const before = calls;
    await user.click(screen.getByRole('button', { name: 'Refresh queue' }));
    await waitFor(() => {
      expect(calls).toBeGreaterThan(before);
    });
  });
});
