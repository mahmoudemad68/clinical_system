import { randomUUID } from 'node:crypto';

/**
 * Refresh is successful only when a complete replacement pair is durably
 * persisted. A 2xx body without both tokens, or a persist failure, is a
 * protocol/upstream failure: the retained Idempotency-Key stays so a lost
 * response can be retried.
 */

export const REFRESH_PROTOCOL_FAILURE = 'PROTOCOL_FAILURE';

export type ReplacementTokens = {
  access: string;
  refresh: string;
};

export function replacementTokensFromRefreshData(data: unknown): ReplacementTokens | null {
  if (typeof data !== 'object' || data === null) {
    return null;
  }

  const record = data as Record<string, unknown>;
  const access = record['access_token'];
  const refresh = record['refresh_token'];

  if (typeof access !== 'string' || access.length === 0) {
    return null;
  }
  if (typeof refresh !== 'string' || refresh.length === 0) {
    return null;
  }

  return { access, refresh };
}

/**
 * Persist the complete pair first, then update in-memory copies.
 *
 * Throws without calling `remember` when the body is incomplete or `persist`
 * fails, so stale credentials remain the recoverable source of truth.
 */
export function persistCompleteReplacement(input: {
  data: unknown;
  persist: (tokens: ReplacementTokens) => void;
  remember: (tokens: ReplacementTokens) => void;
}): ReplacementTokens {
  const tokens = replacementTokensFromRefreshData(input.data);
  if (tokens === null) {
    throw new Error(REFRESH_PROTOCOL_FAILURE);
  }

  input.persist(tokens);
  input.remember(tokens);
  return tokens;
}

export class TokenRefreshSession {
  private key: string | null = null;

  currentKey(): string | null {
    return this.key;
  }

  clear(): void {
    this.key = null;
  }

  async run(input: {
    request: (idempotencyKey: string) => Promise<unknown>;
    persist: (tokens: ReplacementTokens) => void;
    remember: (tokens: ReplacementTokens) => void;
  }): Promise<boolean> {
    this.key ??= randomUUID();
    try {
      const data = await input.request(this.key);
      persistCompleteReplacement({
        data,
        persist: input.persist,
        remember: input.remember,
      });
      this.key = null;
      return true;
    } catch {
      return false;
    }
  }
}
