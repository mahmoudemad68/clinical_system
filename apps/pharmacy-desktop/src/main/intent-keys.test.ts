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

  it('clears a key after a delivered success so a later mutation is a new intent', () => {
    const store = new IntentKeyStore();
    const fingerprint = store.fingerprint({ caseVersion: 1 });
    const first = store.keyFor('submit', fingerprint);
    store.clear('submit', fingerprint);
    const second = store.keyFor('submit', fingerprint);
    expect(second).not.toBe(first);
  });

  it('stores only SHA-256 fingerprints and random UUIDs, never raw address or phone', () => {
    const store = new IntentKeyStore();
    const address = 'CANARY-INTENT-ADDRESS-99 Nile St';
    const phone = 'CANARY-INTENT-PHONE-01099999999';
    const fingerprint = store.fingerprint({
      organizationId: '0199a5c8-0000-7000-8000-000000000010',
      publicName: 'Cairo Pharmacy',
      address,
      countryCode: 'EG',
      latitude: 30.0444,
      longitude: 31.2357,
      phone,
    });
    const key = store.keyFor('pharmacy.branch.create', fingerprint);
    expect(fingerprint).toMatch(/^[0-9a-f]{64}$/);
    expect(key).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    );
    expect(fingerprint).not.toContain(address);
    expect(fingerprint).not.toContain(phone);
    expect(key).not.toContain(address);
    expect(key).not.toContain(phone);
    const serialized = JSON.stringify(store.snapshot());
    expect(serialized).not.toContain(address);
    expect(serialized).not.toContain(phone);
  });
});
