import { describe, expect, it } from 'vitest';
import { unwrapEnvelope } from './index';

describe('envelope mapping', () => {
  it('unwraps a success envelope', () => {
    const result = unwrapEnvelope<{ status: string }>(
      { data: { status: 'ok' }, meta: {}, errors: [], request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01' },
      200,
    );
    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.data.status).toBe('ok');
    }
  });

  it('maps a csrf failure without treating it as success', () => {
    const result = unwrapEnvelope(
      {
        data: null,
        meta: {},
        errors: [{ code: 'CSRF_MISMATCH', message: 'reload' }],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      },
      403,
    );
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.failure.code).toBe('CSRF_MISMATCH');
    }
  });
});
