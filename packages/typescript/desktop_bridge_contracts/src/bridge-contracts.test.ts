import { describe, expect, it } from 'vitest';
import { BRIDGE_CONTRACT_VERSION, MAX_IPC_PAYLOAD_BYTES, bridgeErrorSchema } from './index';

describe('desktop bridge contracts', () => {
  it('keeps a versioned size-bounded error envelope', () => {
    expect(BRIDGE_CONTRACT_VERSION).toBe(1);
    expect(MAX_IPC_PAYLOAD_BYTES).toBe(256 * 1024);
    const parsed = bridgeErrorSchema.parse({
      code: 'UNAUTHENTICATED',
      message: 'session required',
    });
    expect(parsed.code).toBe('UNAUTHENTICATED');
  });
});
