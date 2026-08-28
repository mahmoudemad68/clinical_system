import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  describeNpmSpawnResult,
  npmSpawnInvocation,
  quoteCmdArgument,
} from './npm-spawn.mjs';

describe('npmSpawnInvocation', () => {
  it('spawns npm directly on POSIX platforms', () => {
    const args = ['run', 'package', '--workspace=apps/doctor-desktop'];
    for (const platform of ['linux', 'darwin']) {
      const invocation = npmSpawnInvocation(args, { platform });
      assert.equal(invocation.command, 'npm');
      assert.deepEqual(invocation.args, args);
      assert.equal(invocation.options.windowsVerbatimArguments, undefined);
    }
  });

  it('invokes npm through cmd.exe on Windows so npm.cmd can run', () => {
    const invocation = npmSpawnInvocation(
      ['run', 'package', '--workspace=apps/doctor-desktop'],
      { platform: 'win32', comSpec: 'C:\\Windows\\system32\\cmd.exe' },
    );
    assert.equal(invocation.command, 'C:\\Windows\\system32\\cmd.exe');
    assert.deepEqual(invocation.args, [
      '/d',
      '/s',
      '/c',
      '"npm run package --workspace=apps/doctor-desktop"',
    ]);
    assert.equal(invocation.options.windowsVerbatimArguments, true);
  });

  it('quotes Windows arguments that contain spaces', () => {
    const invocation = npmSpawnInvocation(
      ['ci', '--prefix', 'D:\\Program Files\\clinic\\tests\\desktop-e2e'],
      { platform: 'win32', comSpec: 'cmd.exe' },
    );
    assert.equal(
      invocation.args[3],
      '"npm ci --prefix "D:\\Program Files\\clinic\\tests\\desktop-e2e""',
    );
  });

  it('falls back to cmd.exe when ComSpec is empty', () => {
    const invocation = npmSpawnInvocation(['ci'], { platform: 'win32', comSpec: '' });
    assert.equal(invocation.command, 'cmd.exe');
  });
});

describe('quoteCmdArgument', () => {
  it('leaves ordinary npm flags unquoted', () => {
    assert.equal(quoteCmdArgument('--workspace=apps/doctor-desktop'), '--workspace=apps/doctor-desktop');
  });

  it('quotes empty and space-containing values', () => {
    assert.equal(quoteCmdArgument(''), '""');
    assert.equal(quoteCmdArgument('Clinic Doctor'), '"Clinic Doctor"');
  });
});

describe('describeNpmSpawnResult', () => {
  const invocation = { command: 'npm', args: ['run', 'package'] };

  it('reports spawn failures separately from process exit codes', () => {
    const error = Object.assign(new Error('spawn npm ENOENT'), { code: 'ENOENT' });
    const described = describeNpmSpawnResult(invocation, { error, status: null });
    assert.equal(described.ok, false);
    assert.equal(described.kind, 'spawn-error');
    assert.match(described.message, /Failed to spawn npm run package \(ENOENT\)/);
    assert.doesNotMatch(described.message, /status null/);
  });

  it('reports a child exit status when the process started', () => {
    const described = describeNpmSpawnResult(invocation, { status: 1 });
    assert.equal(described.kind, 'exit-code');
    assert.equal(described.message, 'npm run package exited with status 1');
  });

  it('treats status 0 as success', () => {
    const described = describeNpmSpawnResult(invocation, { status: 0 });
    assert.equal(described.ok, true);
    assert.equal(described.kind, 'ok');
  });
});
