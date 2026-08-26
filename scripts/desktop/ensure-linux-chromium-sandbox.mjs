#!/usr/bin/env node
/**
 * Configure Chromium's SUID sandbox helper on Linux.
 *
 * Electron aborts when `chrome-sandbox` exists but is not root-owned mode 4755.
 * This script sets that helper. It does **not** disable Chromium sandboxing.
 *
 * Must run from a desktop app directory (or any cwd that can resolve `electron`).
 */
import { existsSync, statSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

if (process.platform !== 'linux') {
  process.exit(0);
}

function resolveSandboxHelper() {
  const searchRoots = [process.cwd()];
  let dir = path.dirname(fileURLToPath(import.meta.url));

  for (let i = 0; i < 8; i += 1) {
    searchRoots.push(dir);
    dir = path.dirname(dir);
  }

  for (const root of searchRoots) {
    try {
      const requireFromRoot = createRequire(pathToFileURL(path.join(root, 'package.json')));
      const helper = path.join(
        path.dirname(requireFromRoot.resolve('electron/package.json')),
        'dist',
        'chrome-sandbox',
      );

      if (existsSync(helper)) {
        return helper;
      }
    } catch {
      // Try the next candidate root.
    }
  }

  return null;
}

const sandbox = resolveSandboxHelper();

if (!sandbox) {
  console.error('Could not resolve Electron chrome-sandbox from the current directory or this script.');
  process.exit(1);
}

function isConfigured() {
  const st = statSync(sandbox);
  return st.uid === 0 && (st.mode & 0o4000) !== 0;
}

if (isConfigured()) {
  process.exit(0);
}

console.error('Linux Chromium SUID sandbox is not configured.');
console.error('This keeps the OS sandbox enabled. It sets chrome-sandbox to root:root mode 4755.');
console.error(`Helper: ${sandbox}`);

const chown = spawnSync('sudo', ['chown', 'root:root', sandbox], { stdio: 'inherit' });
if (chown.status !== 0) {
  console.error('Failed to chown chrome-sandbox. Run:');
  console.error(`  sudo chown root:root "${sandbox}" && sudo chmod 4755 "${sandbox}"`);
  process.exit(1);
}

const chmod = spawnSync('sudo', ['chmod', '4755', sandbox], { stdio: 'inherit' });
if (chmod.status !== 0 || !isConfigured()) {
  console.error('Failed to chmod chrome-sandbox. Run:');
  console.error(`  sudo chmod 4755 "${sandbox}"`);
  process.exit(1);
}
