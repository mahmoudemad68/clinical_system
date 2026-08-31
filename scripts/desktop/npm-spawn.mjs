/**
 * Cross-platform `npm` spawn for the packaged Electron E2E harness.
 *
 * On POSIX, `npm` is an executable. On Windows it is `npm.cmd`, and Node
 * (CVE-2024-27980) will not run `.cmd`/`.bat` without a shell. `spawnSync('npm')`
 * therefore returns `error.code=ENOENT` and `status=null` with no Forge output.
 */
import { spawnSync } from 'node:child_process';

export function quoteCmdArgument(arg) {
  const value = String(arg);
  if (value.length === 0) {
    return '""';
  }
  if (!/[\s"]/u.test(value)) {
    return value;
  }
  return `"${value.replaceAll('"', '""')}"`;
}

export function npmSpawnInvocation(npmArgs, {
  platform = process.platform,
  comSpec = process.env.ComSpec,
} = {}) {
  if (platform === 'win32') {
    const commandLine = ['npm', ...npmArgs].map(quoteCmdArgument).join(' ');
    return {
      command: comSpec && String(comSpec).trim() !== '' ? comSpec : 'cmd.exe',
      args: ['/d', '/s', '/c', `"${commandLine}"`],
      options: { windowsVerbatimArguments: true },
    };
  }

  return {
    command: 'npm',
    args: [...npmArgs],
    options: {},
  };
}

export function describeNpmSpawnResult(invocation, result) {
  const displayed = `${invocation.command} ${invocation.args.join(' ')}`;
  if (result.error) {
    const code = result.error.code ? ` (${result.error.code})` : '';
    return {
      ok: false,
      kind: 'spawn-error',
      message: `Failed to spawn ${displayed}${code}: ${result.error.message}`,
    };
  }
  if (result.signal) {
    return {
      ok: false,
      kind: 'signal',
      message: `${displayed} terminated by signal ${result.signal}`,
    };
  }
  if (result.status !== 0) {
    return {
      ok: false,
      kind: 'exit-code',
      message: `${displayed} exited with status ${result.status}`,
    };
  }
  return { ok: true, kind: 'ok', message: null };
}

export function runNpm(npmArgs, { throwOnFailure = true, ...spawnOptions } = {}) {
  const invocation = npmSpawnInvocation(npmArgs);
  const result = spawnSync(invocation.command, invocation.args, {
    env: process.env,
    ...invocation.options,
    ...spawnOptions,
  });
  const described = describeNpmSpawnResult(invocation, result);
  if (!described.ok && throwOnFailure) {
    throw new Error(described.message);
  }
  return { result, invocation, described };
}
