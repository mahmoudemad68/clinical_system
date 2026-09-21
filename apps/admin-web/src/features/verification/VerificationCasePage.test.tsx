import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { requestUrl, stubApi } from '@/test/apiStub';
import {
  CANARIES,
  capabilitiesBody,
  caseDetail,
  envelope,
  errorEnvelope,
  jsonResponse,
  meBody,
  queueItem,
  pharmacyCaseDetail,
  reviewDocument,
  REVIEW_CAPABILITY,
} from '@/test/fixtures';
import i18n from '@/i18n';

const CASE_ID = queueItem().case_id;

function baseRoutes(extra: Record<string, (request: Request) => Response | Promise<Response>>) {
  return stubApi({
    'GET /api/v1/me': () => jsonResponse(meBody()),
    'GET /api/v1/me/capabilities': () => jsonResponse(capabilitiesBody([REVIEW_CAPABILITY])),
    'GET /api/v1/auth/csrf': () => jsonResponse(envelope({ csrf: true })),
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
    ...extra,
  });
}

describe('verification case detail and claim', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('opens a safe case summary and hides documents before assignment', async () => {
    baseRoutes({
      [`GET /api/v1/admin/verification-cases/${CASE_ID}`]: () =>
        jsonResponse(
          envelope(
            caseDetail({
              documents: [],
              assignment: 'unassigned',
              assigned_to_me: false,
            }),
          ),
        ),
    });

    renderApp(`/verification/${CASE_ID}`);
    expect(await screen.findByText('Dr Synthetic Review')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Claim case' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'View / download document' })).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain(CANARIES.nationalId);
    expect(document.body.textContent).not.toContain(CANARIES.notes);
  });

  it('claims with expected_case_version only and then shows documents', async () => {
    const user = userEvent.setup();
    let assigned = false;
    const fetchMock = baseRoutes({
      [`GET /api/v1/admin/verification-cases/${CASE_ID}`]: () =>
        jsonResponse(
          envelope(
            caseDetail({
              assignment: assigned ? 'mine' : 'unassigned',
              assigned_to_me: assigned,
              case_version: assigned ? 3 : 2,
              documents: assigned ? [reviewDocument()] : [],
            }),
          ),
        ),
      [`POST /api/v1/admin/verification-cases/${CASE_ID}/claim`]: async (request) => {
        const body = (await request.json()) as Record<string, unknown>;
        expect(body).toEqual({ expected_case_version: 2 });
        expect(body).not.toHaveProperty('reviewer_id');
        assigned = true;
        return jsonResponse(
          envelope(
            caseDetail({
              assignment: 'mine',
              assigned_to_me: true,
              case_version: 3,
              documents: [reviewDocument()],
            }),
          ),
        );
      },
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByRole('button', { name: 'Claim case' }));
    expect(await screen.findByText('Case assigned to you.')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'View / download document' })).toBeInTheDocument();
    const claimCall = fetchMock.mock.calls.find((call) => requestUrl(call).includes('/claim'));
    expect(claimCall).toBeTruthy();
    const claimInput = Array.isArray(claimCall) ? claimCall[0] : undefined;
    expect(claimInput instanceof Request ? claimInput.credentials : '').toBe('include');
  });

  it('shows a foreign-assigned case as read-only', async () => {
    baseRoutes({
      [`GET /api/v1/admin/verification-cases/${CASE_ID}`]: () =>
        jsonResponse(
          envelope(
            caseDetail({
              assignment: 'other',
              assigned_to_me: false,
              documents: [reviewDocument()],
            }),
          ),
        ),
    });

    renderApp(`/verification/${CASE_ID}`);
    expect(
      await screen.findByText('This case is assigned to another reviewer. Document access and decisions are not available.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Claim case' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'View / download document' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit decision' })).not.toBeInTheDocument();
  });

  it('refreshes on VERSION_CONFLICT without retrying the claim', async () => {
    const user = userEvent.setup();
    let claims = 0;
    let taken = false;
    baseRoutes({
      [`GET /api/v1/admin/verification-cases/${CASE_ID}`]: () =>
        jsonResponse(
          envelope(
            caseDetail({
              assignment: taken ? 'other' : 'unassigned',
              assigned_to_me: false,
              case_version: taken ? 4 : 2,
              documents: [],
            }),
          ),
        ),
      [`POST /api/v1/admin/verification-cases/${CASE_ID}/claim`]: () => {
        claims += 1;
        taken = true;
        return jsonResponse(errorEnvelope('VERSION_CONFLICT', 'changed', 409).body, 409);
      },
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByRole('button', { name: 'Claim case' }));
    await waitFor(() => {
      expect(screen.getByText(/This case changed/)).toBeInTheDocument();
    });
    expect(claims).toBe(1);
    expect(screen.queryByRole('button', { name: 'Claim case' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit decision' })).not.toBeInTheDocument();
  });

  it('shows a safe message for a missing case', async () => {
    baseRoutes({
      [`GET /api/v1/admin/verification-cases/${CASE_ID}`]: () =>
        jsonResponse(errorEnvelope('NOT_FOUND', 'missing', 404).body, 404),
    });

    renderApp(`/verification/${CASE_ID}`);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(document.body.textContent).not.toContain(CANARIES.canonicalLocator);
  });

  it('renders a pharmacy case without legal identity fields', async () => {
    const user = userEvent.setup();
    const pharmacyId = pharmacyCaseDetail().case_id;
    let assigned = false;
    baseRoutes({
      [`GET /api/v1/admin/verification-cases/${pharmacyId}`]: () =>
        jsonResponse(
          envelope(
            pharmacyCaseDetail({
              assignment: assigned ? 'mine' : 'unassigned',
              assigned_to_me: assigned,
              documents: assigned
                ? [reviewDocument({ requirement_code: 'organization_registration_evidence' })]
                : [],
            }),
          ),
        ),
      [`POST /api/v1/admin/verification-cases/${pharmacyId}/claim`]: () => {
        assigned = true;
        return jsonResponse(
          envelope(
            pharmacyCaseDetail({
              assignment: 'mine',
              assigned_to_me: true,
              documents: [reviewDocument({ requirement_code: 'organization_registration_evidence' })],
            }),
          ),
        );
      },
    });

    renderApp(`/verification/${pharmacyId}`);
    expect(await screen.findByText('Synthetic Pharmacy Review')).toBeInTheDocument();
    expect(
      screen.getByText(/Displayed organization, branch, and membership statuses come from the server/),
    ).toBeInTheDocument();
    expect(document.body.textContent).not.toContain(CANARIES.legalName);
    expect(document.body.textContent).not.toContain(CANARIES.registration);
    expect(document.body.textContent).not.toContain(CANARIES.address);
    await user.click(screen.getByRole('button', { name: 'Claim case' }));
    expect(await screen.findByRole('button', { name: 'View / download document' })).toBeInTheDocument();
  });
});
