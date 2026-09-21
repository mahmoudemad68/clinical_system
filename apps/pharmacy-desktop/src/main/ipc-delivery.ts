/**
 * Separate "the mutation finished in main" from "the renderer received a
 * contract-valid result". IPC timeouts reject the caller without cancelling
 * the underlying work. A resolved value that fails the channel response
 * schema is also an uncertain outcome: the ticket is abandoned, not retired.
 */

import type { IntentKeyStore } from './intent-keys';

export class TimeoutError extends Error {
  constructor() {
    super('TIMEOUT');
    this.name = 'TimeoutError';
  }
}

export class ResponseContractError extends Error {
  constructor() {
    super('INTERNAL_ERROR');
    this.name = 'ResponseContractError';
  }
}

export function createDeferred<T>(): {
  promise: Promise<T>;
  resolve: (value: T | PromiseLike<T>) => void;
  reject: (reason?: unknown) => void;
} {
  let resolve!: (value: T | PromiseLike<T>) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

export function openIpcDeadline(): { promise: Promise<never>; expire: () => void } {
  const deferred = createDeferred<never>();
  return {
    promise: deferred.promise,
    expire: () => {
      deferred.reject(new TimeoutError());
    },
  };
}

export function timeoutDeadline(ms: number): Promise<never> {
  const deadline = openIpcDeadline();
  setTimeout(() => {
    deadline.expire();
  }, ms);
  return deadline.promise;
}

export function acceptIpcSchema<T>(schema: {
  safeParse: (value: unknown) => { success: true; data: T } | { success: false };
}): (value: unknown) => T {
  return (value: unknown) => {
    const parsed = schema.safeParse(value);
    if (!parsed.success) {
      throw new ResponseContractError();
    }
    return parsed.data;
  };
}

export async function runIpcDelivered<T, U>(
  intents: IntentKeyStore,
  operation: () => Promise<T>,
  deadline: Promise<never>,
  deliver: (value: T) => U,
): Promise<U> {
  const ticket = intents.openTicket();
  const running = intents.runInTicket(ticket, operation);
  try {
    const value = await Promise.race([running, deadline]);
    let accepted: U;
    try {
      accepted = deliver(value);
    } catch {
      intents.abandon(ticket);
      throw new ResponseContractError();
    }
    intents.acknowledge(ticket);
    return accepted;
  } catch (error) {
    void running.then(
      () => undefined,
      () => undefined,
    );
    if (error instanceof ResponseContractError) {
      throw error;
    }
    if (error instanceof TimeoutError) {
      intents.abandon(ticket);
    } else {
      intents.acknowledge(ticket);
    }
    throw error;
  }
}
