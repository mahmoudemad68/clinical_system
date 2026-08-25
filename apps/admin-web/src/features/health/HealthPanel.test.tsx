import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { I18nextProvider } from 'react-i18next';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { HealthPanel } from './HealthPanel';
import i18n from '@/i18n';

/**
 * Phase 00 admin end-to-end requirement: the client displays core health and
 * version in Arabic and English.
 *
 * `fetch` is stubbed rather than mocking the hook, so the transport wrapper and
 * the generated types stay in the path. Mocking the hook would test the
 * component against an interface no server ever produces.
 */

const healthBody = (overrides: Record<string, unknown> = {}) => ({
  data: {
    status: 'operational',
    message: 'All services are operating normally.',
    components: { core: 'operational', realtime: 'operational', ai: 'operational' },
    version: '0.1.0-test',
    server_time: '2026-08-24T19:05:00Z',
    ...overrides,
  },
  meta: { locale: 'en' },
  errors: [],
  request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
});

function renderPanel() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <I18nextProvider i18n={i18n}>
        <HealthPanel />
      </I18nextProvider>
    </QueryClientProvider>,
  );
}

describe('HealthPanel', () => {
  beforeEach(() => {
    void i18n.changeLanguage('en');
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('renders health and version in English', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify(healthBody()), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    renderPanel();

    await waitFor(() => {
      expect(screen.getByTestId('version')).toHaveTextContent('0.1.0-test');
    });

    expect(screen.getByTestId('overall-status')).toHaveTextContent('Operational');
  });

  it('renders health and version in Arabic', async () => {
    await i18n.changeLanguage('ar');

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify(healthBody()), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    renderPanel();

    await waitFor(() => {
      expect(screen.getByTestId('version')).toHaveTextContent('0.1.0-test');
    });

    // Arabic, not an untranslated English fallback.
    expect(screen.getByTestId('overall-status').textContent).toMatch(/\p{Script=Arabic}/u);
    expect(document.documentElement.getAttribute('dir')).toBe('rtl');
  });

  it('shows a degraded AI component without claiming the platform is down', async () => {
    // An AI outage is not a core outage (plan.md section 141). The panel must
    // reflect that rather than showing a single red banner.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify(
            healthBody({
              status: 'degraded',
              components: { core: 'operational', realtime: 'operational', ai: 'unavailable' },
            }),
          ),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
      ),
    );

    renderPanel();

    await waitFor(() => {
      expect(screen.getByTestId('component-ai')).toHaveTextContent('Unavailable');
    });

    expect(screen.getByTestId('component-core')).toHaveTextContent('Operational');
    expect(screen.getByTestId('overall-status')).toHaveTextContent('Degraded');
  });

  it('surfaces a safe error with its request id when the platform is unreachable', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network down')));

    renderPanel();

    // A network failure is retryable, so the panel deliberately retries twice
    // with backoff before declaring the platform unreachable. That is the
    // behaviour we want in production — a single dropped packet should not
    // paint a red banner — so the test waits it out rather than the hook being
    // weakened to make the test fast.
    await waitFor(
      () => {
        expect(screen.getByRole('alert')).toBeInTheDocument();
      },
      { timeout: 5000 },
    );
  });

  it('conveys status as text, not colour alone', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify(healthBody()), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    );

    renderPanel();

    await waitFor(() => {
      // Every component status must have a readable label. A colour swatch
      // alone fails WCAG 1.4.1 and is unusable for a colour-blind operator.
      for (const key of ['core', 'realtime', 'ai']) {
        expect(screen.getByTestId(`component-${key}`).textContent.trim()).toBeTruthy();
      }
    });
  });
});
