import { uuidV7 } from '@clinic/api-client';

export interface DecisionPayload {
  decision: string;
  reason_code: string;
  expected_case_version: number;
  notes?: string | null;
}

function fingerprint(payload: DecisionPayload): string {
  return JSON.stringify({
    decision: payload.decision,
    reason_code: payload.reason_code,
    expected_case_version: payload.expected_case_version,
    notes: payload.notes ?? null,
  });
}

/**
 * One UUID per logical decision payload. Network retries of the exact same
 * body reuse the key. A changed payload mints a new key. Keys are never
 * persisted across cases or browser storage.
 */
export function createDecisionIdempotency() {
  let key: string | null = null;
  let lastFingerprint: string | null = null;

  return {
    keyFor(payload: DecisionPayload): string {
      const next = fingerprint(payload);
      if (key === null || lastFingerprint !== next) {
        key = uuidV7();
        lastFingerprint = next;
      }

      return key;
    },
    reset(): void {
      key = null;
      lastFingerprint = null;
    },
  };
}
