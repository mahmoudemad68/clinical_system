/**
 * Separate "the mutation finished in main" from "the renderer received the
 * result". IPC timeouts reject the caller without cancelling the underlying
 * work, so acknowledgement must not run on that late completion.
 */

import type { IntentKeyStore } from './intent-keys';

export class TimeoutError extends Error {
  constructor() {
    super('TIMEOUT');
    this.name = 'TimeoutError';
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

export async function runIpcDelivered<T>(
  intents: IntentKeyStore,
  operation: () => Promise<T>,
  deadline: Promise<never>,
): Promise<T> {
  const ticket = intents.openTicket();
  const running = intents.runInTicket(ticket, operation);
  try {
    const value = await Promise.race([running, deadline]);
    intents.acknowledge(ticket);
    return value;
  } catch (error) {
    void running.then(
      () => undefined,
      () => undefined,
    );
    if (error instanceof TimeoutError) {
      intents.abandon(ticket);
    } else {
      intents.acknowledge(ticket);
    }
    throw error;
  }
}
