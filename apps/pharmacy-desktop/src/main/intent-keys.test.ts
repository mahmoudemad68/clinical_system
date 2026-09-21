import { describe, expect, it } from 'vitest';
import { IntentKeyStore } from './intent-keys';

describe('pharmacy intent keys', () => {
  it('reuses one key for the same fingerprint and mints a new key when the payload changes', () => {
    const store = new IntentKeyStore();
    const first = store.keyFor('onboard', store.fingerprint({ legalName: 'A' }));
    const retry = store.keyFor('onboard', store.fingerprint({ legalName: 'A' }));
    const changed = store.keyFor('onboard', store.fingerprint({ legalName: 'B' }));
    expect(retry).toBe(first);
    expect(changed).not.toBe(first);
  });

  it('clears a key after success so a later mutation is a new intent', () => {
    const store = new IntentKeyStore();
    const fingerprint = store.fingerprint({ caseVersion: 1 });
    const first = store.keyFor('submit', fingerprint);
    store.clear('submit', fingerprint);
    const second = store.keyFor('submit', fingerprint);
    expect(second).not.toBe(first);
  });
});
