#!/usr/bin/env node
/**
 * Fail if Chunk 12 Forge Doctor practice evidence is skipped or incomplete.
 * CI must not treat process exit 0 plus skipped=true as a green gate.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
  assertDoctorPracticeE2EEvidence,
  practiceE2EEvidencePath,
} from './run-forge-doctor-practice-e2e.mjs';

const evidencePath = resolve(process.argv[2] ?? practiceE2EEvidencePath());
const payload = JSON.parse(readFileSync(evidencePath, 'utf8'));
assertDoctorPracticeE2EEvidence(payload);
process.stdout.write(`Practice E2E evidence executed (skipped=false). ${evidencePath}\n`);
