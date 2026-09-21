import { screen } from '@testing-library/react';
import axe from 'axe-core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { stubApi } from '@/test/apiStub';
import {
  CANARIES,
  capabilitiesBody,
  caseDetail,
  envelope,
  jsonResponse,
  meBody,
  queueItem,
  reviewDocument,
  REVIEW_CAPABILITY,
} from '@/test/fixtures';
import i18n from '@/i18n';

function reviewer() {
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
    'GET /api/v1/admin/verification-cases': () =>
      jsonResponse(
        envelope([queueItem()], {
          meta: { locale: 'en', pagination: { has_more: false, next: null, limit: 25 } },
        }),
      ),
    [`GET /api/v1/admin/verification-cases/${queueItem().case_id}`]: () =>
      jsonResponse(
        envelope(
          caseDetail({
            assignment: 'mine',
            assigned_to_me: true,
            documents: [reviewDocument()],
          }),
        ),
      ),
  });
}

describe('security canaries and accessibility', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('does not render seeded sensitive canaries in queue or detail', async () => {
    reviewer();
    renderApp('/verification');
    expect(await screen.findByText('Dr Synthetic Review')).toBeInTheDocument();
    const queueText = document.body.innerHTML;
    expect(queueText).not.toContain(CANARIES.nationalId);
    expect(queueText).not.toContain(CANARIES.syndicate);
    expect(queueText).not.toContain(CANARIES.phone);
    expect(queueText).not.toContain(CANARIES.canonicalLocator);
    expect(queueText).not.toContain(CANARIES.ingressLocator);
    expect(queueText).not.toContain(CANARIES.signedUrl);
    expect(queueText).not.toContain(CANARIES.notes);
    expect(queueText).not.toContain(CANARIES.amz);
    expect(queueText).not.toContain(CANARIES.legalName);
    expect(queueText).not.toContain(CANARIES.registration);
    expect(queueText).not.toContain(CANARIES.address);
    expect(queueText).not.toContain(CANARIES.coordinates);
  });

  it('has no serious axe violations on the queue', async () => {
    reviewer();
    const { container } = renderApp('/verification');
    await screen.findByText('Dr Synthetic Review');
    const results = await axe.run(container, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } });
    const serious = results.violations.filter(
      (violation) => violation.impact === 'serious' || violation.impact === 'critical',
    );
    expect(serious).toEqual([]);
  });
});
