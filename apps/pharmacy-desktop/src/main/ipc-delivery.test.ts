import { describe, expect, it } from 'vitest';
import { pharmacyOnboardResponseSchema } from '@clinic/desktop-bridge-contracts';
import { IntentKeyStore } from './intent-keys';
import {
  TimeoutError,
  acceptIpcSchema,
  createDeferred,
  openIpcDeadline,
  runIpcDelivered,
} from './ipc-delivery';

function hang(): Promise<never> {
  return new Promise(() => undefined);
}

function passThrough<T>(value: T): T {
  return value;
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
      passThrough,
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
        passThrough,
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

  it('does not retire the key when the resolved value fails the IPC response contract', async () => {
    const store = new IntentKeyStore();
    const fingerprint = store.fingerprint({ legalName: 'A' });
    const accept = acceptIpcSchema(pharmacyOnboardResponseSchema);

    await expect(
      runIpcDelivered(
        store,
        async () => {
          const key = store.keyFor('onboard', fingerprint);
          store.retireWhenDelivered('onboard', fingerprint, key);
          return { status: 'organization_ready' };
        },
        hang(),
        accept,
      ),
    ).rejects.toMatchObject({ name: 'ResponseContractError', message: 'INTERNAL_ERROR' });

    const original = store.peek('onboard', fingerprint);
    expect(original).toEqual(expect.any(String));
    expect(store.keyFor('onboard', fingerprint)).toBe(original);

    const delivered = await runIpcDelivered(
      store,
      async () => {
        const key = store.keyFor('onboard', fingerprint);
        store.retireWhenDelivered('onboard', fingerprint, key);
        return {
          status: 'organization_ready' as const,
          organizationId: '0199a5c8-0000-7000-8000-000000000010',
          branchId: '0199a5c8-0000-7000-8000-000000000011',
          membershipId: '0199a5c8-0000-7000-8000-000000000012',
          version: 1,
        };
      },
      hang(),
      accept,
    );
    expect(delivered.status).toBe('organization_ready');
    expect(store.peek('onboard', fingerprint)).toBeUndefined();
    expect(store.keyFor('onboard', fingerprint)).not.toBe(original);
  });

  it('acknowledges a queued terminal rejection and keeps a key that was never queued', async () => {
    const store = new IntentKeyStore();
    const terminalFingerprint = store.fingerprint({ legalName: 'A' });

    await expect(
      runIpcDelivered(
        store,
        async () => {
          const key = store.keyFor('onboard', terminalFingerprint);
          store.retireWhenDelivered('onboard', terminalFingerprint, key);
          throw new Error('VALIDATION_FAILED');
        },
        hang(),
        passThrough,
      ),
    ).rejects.toThrow('VALIDATION_FAILED');
    expect(store.peek('onboard', terminalFingerprint)).toBeUndefined();

    const uncertainFingerprint = store.fingerprint({ legalName: 'B' });
    const original = store.keyFor('onboard', uncertainFingerprint);
    await expect(
      runIpcDelivered(
        store,
        async () => {
          store.keyFor('onboard', uncertainFingerprint);
          throw new Error('UPSTREAM_FAILED');
        },
        hang(),
        passThrough,
      ),
    ).rejects.toThrow('UPSTREAM_FAILED');
    expect(store.peek('onboard', uncertainFingerprint)).toBe(original);
    expect(store.keyFor('onboard', uncertainFingerprint)).toBe(original);
  });

  it('retires the key after a caller-deliverable success so a later mutation can mint a new key', async () => {
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
      passThrough,
    );
    expect(first).toBe('ready');
    expect(store.peek('onboard', fingerprint)).toBeUndefined();
    const second = store.keyFor('onboard', fingerprint);
    expect(second).toEqual(expect.any(String));
  });
});
