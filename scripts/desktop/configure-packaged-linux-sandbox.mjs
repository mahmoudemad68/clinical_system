#!/usr/bin/env node
/**
 * Configure the packaged Chromium sandbox helper for Linux E2E.
 *
 * Forge `package` output ships `chrome-sandbox`. If that file exists but is
 * not root-owned mode 4755, Electron aborts. On Ubuntu 24.04+ AppArmor also
 * blocks unprivileged user namespaces unless the binary has a userns profile.
 *
 * This script never disables Chromium sandboxing. It:
 *   1. restores `chrome-sandbox` if a previous run moved it aside;
 *   2. sets the SUID helper (sudo -n, else Docker chroot as root);
 *   3. loads a binary-specific AppArmor userns profile when the kernel
 *      restriction is enabled.
 */
import { existsSync, mkdirSync, readFileSync, renameSync, statSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';
import { chromeSandboxPath } from './packaged-app-paths.mjs';

function readSysctl(path) {
  try {
    return readFileSync(path, 'utf8').trim();
  } catch {
    return null;
  }
}

function apparmorUsernsRestricted() {
  return readSysctl('/proc/sys/kernel/apparmor_restrict_unprivileged_userns') === '1';
}

function isSuidRoot(path) {
  const st = statSync(path);
  return st.uid === 0 && (st.mode & 0o4000) !== 0;
}

function privilegedRun(scriptBody) {
  const dir = join(tmpdir(), `clinic-priv-${process.pid}`);
  mkdirSync(dir, { recursive: true });
  const scriptPath = join(dir, 'run.sh');
  writeFileSync(scriptPath, `#!/bin/sh\nset -eu\n${scriptBody}\n`, { mode: 0o700 });

  const sudo = spawnSync('sudo', ['-n', 'sh', scriptPath], { stdio: 'inherit' });
  if (sudo.status === 0) {
    return { ok: true, via: 'sudo-n' };
  }

  const docker = spawnSync(
    'docker',
    ['run', '--rm', '--privileged', '-v', '/:/host', 'alpine:3.21', 'chroot', '/host', '/bin/sh', scriptPath],
    { stdio: 'inherit' },
  );
  if (docker.status === 0) {
    return { ok: true, via: 'docker-chroot' };
  }

  return {
    ok: false,
    via: null,
    sudoStatus: sudo.status,
    dockerStatus: docker.status,
    dockerError: docker.error?.message,
  };
}

function restoreHelper(helper) {
  const disabled = `${helper}.unconfigured`;
  if (existsSync(helper) || !existsSync(disabled)) {
    return helper;
  }
  try {
    renameSync(disabled, helper);
    return helper;
  } catch {
    const moved = privilegedRun(`mv '${disabled}' '${helper}'`);
    if (!moved.ok || !existsSync(helper)) {
      throw new Error(`Could not restore packaged chrome-sandbox from ${disabled}`);
    }
    return helper;
  }
}

function apparmorProfileContent(binaryPath) {
  const binaryName = basename(binaryPath).replace(/[^A-Za-z0-9._-]/g, '-');
  const profileName = `${binaryName}-clinic-packaged-e2e`;
  return {
    profileName,
    content: `# Clinic packaged Electron E2E — allow user namespaces for this binary only.
# Chromium sandboxing stays enabled. Do not use this profile as a production installer.
abi <abi/4.0>,
include <tunables/global>

profile ${profileName} "${binaryPath}" flags=(unconfined) {
  userns,
  include if exists <local/${profileName}>
}
`,
  };
}

function installApparmorProfile(binaryPath) {
  if (!apparmorUsernsRestricted()) {
    return { skipped: true, reason: 'userns-unrestricted' };
  }

  const { profileName, content } = apparmorProfileContent(binaryPath);
  const dest = `/etc/apparmor.d/${profileName}`;
  const tmpProfile = join(tmpdir(), `${profileName}.apparmor`);
  writeFileSync(tmpProfile, content);

  const result = privilegedRun(`cp '${tmpProfile}' '${dest}' && apparmor_parser -r '${dest}'`);
  if (!result.ok) {
    throw new Error(
      `AppArmor userns restriction is enabled and a profile for ${binaryPath} could not be loaded. Packaged Chromium cannot start a sandbox. sudo -n status=${result.sudoStatus}; docker status=${result.dockerStatus}${result.dockerError ? `; ${result.dockerError}` : ''}`,
    );
  }
  return { skipped: false, profileName, dest, via: result.via };
}

export function releasePackagedLinuxSandboxForRebuild(binaryPath) {
  if (process.platform !== 'linux') {
    return { skipped: true };
  }

  const helperPath = chromeSandboxPath(resolve(binaryPath));
  if (!helperPath) {
    return { skipped: true };
  }

  const targets = [helperPath, `${helperPath}.unconfigured`].filter((path) => existsSync(path));
  if (targets.length === 0) {
    return { skipped: true };
  }

  const uid = process.getuid?.() ?? 1000;
  const gid = process.getgid?.() ?? 1000;
  const quoted = targets.map((path) => `'${path}'`).join(' ');
  const result = privilegedRun(`chown ${uid}:${gid} ${quoted} && chmod 755 ${quoted}`);
  if (!result.ok) {
    throw new Error(`Could not release root-owned chrome-sandbox for rebuild at ${helperPath}`);
  }
  return { skipped: false, via: result.via, targets };
}

export function configurePackagedLinuxSandbox(binaryPath) {
  if (process.platform !== 'linux') {
    return { skipped: true, reason: 'not-linux', mode: 'n/a' };
  }

  const resolvedBinary = resolve(binaryPath);
  const helperPath = chromeSandboxPath(resolvedBinary);
  if (!helperPath) {
    throw new Error('chrome-sandbox path could not be resolved for a Linux packaged binary');
  }

  restoreHelper(helperPath);
  if (!existsSync(helperPath)) {
    throw new Error(`Packaged chrome-sandbox is missing at ${helperPath}`);
  }

  let suid = { configured: isSuidRoot(helperPath), via: isSuidRoot(helperPath) ? 'already' : null };
  if (!suid.configured) {
    const result = privilegedRun(`chown root:root '${helperPath}' && chmod 4755 '${helperPath}'`);
    if (!result.ok || !isSuidRoot(helperPath)) {
      throw new Error(
        `Could not configure SUID chrome-sandbox at ${helperPath}. sudo -n status=${result.sudoStatus}; docker status=${result.dockerStatus}${result.dockerError ? `; ${result.dockerError}` : ''}`,
      );
    }
    suid = { configured: true, via: result.via };
  }

  const apparmor = installApparmorProfile(resolvedBinary);

  return {
    skipped: false,
    helper: helperPath,
    mode: 'suid',
    suid,
    apparmor,
  };
}
