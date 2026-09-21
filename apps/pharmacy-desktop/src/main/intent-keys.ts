/**
 * Main-owned idempotency keys for pharmacy mutations.
 *
 * One logical mutation keeps one key until a caller-visible success (or a
 * delivered terminal 4xx) acknowledges it. A TIMEOUT or other uncertain
 * result leaves the key in place so a retry cannot mint a second server
 * intent. Late completion of the original promise must not retire that key.
 * Production code never logs the payload.
 */

import { AsyncLocalStorage } from 'node:async_hooks';
import { createHash, randomUUID } from 'node:crypto';

export type DeliveryTicket = {
  closed: boolean;
  pending: Array<{ intent: string; fingerprint: string; key: string }>;
};

const deliveryTickets = new AsyncLocalStorage<DeliveryTicket>();

export class IntentKeyStore {
  private readonly keys = new Map<string, string>();

  fingerprint(value: unknown): string {
    return createHash('sha256').update(canonicalJson(value)).digest('hex');
  }

  keyFor(intent: string, fingerprint: string): string {
    const mapKey = `${intent}:${fingerprint}`;
    const existing = this.keys.get(mapKey);
    if (existing !== undefined) {
      return existing;
    }
    const minted = randomUUID();
    this.keys.set(mapKey, minted);
    return minted;
  }

  peek(intent: string, fingerprint: string): string | undefined {
    return this.keys.get(`${intent}:${fingerprint}`);
  }

  openTicket(): DeliveryTicket {
    return { closed: false, pending: [] };
  }

  runInTicket<T>(ticket: DeliveryTicket, operation: () => Promise<T>): Promise<T> {
    return deliveryTickets.run(ticket, operation);
  }

  /**
   * Retire this exact key only after the IPC caller actually received the
   * outcome. If the delivery ticket already lost the deadline race, this is
   * a no-op so a late HTTP success cannot erase the key a retry still needs.
   */
  retireWhenDelivered(intent: string, fingerprint: string, key: string): void {
    const ticket = deliveryTickets.getStore();
    if (ticket === undefined) {
      this.clearIfCurrent(intent, fingerprint, key);
      return;
    }
    if (ticket.closed) {
      return;
    }
    ticket.pending.push({ intent, fingerprint, key });
  }

  acknowledge(ticket: DeliveryTicket): void {
    if (ticket.closed) {
      return;
    }
    ticket.closed = true;
    for (const item of ticket.pending) {
      this.clearIfCurrent(item.intent, item.fingerprint, item.key);
    }
    ticket.pending.length = 0;
  }

  abandon(ticket: DeliveryTicket): void {
    if (ticket.closed) {
      return;
    }
    ticket.closed = true;
    ticket.pending.length = 0;
  }

  clear(intent: string, fingerprint: string): void {
    this.keys.delete(`${intent}:${fingerprint}`);
  }

  clearAll(): void {
    this.keys.clear();
  }

  private clearIfCurrent(intent: string, fingerprint: string, key: string): void {
    const mapKey = `${intent}:${fingerprint}`;
    if (this.keys.get(mapKey) === key) {
      this.keys.delete(mapKey);
    }
  }
}

function canonicalJson(value: unknown): string {
  if (value === null || typeof value !== 'object') {
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) {
    return `[${value.map((entry) => canonicalJson(entry)).join(',')}]`;
  }
  const record = value as Record<string, unknown>;
  const keys = Object.keys(record).sort();
  return `{${keys.map((key) => `${JSON.stringify(key)}:${canonicalJson(record[key])}`).join(',')}}`;
}
