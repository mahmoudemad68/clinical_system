import { screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { stubApi } from '@/test/apiStub';
import {
  capabilitiesBody,
  caseDetail,
  envelope,
  errorEnvelope,
  jsonResponse,
  meBody,
  queueItem,
  reviewDocument,
  REVIEW_CAPABILITY,
} from '@/test/fixtures';
import i18n from '@/i18n';

describe('session expiry during review', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('clears the case screen when the session expires', async () => {
    let expired = false;
    stubApi({
      'GET /api/v1/me': () =>
        jsonResponse(
          expired ? errorEnvelope('UNAUTHENTICATED', 'expired', 401).body : meBody(),
          expired ? 401 : 200,
        ),
      'GET /api/v1/me/capabilities': () =>
        expired
          ? jsonResponse(errorEnvelope('UNAUTHENTICATED', 'expired', 401).body, 401)
          : jsonResponse(capabilitiesBody([REVIEW_CAPABILITY])),
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
      [`GET /api/v1/admin/verification-cases/${queueItem().case_id}`]: () => {
        if (!expired) {
          expired = true;
          return jsonResponse(
            envelope(
              caseDetail({
                assignment: 'mine',
                assigned_to_me: true,
                documents: [reviewDocument()],
              }),
            ),
          );
        }

        return jsonResponse(errorEnvelope('UNAUTHENTICATED', 'expired', 401).body, 401);
      },
    });

    const view = renderApp(`/verification/${queueItem().case_id}`);
    expect(await screen.findByText('Dr Synthetic Review')).toBeInTheDocument();
    await view.queryClient.invalidateQueries();
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Admin sign in' })).toBeInTheDocument();
    });
    expect(screen.queryByText('Dr Synthetic Review')).not.toBeInTheDocument();
    expect(screen.queryByText('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')).not.toBeInTheDocument();
  });
});
