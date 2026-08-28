import assert from 'node:assert/strict';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';

import { chromeSandboxPath, stageLinuxPackagedAppForLaunch } from './packaged-app-paths.mjs';

describe('stageLinuxPackagedAppForLaunch', () => {
  it('copies a spaced Forge directory to a sibling space-free launch path', { skip: process.platform !== 'linux' }, () => {
    const root = mkdtempSync(join(tmpdir(), 'clinic-stage-'));
    const spacedDir = join(root, 'Clinic Doctor-linux-x64');
    mkdirSync(spacedDir, { recursive: true });
    const binary = join(spacedDir, 'clinic-doctor');
    writeFileSync(binary, 'fake-electron');
    chmodSync(binary, 0o755);
    writeFileSync(join(spacedDir, 'chrome-sandbox'), 'fake-sandbox');

    try {
      const staged = stageLinuxPackagedAppForLaunch(binary, 'doctor');
      assert.equal(staged.staged, true);
      assert.equal(staged.binary.includes(' '), false);
      assert.equal(staged.binary.endsWith('clinic-doctor'), true);
      assert.equal(existsSync(staged.binary), true);
      assert.equal(existsSync(chromeSandboxPath(staged.binary)), true);
      assert.equal(staged.stagedDir.endsWith('clinic-doctor-linux-e2e'), true);
    } finally {
      rmSync(root, { recursive: true, force: true });
    }
  });

  it('leaves a space-free packaged path in place', { skip: process.platform !== 'linux' }, () => {
    const root = mkdtempSync(join(tmpdir(), 'clinic-stage-'));
    const dir = join(root, 'clinic-doctor-linux-x64');
    mkdirSync(dir, { recursive: true });
    const binary = join(dir, 'clinic-doctor');
    writeFileSync(binary, 'fake-electron');

    try {
      const staged = stageLinuxPackagedAppForLaunch(binary, 'doctor');
      assert.equal(staged.staged, false);
      assert.equal(staged.binary, binary);
      assert.equal(staged.reason, 'path-has-no-space');
    } finally {
      rmSync(root, { recursive: true, force: true });
    }
  });
});
