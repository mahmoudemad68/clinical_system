#!/usr/bin/env node
/**
 * Fail if Chunk 13 Flutter patient-profile Core evidence is skipped or missing.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const evidencePath = resolve(
  process.argv[2] ?? 'tests/flutter-e2e/logs/patient-profile-e2e.json',
);
const payload = JSON.parse(readFileSync(evidencePath, 'utf8'));
if (payload.skipped === true) {
  console.error('Patient Flutter profile E2E evidence reports skipped=true.');
  process.exit(1);
}
if (payload.skipped !== false) {
  console.error('Patient Flutter profile E2E evidence must set skipped=false.');
  process.exit(1);
}
if (payload.version_conflict !== true || payload.isolation !== true) {
  console.error('Patient Flutter profile E2E evidence is incomplete.');
  process.exit(1);
}
process.stdout.write(`Patient Flutter profile E2E executed (skipped=false). ${evidencePath}\n`);
