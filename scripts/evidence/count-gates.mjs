#!/usr/bin/env node
/**
 * Count evidence-ledger gate rows.
 *
 * Exists because a hand-written total was wrong: an earlier summary counted the
 * legend and the test-plan category table as gates and reported
 * "25 PASS / 20 PARTIAL / 23 OPEN / 2 BLOCKED" when the real figures were
 * different. A total nobody can reproduce is not evidence.
 *
 * Matches only rows whose first cell is a gate id (G-NN-NN).
 *
 * Exit 1 if any gate id is duplicated, or if `--expect PASS=..,OPEN=..` is
 * given and the counts disagree — so CI can hold the summary honest.
 */

import { readFileSync } from 'node:fs';

const LEDGER = 'docs/evidence/phase-00/evidence-ledger.md';
const ROW = /^\| (G-\d\d-\d\d) \|.*?\| `(PASS|PARTIAL|BLOCKED|OPEN)`(?: \([^)]*\))? \|/gm;

const text = readFileSync(LEDGER, 'utf8');
const rows = [...text.matchAll(ROW)].map((m) => ({ id: m[1], result: m[2] }));

if (rows.length === 0) {
  console.error(`No gate rows matched in ${LEDGER}. Has the table format changed?`);
  process.exit(1);
}

const counts = { PASS: 0, PARTIAL: 0, BLOCKED: 0, OPEN: 0 };
const seen = new Map();
const duplicates = [];

for (const { id, result } of rows) {
  counts[result] += 1;
  if (seen.has(id)) duplicates.push(id);
  seen.set(id, result);
}

console.log(`Gate rows in ${LEDGER}: ${rows.length}`);
for (const key of Object.keys(counts)) console.log(`  ${key.padEnd(8)} ${counts[key]}`);

let failed = false;

if (duplicates.length > 0) {
  console.error(`\nDuplicate gate ids: ${[...new Set(duplicates)].join(', ')}`);
  failed = true;
}

const expectArg = process.argv.indexOf('--expect');
if (expectArg !== -1 && process.argv[expectArg + 1]) {
  for (const pair of process.argv[expectArg + 1].split(',')) {
    const [key, value] = pair.split('=');
    if (counts[key] !== Number(value)) {
      console.error(`\nExpected ${key}=${value}, found ${counts[key]}. Update the ledger summary.`);
      failed = true;
    }
  }
}

process.exit(failed ? 1 : 0);
