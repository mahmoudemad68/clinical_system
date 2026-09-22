#!/usr/bin/env node
/**
 * Fail if Chunk 15 Forge Pharmacy practice evidence is skipped or incomplete.
 * CI must not treat process exit 0 plus skipped=true as a green gate.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
  assertPharmacyPracticeE2EEvidence,
  pharmacyPracticeE2EEvidencePath,
} from './run-forge-pharmacy-practice-e2e.mjs';

const evidencePath = resolve(process.argv[2] ?? pharmacyPracticeE2EEvidencePath());
const payload = JSON.parse(readFileSync(evidencePath, 'utf8'));
assertPharmacyPracticeE2EEvidence(payload);
process.stdout.write(`Pharmacy practice E2E evidence executed (skipped=false). ${evidencePath}\n`);
