import { describe, expect, it } from 'vitest';
import { API_ERROR_CODES, isAuthenticationFailure, shouldRetry, toApiFailure } from './index';

describe('error handling', () => {
  it('treats CSRF_MISMATCH as a distinct non-auth machine code', () => {
    expect(API_ERROR_CODES).toContain('CSRF_MISMATCH');
    expect(
      isAuthenticationFailure({
        code: 'CSRF_MISMATCH',
        message: 'reload',
        status: 403,
      }),
    ).toBe(false);
  });

  it('does not retry a csrf mismatch', () => {
    expect(
      shouldRetry({ code: 'CSRF_MISMATCH', message: 'reload', status: 403 }, 0, true),
    ).toBe(false);
  });

  it('maps a csrf envelope without collapsing it to unauthenticated', () => {
    const failure = toApiFailure(
      {
        errors: [{ code: 'CSRF_MISMATCH', message: 'reload' }],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      },
      403,
    );
    expect(failure.code).toBe('CSRF_MISMATCH');
    expect(failure.status).toBe(403);
  });
});
