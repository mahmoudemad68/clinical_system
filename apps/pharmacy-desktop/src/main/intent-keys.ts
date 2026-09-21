/**
 * Main-owned idempotency keys for pharmacy mutations.
 *
 * One logical mutation keeps one key until it succeeds or the payload
 * fingerprint changes. Uncertain network outcomes keep the key so a retry
 * cannot mint a second server intent. Production code never logs the payload.
 */

import { createHash, randomUUID } from 'node:crypto';

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

  clear(intent: string, fingerprint: string): void {
    this.keys.delete(`${intent}:${fingerprint}`);
  }

  clearAll(): void {
    this.keys.clear();
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
