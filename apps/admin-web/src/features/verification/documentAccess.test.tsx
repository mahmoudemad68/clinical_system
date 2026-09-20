import { fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { grantAndDownloadReviewerDocument } from '@/api/downloadReviewerDocument';
import { renderApp } from '@/test/renderApp';
import { stubApi } from '@/test/apiStub';
import {
  CANARIES,
  capabilitiesBody,
  caseDetail,
  envelope,
  errorEnvelope,
  jsonResponse,
  meBody,
  pdfAttachmentResponse,
  queueItem,
  reviewDocument,
  REVIEW_CAPABILITY,
} from '@/test/fixtures';
import i18n from '@/i18n';

const CASE_ID = queueItem().case_id;
const DOCUMENT_ID = reviewDocument().document_id;

function reviewerWithDocument(
  extra: Record<string, (request: Request) => Response | Promise<Response>> = {},
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
    [`GET /api/v1/admin/verification-cases/${CASE_ID}`]: () =>
      jsonResponse(
        envelope(
          caseDetail({
            assignment: 'mine',
            assigned_to_me: true,
            documents: [reviewDocument()],
          }),
        ),
      ),
    ...extra,
  });
}

describe('document access', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    document.cookie = 'XSRF-TOKEN=test-csrf';
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('does not grant on load or hover; one click issues one grant POST', async () => {
    const user = userEvent.setup();
    let grants = 0;
    const fetchMock = reviewerWithDocument({
      [`POST /api/v1/admin/verification-cases/${CASE_ID}/documents/${DOCUMENT_ID}/access`]: () => {
        grants += 1;
        return jsonResponse(
          envelope({
            document_id: DOCUMENT_ID,
            url: CANARIES.signedUrl,
            expires_at: '2026-09-20T00:02:00Z',
            detected_mime: 'application/pdf',
            size_bytes: 4,
          }),
        );
      },
      'GET /api/v1/verification-review-files/0199a5c8-0000-7000-8000-000000000021/0199a5c8-0000-7000-8000-000000000041':
        () => pdfAttachmentResponse(),
    });

    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:http://localhost/temp');
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

    const view = renderApp(`/verification/${CASE_ID}`);
    const button = await screen.findByRole('button', { name: 'View / download document' });
    expect(grants).toBe(0);
    fireEvent.mouseEnter(button);
    fireEvent.mouseOver(button);
    expect(grants).toBe(0);

    await user.click(button);
    await waitFor(() => {
      expect(grants).toBe(1);
    });
    expect(await screen.findByText(/Document access was recorded/)).toBeInTheDocument();
    expect(document.body.textContent).not.toContain(CANARIES.signedUrl);
    expect(document.body.innerHTML).not.toContain(CANARIES.signedUrl);
    expect(document.body.textContent).not.toContain('X-Amz-');
    expect(document.body.textContent).not.toContain('verification/c/');
    expect(document.body.textContent).not.toContain('verification/q/');
    expect(JSON.stringify(view.queryClient.getQueryCache().getAll().map((query) => query.state.data))).not.toContain(
      CANARIES.signedUrl,
    );
    expect(window.localStorage.length).toBe(0);
    expect(window.sessionStorage.length).toBe(0);
    expect(createObjectURL).toHaveBeenCalled();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/temp');
    const accessPosts = fetchMock.mock.calls.filter((call) => {
      const url = typeof call[0] === 'string' ? call[0] : call[0] instanceof Request ? call[0].url : '';
      return url.includes('/access');
    });
    expect(accessPosts).toHaveLength(1);
  });

  it('uses no-store / no-referrer, a generic filename, and revokes the Blob URL', async () => {
    const signedUrl =
      'http://localhost:8080/api/v1/verification-review-files/0199a5c8-0000-7000-8000-000000000021/0199a5c8-0000-7000-8000-000000000041?expires=1&signature=abc';
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:http://localhost/temp');
    const revokeObjectURL = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(String(input), init);
      if (request.method === 'POST') {
        return jsonResponse(
          envelope({
            document_id: DOCUMENT_ID,
            url: signedUrl,
            expires_at: '2026-09-20T00:02:00Z',
            detected_mime: 'application/pdf',
            size_bytes: 4,
          }),
        );
      }

      expect(
        request.url.startsWith(
          'http://localhost:5173/api/v1/verification-review-files/0199a5c8-0000-7000-8000-000000000021/0199a5c8-0000-7000-8000-000000000041',
        ),
      ).toBe(true);
      expect(request.cache).toBe('no-store');
      expect(request.referrerPolicy).toBe('no-referrer');
      expect(request.credentials).toBe('omit');
      expect(request.redirect).toBe('error');
      return pdfAttachmentResponse();
    });
    vi.stubGlobal('fetch', fetchMock);

    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);

    await grantAndDownloadReviewerDocument({ caseId: CASE_ID, documentId: DOCUMENT_ID });

    expect(createObjectURL).toHaveBeenCalled();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/temp');
    const anchor = click.mock.instances[0] as HTMLAnchorElement | undefined;
    expect(anchor?.download).toBe('verification-document.pdf');
    expect(document.body.innerHTML).not.toContain(signedUrl);
    expect(anchor?.href).not.toBe(signedUrl);
  });

  it('shows a safe failure when the grant is denied and does not retry', async () => {
    const user = userEvent.setup();
    let grants = 0;
    reviewerWithDocument({
      [`POST /api/v1/admin/verification-cases/${CASE_ID}/documents/${DOCUMENT_ID}/access`]: () => {
        grants += 1;
        return jsonResponse(errorEnvelope('NOT_FOUND', 'denied', 404).body, 404);
      },
    });

    renderApp(`/verification/${CASE_ID}`);
    await user.click(await screen.findByRole('button', { name: 'View / download document' }));
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(grants).toBe(1);
    expect(document.body.textContent).not.toContain(CANARIES.signedUrl);
  });
});
