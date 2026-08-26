#!/usr/bin/env node
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Inspect packaged Electron ASAR files for a live custom-scheme origin.
 * Fails if a packaged renderer still loads file:// as the document origin.
 */

const roots = [
  join('apps', 'doctor-desktop', 'out'),
  join('apps', 'pharmacy-desktop', 'out'),
];

function walk(dir, acc = []) {
  if (!existsSync(dir)) {
    return acc;
  }
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const stat = statSync(full);
    if (stat.isDirectory()) {
      walk(full, acc);
    } else if (entry.endsWith('.asar')) {
      acc.push(full);
    }
  }
  return acc;
}

const asars = roots.flatMap((root) => walk(root));
if (asars.length === 0) {
  console.error('No packaged app.asar found. Run npm run desktop:package first.');
  process.exit(2);
}

const asarBin = join('node_modules', '@electron', 'asar', 'bin', 'asar.js');
const listed = asars.map((file) => {
  const list = existsSync(asarBin)
    ? execFileSync(process.execPath, [asarBin, 'list', file], { encoding: 'utf8' })
    : spawnSync('npx', ['--yes', 'asar', 'list', file], { encoding: 'utf8' }).stdout;
  return { file, list };
});

let failed = false;
for (const { file, list } of listed) {
  const mainCandidates = list
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => /main.*\.(js|cjs)$/.test(line) || line.includes('.webpack/main'));
  console.log(`asar ${file}`);
  console.log(`entries=${list.split('\n').filter(Boolean).length}`);

  const bytes = readFileSync(file);
  const asText = bytes.toString('latin1');
  const hasCustomDoctor = asText.includes('clinic-doctor-app://');
  const hasCustomPharmacy = asText.includes('clinic-pharmacy-app://');
  const webpackFileOrigin = /MAIN_WINDOW_WEBPACK_ENTRY.*file:\/\//.test(asText);
  if (!hasCustomDoctor && !hasCustomPharmacy) {
    console.error('missing custom packaged origin in asar');
    failed = true;
  }
  if (webpackFileOrigin && !(hasCustomDoctor || hasCustomPharmacy)) {
    console.error('packaged origin appears to be file:// only');
    failed = true;
  }
  console.log(`custom_origin=${hasCustomDoctor || hasCustomPharmacy} webpack_file_string_present=${asText.includes('file://')}`);
}

if (failed) {
  process.exit(1);
}

console.log('packaged asar origin inspection passed');
