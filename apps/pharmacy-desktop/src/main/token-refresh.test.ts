import { describe, expect, it } from 'vitest';
import {
  persistCompleteReplacement,
  replacementTokensFromRefreshData,
  TokenRefreshSession,
} from './token-refresh';

describe('Clinic Pharmacy — credential refresh success invariant', () => {
  const complete = { access_token: 'access-2', refresh_token: 'refresh-2' };
  const previous = { access: 'access-1', refresh: 'refresh-1' };

  it('A. persists a complete 2xx replacement, then clears the retained key', async () => {
    const session = new TokenRefreshSession();
    const persisted: unknown[] = [];
    const remembered: unknown[] = [];
    const keys: string[] = [];

    const ok = await session.run({
      request: async (key) => {
        keys.push(key);
        return complete;
      },
      persist: (tokens) => {
        persisted.push({ ...tokens });
      },
      remember: (tokens) => {
        remembered.push({ ...tokens });
      },
    });

    expect(ok).toBe(true);
    expect(persisted).toEqual([{ access: 'access-2', refresh: 'refresh-2' }]);
    expect(remembered).toEqual([{ access: 'access-2', refresh: 'refresh-2' }]);
    expect(session.currentKey()).toBeNull();
    expect(keys).toHaveLength(1);
  });

  it('B. treats a 2xx missing access token as failure and retains the key', async () => {
    const session = new TokenRefreshSession();
    let persistCalls = 0;
    const ok = await session.run({
      request: async () => ({ refresh_token: 'refresh-2' }),
      persist: () => {
        persistCalls += 1;
      },
      remember: () => {
        throw new Error('must not remember partial credentials');
      },
    });

    expect(ok).toBe(false);
    expect(persistCalls).toBe(0);
    expect(session.currentKey()).toBeTruthy();
    expect(replacementTokensFromRefreshData({ refresh_token: 'refresh-2' })).toBeNull();
  });

  it('C. treats a 2xx missing refresh token as failure and retains the key', async () => {
    const session = new TokenRefreshSession();
    let persistCalls = 0;
    const ok = await session.run({
      request: async () => ({ access_token: 'access-2' }),
      persist: () => {
        persistCalls += 1;
      },
      remember: () => {
        throw new Error('must not remember partial credentials');
      },
    });

    expect(ok).toBe(false);
    expect(persistCalls).toBe(0);
    expect(session.currentKey()).toBeTruthy();
  });

  it('D. treats a malformed 2xx body as failure and retains the key', async () => {
    const session = new TokenRefreshSession();
    const ok = await session.run({
      request: async () => 'not-json-object',
      persist: () => {
        throw new Error('must not persist malformed body');
      },
      remember: () => {
        throw new Error('must not remember malformed body');
      },
    });

    expect(ok).toBe(false);
    expect(session.currentKey()).toBeTruthy();
  });

  it('E. treats persistence failure after a valid 2xx as failure and retains the key', async () => {
    const session = new TokenRefreshSession();
    let remembered = false;
    const ok = await session.run({
      request: async () => complete,
      persist: () => {
        throw new Error('disk full');
      },
      remember: () => {
        remembered = true;
      },
    });

    expect(ok).toBe(false);
    expect(remembered).toBe(false);
    expect(session.currentKey()).toBeTruthy();
  });

  it('F. retries the same retained Idempotency-Key after a lost or malformed response', async () => {
    const session = new TokenRefreshSession();
    const keys: string[] = [];

    await session.run({
      request: async (key) => {
        keys.push(key);
        return { access_token: 'only-access' };
      },
      persist: () => undefined,
      remember: () => undefined,
    });

    const retained = session.currentKey();
    expect(retained).toBe(keys[0]);

    const persisted: unknown[] = [];
    const ok = await session.run({
      request: async (key) => {
        keys.push(key);
        return complete;
      },
      persist: (tokens) => {
        persisted.push({ ...tokens });
      },
      remember: () => undefined,
    });

    expect(ok).toBe(true);
    expect(keys[1]).toBe(keys[0]);
    expect(persisted).toEqual([{ access: 'access-2', refresh: 'refresh-2' }]);
    expect(session.currentKey()).toBeNull();
  });

  it('G. clears the retained key exactly once after durable replacement', async () => {
    const session = new TokenRefreshSession();
    const keys: string[] = [];

    await session.run({
      request: async (key) => {
        keys.push(key);
        return complete;
      },
      persist: () => undefined,
      remember: () => undefined,
    });
    expect(session.currentKey()).toBeNull();

    await session.run({
      request: async (key) => {
        keys.push(key);
        return { access_token: 'access-3', refresh_token: 'refresh-3' };
      },
      persist: () => undefined,
      remember: () => undefined,
    });

    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBe(keys[1]);
    expect(session.currentKey()).toBeNull();
  });

  it('does not replace existing credentials with a partial pair', () => {
    const remembered: unknown[] = [];
    expect(() =>
      persistCompleteReplacement({
        data: { access_token: 'partial' },
        persist: () => {
          throw new Error('must not persist partial');
        },
        remember: (tokens) => {
          remembered.push(tokens);
        },
      }),
    ).toThrow('PROTOCOL_FAILURE');
    expect(remembered).toEqual([]);
    expect(previous).toEqual({ access: 'access-1', refresh: 'refresh-1' });
  });
});
