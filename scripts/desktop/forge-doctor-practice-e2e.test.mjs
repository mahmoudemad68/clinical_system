import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  PRACTICE_E2E_JOURNEY_KEYS,
  PRACTICE_E2E_REQUIRED_FLAG,
  assertDoctorPracticeE2EEvidence,
  envFlagEnabled,
  isDoctorPracticeE2ERequired,
} from './run-forge-doctor-practice-e2e.mjs';

const executed = Object.fromEntries(PRACTICE_E2E_JOURNEY_KEYS.map((key) => [key, true]));

describe('Forge Doctor practice E2E required-mode helpers', () => {
  it('enables the explicit gate flag the same way Core CLINIC_REQUIRE_* flags do', () => {
    for (const value of ['1', 'true', 'TRUE', 'yes', 'on']) {
      assert.equal(envFlagEnabled(PRACTICE_E2E_REQUIRED_FLAG, { [PRACTICE_E2E_REQUIRED_FLAG]: value }), true);
    }
    for (const value of ['', '0', 'false', 'off', 'no', undefined]) {
      assert.equal(
        envFlagEnabled(PRACTICE_E2E_REQUIRED_FLAG, value === undefined ? {} : { [PRACTICE_E2E_REQUIRED_FLAG]: value }),
        false,
      );
    }
  });

  it('does not infer required mode from CI=true', () => {
    assert.equal(isDoctorPracticeE2ERequired({ CI: 'true' }), false);
    assert.equal(
      isDoctorPracticeE2ERequired({ CI: 'true', [PRACTICE_E2E_REQUIRED_FLAG]: '1' }),
      true,
    );
  });

  it('rejects skipped evidence even when journey booleans are present', () => {
    assert.throws(
      () =>
        assertDoctorPracticeE2EEvidence({
          kind: 'forge-development-practice-e2e',
          skipped: true,
          ...executed,
        }),
      /skipped=true/,
    );
  });

  it('rejects missing journey booleans when skipped is false', () => {
    assert.throws(
      () =>
        assertDoctorPracticeE2EEvidence({
          kind: 'forge-development-practice-e2e',
          skipped: false,
          created: true,
        }),
      /edited=undefined/,
    );
  });

  it('accepts a fully executed journey', () => {
    const payload = {
      kind: 'forge-development-practice-e2e',
      skipped: false,
      required: true,
      ...executed,
    };
    assert.equal(assertDoctorPracticeE2EEvidence(payload), payload);
  });
});
