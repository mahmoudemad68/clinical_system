#!/usr/bin/env node
/**
 * G-02-10 packaged Electron E2E runner.
 *
 * Packages Doctor and Pharmacy, inspects fuses on those binaries, launches
 * the packaged executables with WebdriverIO + @wdio/electron-service, and
 * writes SHA-bound evidence. Never falls back to Vitest, `electron` from
 * node_modules, or a Vite/Forge dev server.
 */
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { APPS, findPackagedBinary, listPackagedArtifacts, stageLinuxPackagedAppForLaunch } from './packaged-app-paths.mjs';
import { configurePackagedLinuxSandbox, releasePackagedLinuxSandboxForRebuild } from './configure-packaged-linux-sandbox.mjs';
import { inspectPackagedFuses } from './inspect-packaged-fuses.mjs';
import { runNpm } from './npm-spawn.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const evidenceDir = join(repoRoot, 'docs', 'evidence', 'phase-00');
const e2eDir = join(repoRoot, 'tests', 'desktop-e2e');
const skipPackage = process.env.CLINIC_DESKTOP_SKIP_PACKAGE === '1';
const skipMake = process.env.CLINIC_DESKTOP_SKIP_MAKE === '1';

function runNpmInRepo(npmArgs, options = {}) {
  const { result } = runNpm(npmArgs, {
    cwd: repoRoot,
    stdio: 'inherit',
    env: process.env,
    ...options,
  });
  return result;
}

function sha256File(path) {
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

function gitSha() {
  const result = spawnSync('git', ['-C', repoRoot, 'rev-parse', 'HEAD'], { encoding: 'utf8' });
  if (result.status !== 0) {
    throw new Error('Unable to read git HEAD');
  }
  return result.stdout.trim();
}

function parseWdioCounts(logText) {
  const passingMatches = [...logText.matchAll(/(\d+)\s+passing\b/g)];
  const failingMatches = [...logText.matchAll(/(\d+)\s+failing\b/g)];
  const passing = passingMatches.length > 0 ? Number(passingMatches[passingMatches.length - 1][1]) : 0;
  const failing = failingMatches.length > 0 ? Number(failingMatches[failingMatches.length - 1][1]) : 0;
  if (passingMatches.length > 0 || failingMatches.length > 0) {
    return { passing, failing };
  }
  const specFiles = logText.match(/Spec Files:\s+(\d+)\s+passed,\s+(\d+)\s+failed/i);
  if (specFiles) {
    return { passing: Number(specFiles[1]), failing: Number(specFiles[2]) };
  }
  return { passing: 0, failing: /FAILED|Error:/.test(logText) ? 1 : 0 };
}

function packageApp(appKey) {
  const existing = findPackagedBinary(repoRoot, appKey);
  if (existing && process.platform === 'linux' && !skipPackage) {
    releasePackagedLinuxSandboxForRebuild(existing);
  }
  if (skipPackage) {
    if (!existing) {
      throw new Error(`CLINIC_DESKTOP_SKIP_PACKAGE=1 but no packaged binary for ${appKey}`);
    }
    console.log(`Skipping package for ${appKey}; using ${existing}`);
    return { skipped: true };
  }
  runNpmInRepo(['run', 'package', `--workspace=${APPS[appKey].workspace}`]);
  return { skipped: false };
}

function makeApp(appKey) {
  if (skipMake) {
    return { skipped: true, reason: 'CLINIC_DESKTOP_SKIP_MAKE' };
  }
  const { result, described } = runNpm(
    ['run', 'make', `--workspace=${APPS[appKey].workspace}`],
    {
      cwd: repoRoot,
      encoding: 'utf8',
      env: process.env,
      throwOnFailure: false,
    },
  );
  if (!described.ok) {
    console.warn(`==> make ${appKey}: ${described.message}`);
  }
  return {
    skipped: false,
    status: result.status,
    ok: described.ok,
    spawnKind: described.kind,
    spawnError: described.ok ? null : described.message,
  };
}

function ensureE2eDeps() {
  if (!existsSync(join(e2eDir, 'node_modules', '@wdio', 'cli'))) {
    runNpmInRepo(['ci', '--prefix', e2eDir]);
  }
}

function runWdio(appKey, binary, userDataDir) {
  const app = APPS[appKey];
  const logPath = join(e2eDir, 'logs', `${appKey}-${process.platform}.log`);
  mkdirSync(dirname(logPath), { recursive: true });

  const env = {
    ...process.env,
    CLINIC_DESKTOP_APP: appKey,
    CLINIC_DESKTOP_BINARY: binary,
    CLINIC_DESKTOP_ORIGIN: app.packagedOrigin,
    CLINIC_DESKTOP_PRODUCT: app.productName,
    CLINIC_DESKTOP_USER_DATA: userDataDir,
    CLINIC_DESKTOP_PROTOCOL_LOG: join(e2eDir, 'logs', `${appKey}-protocol.log`),
  };

  const result = spawnSync(
    process.execPath,
    [join(e2eDir, 'node_modules', '@wdio', 'cli', 'bin', 'wdio.js'), 'run', join(e2eDir, 'wdio.conf.mjs')],
    { cwd: e2eDir, encoding: 'utf8', env },
  );

  const output = `${result.stdout ?? ''}\n${result.stderr ?? ''}${
    result.error
      ? `\nFailed to spawn WebdriverIO (${result.error.code ?? 'spawn-error'}): ${result.error.message}\n`
      : ''
  }`;
  writeFileSync(logPath, output);
  process.stdout.write(output);
  const counts = parseWdioCounts(output);
  return {
    exitCode: result.error ? 1 : (result.status ?? 1),
    passing: counts.passing,
    failing: counts.failing,
    logRelative: logPath.slice(repoRoot.length + 1),
    spawnError: result.error ? `${result.error.code ?? 'spawn-error'}: ${result.error.message}` : null,
  };
}

function writeEvidence(payload) {
  mkdirSync(evidenceDir, { recursive: true });
  const jsonPath = join(evidenceDir, 'g-02-10-packaged-electron-e2e.json');
  const mdPath = join(evidenceDir, 'g-02-10-packaged-electron-e2e.md');
  writeFileSync(jsonPath, `${JSON.stringify(payload, null, 2)}\n`);

  const appRows = payload.apps
    .map((app) => {
      const fuse = app.fuses.passed ? 'PASS' : 'FAIL';
      const wdio = app.wdio.failing === 0 && app.wdio.exitCode === 0 ? 'PASS' : 'FAIL';
      const asar = app.asars[0]?.sha256 ? `asar ${app.asars[0].sha256.slice(0, 12)}…` : 'no asar';
      return `| ${app.productName} | \`${app.binaryRelative}\` (${asar}) | ${fuse} | ${wdio} (${app.wdio.passing} passing / ${app.wdio.failing} failing) |`;
    })
    .join('\n');

  const makers = payload.apps
    .flatMap((app) => app.makers)
    .map((file) => `- \`${file}\``)
    .join('\n');

  writeFileSync(
    mdPath,
    `# G-02-10 — Packaged Electron E2E

- **Gate:** G-02-10
- **Result:** ${payload.result}
- **Candidate SHA:** \`${payload.candidate_sha}\`
- **Recorded:** ${payload.recorded_at}
- **Host OS executed:** ${payload.os_executed.join(', ') || 'none'}
- **Host not executed:** ${payload.os_not_executed.join(', ') || 'none'}
- **Command:** \`node scripts/desktop/run-packaged-e2e.mjs\`

This is packaged-runtime evidence. It is not Vitest, not \`electron-forge start\`,
not a Vite/Webpack dev server, and not ASAR string inspection alone.

Phase 00 remains **OPEN**. This file does not close the phase.

## OS matrix

| OS | Executed | Result |
| --- | --- | --- |
| Linux | ${payload.os_matrix.linux.executed ? 'yes' : 'no'} | ${payload.os_matrix.linux.result} |
| Windows | ${payload.os_matrix.windows.executed ? 'yes' : 'no'} | ${payload.os_matrix.windows.result} |
| macOS | ${payload.os_matrix.macos.executed ? 'yes' : 'no'} | ${payload.os_matrix.macos.result} |

A cell is PASS only when the packaged binary actually ran on that OS.

## Applications

| App | Packaged binary | Fuses | WebdriverIO |
| --- | --- | --- | --- |
${appRows}

## Installer / maker artifacts

${makers || '_None produced on this host._'}

## What was asserted at runtime

- Renderer document origin is the privileged custom scheme, not \`file://\`.
- \`window.require\` / \`window.process\` are absent (no Node in the renderer).
- \`window.clinic\` exists; login form is shown; session panel is not (unsigned-in).
- \`localStorage\`, \`sessionStorage\`, and cookies do not hold tokens.
- Hostile navigation to \`https://example.com\` stays on the packaged origin.
- \`window.open\` is denied.
- Locale switch English → Arabic sets \`dir=rtl\`.
- Binary fuse wire matches Forge intent, including \`GrantFileProtocolExtraPrivileges=DISABLE\`.

## Residual

Windows and macOS are not claimed unless a packaged binary ran on those
runners. Signing and notarization remain Phase 23.
`,
  );

  return { jsonPath, mdPath };
}

const candidateSha = gitSha();
const now = new Date().toISOString();
const platform = process.platform;
const osExecuted = platform === 'linux' ? 'linux' : platform === 'darwin' ? 'macos' : platform === 'win32' ? 'windows' : platform;

ensureE2eDeps();

const appResults = [];
let failed = false;

for (const appKey of ['doctor', 'pharmacy']) {
  console.log(`==> Packaging ${appKey}`);
  packageApp(appKey);
  const makeResult = makeApp(appKey);
  const artifacts = listPackagedArtifacts(repoRoot, appKey);
  if (!artifacts.binary) {
    throw new Error(`No packaged binary found for ${appKey}. Forge package did not produce an executable.`);
  }

  const staged = stageLinuxPackagedAppForLaunch(artifacts.binary, appKey);
  const launchBinary = staged.binary;
  if (staged.staged) {
    console.log(`==> Staged ${appKey} for launch at ${launchBinary} (Forge output path contains a space)`);
  }

  let sandbox = { skipped: true, mode: 'n/a' };
  if (process.platform === 'linux') {
    console.log(`==> Configuring packaged chrome-sandbox for ${appKey}`);
    sandbox = {
      ...configurePackagedLinuxSandbox(launchBinary),
      staged: staged.staged,
      stagedDir: staged.stagedDir ?? null,
    };
  }

  console.log(`==> Inspecting fuses for ${appKey}`);
  const fuses = await inspectPackagedFuses(artifacts.binary);
  if (!fuses.passed) {
    failed = true;
  }

  const userDataDir = mkdtempSync(join(tmpdir(), `clinic-${appKey}-e2e-`));
  console.log(`==> WebdriverIO packaged runtime for ${appKey}`);
  let wdio;
  try {
    wdio = runWdio(appKey, launchBinary, userDataDir);
  } finally {
    rmSync(userDataDir, { recursive: true, force: true });
  }
  if (wdio.exitCode !== 0 || wdio.failing > 0) {
    failed = true;
  }

  appResults.push({
    app: appKey,
    productName: APPS[appKey].productName,
    packagedOrigin: APPS[appKey].packagedOrigin,
    binaryRelative: artifacts.binaryRelative,
    binarySha256: sha256File(artifacts.binary),
    asars: artifacts.asars.map((relative) => ({
      path: relative,
      sha256: sha256File(join(repoRoot, relative)),
    })),
    makers: artifacts.makers,
    make: makeResult,
    sandbox,
    fuses: {
      passed: fuses.passed,
      observed: fuses.observed,
      failures: fuses.failures,
    },
    wdio,
  });
}

const linuxRan = osExecuted === 'linux';
const windowsRan = osExecuted === 'windows';
const macosRan = osExecuted === 'macos';
const hostResult = failed ? 'FAIL' : 'PASS';
const result = failed
  ? 'FAIL'
  : linuxRan && !windowsRan && !macosRan
    ? 'PARTIAL'
    : hostResult;

const evidence = {
  gate: 'G-02-10',
  result,
  candidate_sha: candidateSha,
  recorded_at: now,
  command: 'node scripts/desktop/run-packaged-e2e.mjs',
  host: {
    platform: process.platform,
    arch: process.arch,
  },
  os_executed: [osExecuted],
  os_not_executed: ['linux', 'windows', 'macos'].filter((name) => name !== osExecuted),
  os_matrix: {
    linux: { executed: linuxRan, result: linuxRan ? hostResult : 'NOT_RUN' },
    windows: { executed: windowsRan, result: windowsRan ? hostResult : 'NOT_RUN' },
    macos: { executed: macosRan, result: macosRan ? hostResult : 'NOT_RUN' },
  },
  harness: {
    runner: '@wdio/electron-service',
    mode: 'packaged-binary',
    forbidden_fallbacks: ['vitest', 'electron-forge start', 'vite', 'file://', 'asar-inspect-only'],
  },
  apps: appResults,
};

const paths = writeEvidence(evidence);
console.log(`==> Evidence written to ${paths.mdPath}`);
if (failed) {
  process.exit(1);
}
