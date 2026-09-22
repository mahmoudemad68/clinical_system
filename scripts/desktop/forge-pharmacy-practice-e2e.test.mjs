import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  PHARMACY_PRACTICE_E2E_JOURNEY_KEYS,
  PHARMACY_PRACTICE_E2E_REQUIRED_FLAG,
  assertPharmacyPracticeE2EEvidence,
  envFlagEnabled,
  isPharmacyPracticeE2ERequired,
} from './run-forge-pharmacy-practice-e2e.mjs';

const executed = Object.fromEntries(
  PHARMACY_PRACTICE_E2E_JOURNEY_KEYS.map((key) => [key, key === 'core_health' ? 'operational' : true]),
);

describe('Forge Pharmacy practice E2E required-mode helpers', () => {
  it('enables the explicit gate flag the same way Core CLINIC_REQUIRE_* flags do', () => {
    for (const value of ['1', 'true', 'TRUE', 'yes', 'on']) {
      assert.equal(
        envFlagEnabled(PHARMACY_PRACTICE_E2E_REQUIRED_FLAG, { [PHARMACY_PRACTICE_E2E_REQUIRED_FLAG]: value }),
        true,
      );
    }
    for (const value of ['', '0', 'false', 'off', 'no', undefined]) {
      assert.equal(
        envFlagEnabled(
          PHARMACY_PRACTICE_E2E_REQUIRED_FLAG,
          value === undefined ? {} : { [PHARMACY_PRACTICE_E2E_REQUIRED_FLAG]: value },
        ),
        false,
      );
    }
  });

  it('does not infer required mode from CI=true', () => {
    assert.equal(isPharmacyPracticeE2ERequired({ CI: 'true' }), false);
    assert.equal(
      isPharmacyPracticeE2ERequired({ CI: 'true', [PHARMACY_PRACTICE_E2E_REQUIRED_FLAG]: '1' }),
      true,
    );
  });

  it('rejects skipped evidence even when journey booleans are present', () => {
    assert.throws(
      () =>
        assertPharmacyPracticeE2EEvidence({
          kind: 'forge-development-pharmacy-practice-e2e',
          skipped: true,
          ...executed,
        }),
      /skipped=true/,
    );
  });

  it('rejects missing journey booleans when skipped is false', () => {
    assert.throws(
      () =>
        assertPharmacyPracticeE2EEvidence({
          kind: 'forge-development-pharmacy-practice-e2e',
          skipped: false,
          core_health: 'operational',
          branch_created: true,
        }),
      /branch_edited=undefined/,
    );
  });

  it('rejects non-operational core_health', () => {
    assert.throws(
      () =>
        assertPharmacyPracticeE2EEvidence({
          kind: 'forge-development-pharmacy-practice-e2e',
          skipped: false,
          ...executed,
          core_health: 'degraded',
        }),
      /core_health="degraded"/,
    );
  });

  it('accepts a fully executed journey', () => {
    const payload = {
      kind: 'forge-development-pharmacy-practice-e2e',
      skipped: false,
      required: true,
      ...executed,
    };
    assert.equal(assertPharmacyPracticeE2EEvidence(payload), payload);
  });
});
