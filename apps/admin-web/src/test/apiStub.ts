import { vi } from 'vitest';
import { jsonResponse } from './fixtures';

export type RouteHandler = (request: Request) => Response | Promise<Response>;

export function stubApi(routes: Record<string, RouteHandler>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const request = input instanceof Request ? input : new Request(String(input), init);
    const url = new URL(request.url, 'http://localhost:5173');
    const key = `${request.method} ${url.pathname}`;
    const handler = routes[key];

    if (!handler) {
      return jsonResponse(
        {
          errors: [{ code: 'NOT_FOUND', message: 'missing handler' }],
          request_id: 'missing-handler',
        },
        404,
      );
    }

    return handler(request);
  });

  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

export function requestUrl(call: unknown): string {
  const first = Array.isArray(call) ? call[0] : call;
  if (typeof first === 'string') {
    return first;
  }
  if (first instanceof URL) {
    return first.href;
  }
  if (first instanceof Request) {
    return first.url;
  }
  return '';
}
