import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createDecisionIdempotency } from '@/features/verification/idempotency';
import { isAllowedDecisionPair } from '@/features/verification/reasons';
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

const CASE_ID = queueItem().case_id;

function reviewerCase(status: 'pending_review' | 'approved' = 'pending_review') {
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
    [`GET /api/v1/admin/verification-cases/${CASE_ID}`]: () =>
      jsonResponse(
        envelope(
          caseDetail({
            assignment: 'mine',
            assigned_to_me: true,
            case_status: status,
            decision: status === 'approved' ? 'approved' : null,
            reason_code: status === 'approved' ? 'approved' : null,
            documents: [reviewDocument()],
          }),
        ),
      ),
  });
}

describe('decision form', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('allows only ENGINEERING_DEFAULT decision/reason pairs', () => {
    expect(isAllowedDecisionPair('approved', 'approved')).toBe(true);
    expect(isAllowedDecisionPair('rejected', 'identity_mismatch')).toBe(true);
    expect(isAllowedDecisionPair('rejected', 'evidence_incomplete')).toBe(true);
    expect(isAllowedDecisionPair('changes_requested', 'evidence_incomplete')).toBe(true);
    expect(isAllowedDecisionPair('changes_requested', 'documents_illegible')).toBe(true);
    expect(isAllowedDecisionPair('approved', 'identity_mismatch')).toBe(false);
    expect(isAllowedDecisionPair('rejected', 'documents_illegible')).toBe(false);
    expect(isAllowedDecisionPair('changes_requested', 'approved')).toBe(false);
  });

  it('reuses one idempotency key for the same payload and mints a new key when the payload changes', () => {
    const store = createDecisionIdempotency();
    const first = store.keyFor({
      decision: 'approved',
      reason_code: 'approved',
      expected_case_version: 2,
    });
    const retry = store.keyFor({
      decision: 'approved',
      reason_code: 'approved',
      expected_case_version: 2,
    });
    const changed = store.keyFor({
      decision: 'rejected',
      reason_code: 'identity_mismatch',
      expected_case_version: 2,
    });
    expect(first).toBe(retry);
    expect(changed).not.toBe(first);
  });

  it('confirms approval and posts approved + approved with an Idempotency-Key', async () => {
    const user = userEvent.setup();
    const keys: string[] = [];
    const fetchMock = reviewerCase();
    fetchMock.mockImplementation(async (input, init) => {
      const request = input instanceof Request ? input : new Request(String(input), init);
      if (request.url.endsWith('/api/v1/me')) {
        return jsonResponse(meBody());
      }
      if (request.url.includes('/capabilities')) {
        return jsonResponse(capabilitiesBody([REVIEW_CAPABILITY]));
      }
      if (request.url.includes('/health')) {
        return jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        );
      }
      if (request.method === 'GET' && request.url.includes(`/verification-cases/${CASE_ID}`)) {
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
      if (request.method === 'POST' && request.url.includes('/decisions')) {
        keys.push(request.headers.get('Idempotency-Key') ?? '');
        const body = (await request.json()) as Record<string, unknown>;
        expect(body.decision).toBe('approved');
        expect(body.reason_code).toBe('approved');
        expect(body.expected_case_version).toBe(2);
        expect(body).not.toHaveProperty('reviewer_id');
        return jsonResponse(
          envelope({
            case_id: CASE_ID,
            case_status: 'approved',
            case_version: 3,
            decision: 'approved',
            reason_code: 'approved',
          }),
        );
      }
      return jsonResponse({ errors: [{ code: 'NOT_FOUND', message: 'missing' }] }, 404);
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByRole('button', { name: 'Submit decision' }));
    expect(await screen.findByRole('dialog', { name: 'Confirm verification decision' })).toBeInTheDocument();
    expect(
      screen.getByText(/Approval verifies this profile status only/),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Record decision' }));
    await waitFor(() => {
      expect(keys).toHaveLength(1);
      expect(keys[0]?.length).toBeGreaterThan(16);
    });
  });

  it('does not auto-resubmit after VERSION_CONFLICT', async () => {
    const user = userEvent.setup();
    let posts = 0;
    const fetchMock = reviewerCase();
    fetchMock.mockImplementation(async (input, init) => {
      const request = input instanceof Request ? input : new Request(String(input), init);
      if (request.url.endsWith('/api/v1/me')) {
        return jsonResponse(meBody());
      }
      if (request.url.includes('/capabilities')) {
        return jsonResponse(capabilitiesBody([REVIEW_CAPABILITY]));
      }
      if (request.url.includes('/health')) {
        return jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        );
      }
      if (request.method === 'GET' && request.url.includes(`/verification-cases/${CASE_ID}`)) {
        return jsonResponse(
          envelope(caseDetail({ assignment: 'mine', assigned_to_me: true, documents: [reviewDocument()] })),
        );
      }
      if (request.method === 'POST' && request.url.includes('/decisions')) {
        posts += 1;
        return jsonResponse(errorEnvelope('VERSION_CONFLICT', 'changed', 409).body, 409);
      }
      return jsonResponse({ errors: [{ code: 'NOT_FOUND', message: 'missing' }] }, 404);
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByRole('button', { name: 'Submit decision' }));
    await user.click(await screen.findByRole('button', { name: 'Record decision' }));
    await waitFor(() => {
      expect(screen.getByText(/This case changed/)).toBeInTheDocument();
    });
    expect(posts).toBe(1);
  });

  it('hides decision controls after the case is decided', async () => {
    reviewerCase('approved');
    renderApp(`/verification/${CASE_ID}`);
    expect(await screen.findByText('This case is no longer pending review.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit decision' })).not.toBeInTheDocument();
  });

  it('bounds notes to 2000 characters', async () => {
    reviewerCase();
    renderApp(`/verification/${CASE_ID}`);
    const notes = await screen.findByLabelText(/Reviewer notes/);
    expect(notes).toHaveAttribute('maxLength', '2000');
  });

  it.each([
    ['Rejected', 'Identity mismatch', 'rejected', 'identity_mismatch'],
    ['Rejected', 'Evidence incomplete', 'rejected', 'evidence_incomplete'],
    ['Changes requested', 'Evidence incomplete', 'changes_requested', 'evidence_incomplete'],
    ['Changes requested', 'Documents illegible', 'changes_requested', 'documents_illegible'],
  ] as const)('posts %s + %s', async (decisionLabel, reasonLabel, decision, reason) => {
    const user = userEvent.setup();
    const bodies: Record<string, unknown>[] = [];
    const fetchMock = reviewerCase();
    fetchMock.mockImplementation(async (input, init) => {
      const request = input instanceof Request ? input : new Request(String(input), init);
      if (request.url.endsWith('/api/v1/me')) {
        return jsonResponse(meBody());
      }
      if (request.url.includes('/capabilities')) {
        return jsonResponse(capabilitiesBody([REVIEW_CAPABILITY]));
      }
      if (request.url.includes('/health')) {
        return jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        );
      }
      if (request.method === 'GET' && request.url.includes(`/verification-cases/${CASE_ID}`)) {
        return jsonResponse(
          envelope(caseDetail({ assignment: 'mine', assigned_to_me: true, documents: [reviewDocument()] })),
        );
      }
      if (request.method === 'POST' && request.url.includes('/decisions')) {
        bodies.push((await request.json()) as Record<string, unknown>);
        return jsonResponse(
          envelope({
            case_id: CASE_ID,
            case_status: decision,
            case_version: 3,
            decision,
            reason_code: reason,
          }),
        );
      }
      return jsonResponse({ errors: [{ code: 'NOT_FOUND', message: 'missing' }] }, 404);
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByLabelText(/^Decision$/));
    await user.click(await screen.findByRole('option', { name: decisionLabel }));
    await user.click(screen.getByLabelText(/^Reason$/));
    await user.click(await screen.findByRole('option', { name: reasonLabel }));
    await user.click(screen.getByRole('button', { name: 'Submit decision' }));
    expect(await screen.findByRole('dialog', { name: 'Confirm verification decision' })).toBeInTheDocument();
    expect(screen.queryByText('reviewer-private-notes-must-not-render')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Record decision' }));
    await waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    expect(bodies[0]).toMatchObject({
      decision,
      reason_code: reason,
      expected_case_version: 2,
    });
  });

  it('does not offer invalid ENGINEERING_DEFAULT pairs', async () => {
    const user = userEvent.setup();
    reviewerCase();
    renderApp(`/verification/${CASE_ID}`);
    await screen.findByRole('button', { name: 'Submit decision' });
    await user.click(screen.getByLabelText(/^Decision$/));
    await user.click(await screen.findByRole('option', { name: 'Approved' }));
    await user.click(screen.getByLabelText(/^Reason$/));
    expect(screen.queryByRole('option', { name: 'Identity mismatch' })).not.toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Documents illegible' })).not.toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Approved' })).toBeInTheDocument();
  });

  it('reuses one Idempotency-Key when the same payload is retried after a transport failure', async () => {
    const user = userEvent.setup();
    const keys: string[] = [];
    let attempts = 0;
    const fetchMock = reviewerCase();
    fetchMock.mockImplementation(async (input, init) => {
      const request = input instanceof Request ? input : new Request(String(input), init);
      if (request.url.endsWith('/api/v1/me')) {
        return jsonResponse(meBody());
      }
      if (request.url.includes('/capabilities')) {
        return jsonResponse(capabilitiesBody([REVIEW_CAPABILITY]));
      }
      if (request.url.includes('/health')) {
        return jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        );
      }
      if (request.method === 'GET' && request.url.includes(`/verification-cases/${CASE_ID}`)) {
        return jsonResponse(
          envelope(caseDetail({ assignment: 'mine', assigned_to_me: true, documents: [reviewDocument()] })),
        );
      }
      if (request.method === 'POST' && request.url.includes('/decisions')) {
        keys.push(request.headers.get('Idempotency-Key') ?? '');
        attempts += 1;
        if (attempts === 1) {
          return jsonResponse(errorEnvelope('INTERNAL_ERROR', 'lost', 500).body, 500);
        }
        return jsonResponse(
          envelope({
            case_id: CASE_ID,
            case_status: 'approved',
            case_version: 3,
            decision: 'approved',
            reason_code: 'approved',
          }),
        );
      }
      return jsonResponse({ errors: [{ code: 'NOT_FOUND', message: 'missing' }] }, 404);
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByRole('button', { name: 'Submit decision' }));
    await user.click(await screen.findByRole('button', { name: 'Record decision' }));
    await waitFor(() => {
      expect(keys).toHaveLength(2);
    });
    expect(keys[0]).toBe(keys[1]);
    expect(keys[0]?.length).toBeGreaterThan(16);
  });

  it('stops and refreshes on IDEMPOTENCY_KEY_REUSED without posting again', async () => {
    const user = userEvent.setup();
    let posts = 0;
    const fetchMock = reviewerCase();
    fetchMock.mockImplementation(async (input, init) => {
      const request = input instanceof Request ? input : new Request(String(input), init);
      if (request.url.endsWith('/api/v1/me')) {
        return jsonResponse(meBody());
      }
      if (request.url.includes('/capabilities')) {
        return jsonResponse(capabilitiesBody([REVIEW_CAPABILITY]));
      }
      if (request.url.includes('/health')) {
        return jsonResponse(
          envelope({
            status: 'operational',
            message: 'ok',
            components: { core: 'operational', realtime: 'operational', ai: 'operational' },
            version: '0.1.0-test',
            server_time: '2026-09-20T00:00:00Z',
          }),
        );
      }
      if (request.method === 'GET' && request.url.includes(`/verification-cases/${CASE_ID}`)) {
        return jsonResponse(
          envelope(caseDetail({ assignment: 'mine', assigned_to_me: true, documents: [reviewDocument()] })),
        );
      }
      if (request.method === 'POST' && request.url.includes('/decisions')) {
        posts += 1;
        return jsonResponse(errorEnvelope('IDEMPOTENCY_KEY_REUSED', 'reused', 409).body, 409);
      }
      return jsonResponse({ errors: [{ code: 'NOT_FOUND', message: 'missing' }] }, 404);
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByRole('button', { name: 'Submit decision' }));
    await user.click(await screen.findByRole('button', { name: 'Record decision' }));
    await waitFor(() => {
      expect(screen.getByText(/This case changed/)).toBeInTheDocument();
    });
    expect(posts).toBe(1);
  });
});
