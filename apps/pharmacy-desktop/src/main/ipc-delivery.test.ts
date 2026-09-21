import { describe, expect, it } from 'vitest';
import { IntentKeyStore } from './intent-keys';
import { TimeoutError, createDeferred, openIpcDeadline, runIpcDelivered } from './ipc-delivery';

function hang(): Promise<never> {
  return new Promise(() => undefined);
}

describe('IPC delivery tickets', () => {
  it('keeps the original key when the caller sees TIMEOUT and the mutation later succeeds', async () => {
    const store = new IntentKeyStore();
    const fingerprint = store.fingerprint({ legalName: 'A' });
    const held = createDeferred<string>();
    const deadline = openIpcDeadline();

    const pending = runIpcDelivered(
      store,
      async () => {
        const key = store.keyFor('onboard', fingerprint);
        const value = await held.promise;
        store.retireWhenDelivered('onboard', fingerprint, key);
        return value;
      },
      deadline.promise,
    );

    await Promise.resolve();
    const original = store.peek('onboard', fingerprint);
    expect(original).toEqual(expect.any(String));

    deadline.expire();
    await expect(pending).rejects.toBeInstanceOf(TimeoutError);

    held.resolve('organization_ready');
    await Promise.resolve();
    await Promise.resolve();

    expect(store.peek('onboard', fingerprint)).toBe(original);
    expect(store.keyFor('onboard', fingerprint)).toBe(original);
  });

  it('reuses the same key for open and submit after a caller-visible timeout', async () => {
    const store = new IntentKeyStore();

    async function timeoutThenRetry(intent: string, payload: unknown): Promise<void> {
      const fingerprint = store.fingerprint(payload);
      const held = createDeferred<string>();
      const deadline = openIpcDeadline();
      const pending = runIpcDelivered(
        store,
        async () => {
          const key = store.keyFor(intent, fingerprint);
          const value = await held.promise;
          store.retireWhenDelivered(intent, fingerprint, key);
          return value;
        },
        deadline.promise,
      );
      await Promise.resolve();
      const original = store.peek(intent, fingerprint);
      expect(original).toEqual(expect.any(String));
      deadline.expire();
      await expect(pending).rejects.toBeInstanceOf(TimeoutError);
      held.resolve('ok');
      await Promise.resolve();
      await Promise.resolve();
      expect(store.keyFor(intent, fingerprint)).toBe(original);
    }

    await timeoutThenRetry('pharmacy.verification.open', { intent: 'pharmacy.verification.open' });
    await timeoutThenRetry('pharmacy.verification.submit', { caseVersion: 1, organizationVersion: 1 });
  });

  it('retires the key after a caller-visible success so a later mutation can mint a new key', async () => {
    const store = new IntentKeyStore();
    const fingerprint = store.fingerprint({ legalName: 'A' });
    const first = await runIpcDelivered(
      store,
      async () => {
        const key = store.keyFor('onboard', fingerprint);
        store.retireWhenDelivered('onboard', fingerprint, key);
        return 'ready';
      },
      hang(),
    );
    expect(first).toBe('ready');
    expect(store.peek('onboard', fingerprint)).toBeUndefined();
    const second = store.keyFor('onboard', fingerprint);
    expect(second).toEqual(expect.any(String));
  });
});
