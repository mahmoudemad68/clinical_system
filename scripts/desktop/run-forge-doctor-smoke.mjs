#!/usr/bin/env node
/**
 * Unpackaged Forge development smoke for Clinic Doctor.
 *
 * Proves the development renderer mounts under script-src 'self' without
 * 'unsafe-eval'. This is not packaged E2E and must not be cited as G-02-10.
 */
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const WebSocket = require('ws');

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const doctorApp = join(repoRoot, 'apps', 'doctor-desktop');

export const CSP_EVAL_VIOLATION =
  /unsafe-eval|EvalError|Refused to evaluate a string as JavaScript|Calling eval\(\) on a script/i;

const CANARY =
  /Bearer\s+[A-Za-z0-9._-]+|refresh_token|X-Amz-Signature|national[_\s-]?id|syndicate[_\s-]?number/i;

export function isCspEvalViolation(message) {
  return CSP_EVAL_VIOLATION.test(String(message));
}

export function pickRendererTarget(targets) {
  if (!Array.isArray(targets)) {
    return null;
  }
  return (
    targets.find(
      (target) =>
        target &&
        target.type === 'page' &&
        typeof target.url === 'string' &&
        (target.url.includes('localhost') || target.url.includes('127.0.0.1')) &&
        typeof target.webSocketDebuggerUrl === 'string',
    ) ?? null
  );
}

export function assertSmokeSnapshot(snapshot) {
  if (!snapshot || typeof snapshot !== 'object') {
    throw new Error('Forge smoke produced no renderer snapshot');
  }
  if (!String(snapshot.href ?? '').match(/^https?:\/\/(localhost|127\.0\.0\.1)[:/]/)) {
    throw new Error(`Forge renderer is not on a loopback development origin: ${snapshot.href}`);
  }
  if (snapshot.title !== 'Clinic Doctor' && snapshot.product !== 'Clinic Doctor') {
    throw new Error(`Forge renderer missing product title: ${JSON.stringify(snapshot)}`);
  }
  if ((snapshot.rootLength ?? -1) <= 0) {
    throw new Error(`Forge renderer did not mount React: ${JSON.stringify(snapshot)}`);
  }
  if (!snapshot.login && !snapshot.keystore) {
    throw new Error('Forge renderer showed neither login nor keystore-unavailable');
  }
  if (snapshot.clinicType !== 'object') {
    throw new Error('window.clinic missing');
  }
  if (snapshot.doctorType !== 'object') {
    throw new Error('window.clinic.doctor missing');
  }
  if (snapshot.pharmacyType !== 'undefined') {
    throw new Error('window.clinic.pharmacy must be absent on Doctor');
  }
  if (snapshot.requireType !== 'undefined' || snapshot.processType !== 'undefined') {
    throw new Error('Node globals present in Forge renderer');
  }
  if (snapshot.hasInvoke) {
    throw new Error('generic invoke exposed on window.clinic');
  }
  const doctorKeys = Array.isArray(snapshot.doctorKeys) ? snapshot.doctorKeys : [];
  if (!doctorKeys.includes('listLocations') || !doctorKeys.includes('inviteStaff')) {
    throw new Error('Doctor clinic location operations missing from Forge bridge');
  }
  if (doctorKeys.includes('schedule') || doctorKeys.includes('invoke')) {
    throw new Error('Doctor bridge exposed a forbidden operation');
  }
}

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

async function waitForJson(url, timeoutMs, accept, shouldAbort) {
  const started = Date.now();
  let lastError = 'not attempted';
  while (Date.now() - started < timeoutMs) {
    if (shouldAbort) {
      const reason = shouldAbort();
      if (reason) {
        throw new Error(`Forge process ended before CDP was ready: ${reason}`);
      }
    }
    try {
      const response = await fetch(url);
      if (response.ok) {
        const body = await response.json();
        if (!accept || accept(body)) {
          return body;
        }
        lastError = 'payload rejected';
      } else {
        lastError = `HTTP ${response.status}`;
      }
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
    }
    await sleep(400);
  }
  throw new Error(`Timed out waiting for ${url}: ${lastError}`);
}

function cdpEvaluate(wsUrl, expression) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl);
    let nextId = 0;
    const pending = new Map();
    const timeout = setTimeout(() => {
      ws.close();
      reject(new Error('CDP evaluate timed out'));
    }, 15_000);

    const send = (method, params) => {
      const id = ++nextId;
      return new Promise((res, rej) => {
        pending.set(id, { res, rej });
        ws.send(JSON.stringify({ id, method, params }));
      });
    };

    ws.on('open', () => {
      send('Runtime.enable')
        .then(() =>
          send('Runtime.evaluate', {
            expression,
            returnByValue: true,
            awaitPromise: true,
          }),
        )
        .then((result) => {
          clearTimeout(timeout);
          ws.close();
          if (result?.exceptionDetails) {
            reject(new Error(JSON.stringify(result.exceptionDetails)));
            return;
          }
          resolve(result?.result?.value);
        })
        .catch((error) => {
          clearTimeout(timeout);
          ws.close();
          reject(error);
        });
    });

    ws.on('message', (raw) => {
      const message = JSON.parse(String(raw));
      if (message.id && pending.has(message.id)) {
        const waiter = pending.get(message.id);
        pending.delete(message.id);
        waiter.res(message.result);
      }
    });

    ws.on('error', (error) => {
      clearTimeout(timeout);
      reject(error);
    });
  });
}

const SNAPSHOT_EXPRESSION = `(() => ({
  href: location.href,
  origin: location.origin,
  title: document.title,
  product: document.querySelector('[data-testid="product-title"]')?.textContent ?? null,
  login: Boolean(document.querySelector('[data-testid="login-form"]')),
  keystore: Boolean(document.querySelector('[data-testid="keystore-unavailable"]')),
  healthStatus: document.querySelector('[data-testid="overall-status"]')?.textContent ?? null,
  healthMessage: document.querySelector('[data-testid="health-message"]')?.textContent ?? null,
  healthBusy: Boolean(document.querySelector('[aria-busy="true"]')),
  clinicType: typeof window.clinic,
  doctorType: typeof window.clinic?.doctor,
  doctorKeys: window.clinic?.doctor ? Object.keys(window.clinic.doctor) : [],
  pharmacyType: typeof window.clinic?.pharmacy,
  requireType: typeof window.require,
  processType: typeof window.process,
  electronType: typeof window.electron,
  hasInvoke: Boolean(window.clinic && 'invoke' in window.clinic),
  rootLength: document.getElementById('root')?.innerHTML.length ?? -1,
}))()`;

function stopChild(child) {
  if (!child.pid) {
    return;
  }
  try {
    process.kill(-child.pid, 'SIGTERM');
  } catch {
    try {
      child.kill('SIGTERM');
    } catch {
      // already exited
    }
  }
}

async function probeCoreApi(baseUrl) {
  try {
    const response = await fetch(`${baseUrl}/api/v1/health`, {
      signal: AbortSignal.timeout(2000),
    });
    return { reachable: response.ok, status: response.status };
  } catch {
    return { reachable: false, status: null };
  }
}

function electronSandboxPath() {
  for (const dir of [join(repoRoot, 'node_modules', 'electron'), join(doctorApp, 'node_modules', 'electron')]) {
    const helper = join(dir, 'dist', 'chrome-sandbox');
    if (existsSync(helper)) {
      return helper;
    }
  }
  return null;
}

function ensureElectronRuntime() {
  if (electronSandboxPath()) {
    return;
  }
  const installer = join(repoRoot, 'node_modules', 'electron', 'install.js');
  if (!existsSync(installer)) {
    throw new Error('Electron package is not installed at the workspace root');
  }
  const installed = spawnSync(process.execPath, [installer], { cwd: repoRoot, stdio: 'inherit' });
  if (installed.status !== 0) {
    throw new Error(`Electron download failed with status ${installed.status ?? 'null'}`);
  }
}

async function main() {
  if (process.platform === 'linux' && !process.env.DISPLAY && process.env.CLINIC_FORGE_SMOKE_NESTED !== '1') {
    const rerun = spawnSync('xvfb-run', ['-a', process.execPath, fileURLToPath(import.meta.url)], {
      stdio: 'inherit',
      env: { ...process.env, CLINIC_FORGE_SMOKE_NESTED: '1' },
    });
    process.exit(rerun.status ?? 1);
  }

  const debugPort = 9222 + Math.floor(Math.random() * 400);
  const workDir = mkdtempSync(join(tmpdir(), 'clinic-doctor-forge-smoke-'));
  const protocolLog = join(workDir, 'protocol.log');
  const stdoutLog = join(workDir, 'forge.stdout.log');
  const evidencePath = join(repoRoot, 'tests', 'desktop-e2e', 'logs', 'doctor-forge-dev-smoke.json');
  const apiBase = process.env.CLINIC_API_BASE_URL?.trim() || 'http://localhost:8080';
  const stdoutChunks = [];

  ensureElectronRuntime();
  const sandbox = spawnSync(process.execPath, [join(here, 'ensure-linux-chromium-sandbox.mjs')], {
    cwd: doctorApp,
    stdio: 'inherit',
  });
  if (sandbox.status !== 0) {
    throw new Error(`Linux Chromium sandbox helper failed with status ${sandbox.status ?? 'null'}`);
  }

  const child = spawn(
    'npx',
    [
      'electron-forge',
      'start',
      '--',
      `--remote-debugging-port=${debugPort}`,
      `--remote-allow-origins=http://127.0.0.1:${debugPort}`,
    ],
    {
      cwd: doctorApp,
      env: {
        ...process.env,
        CLINIC_DESKTOP_USER_DATA: join(workDir, 'user-data'),
        CLINIC_DESKTOP_PROTOCOL_LOG: protocolLog,
        CLINIC_API_BASE_URL: apiBase,
        ELECTRON_ENABLE_LOGGING: '1',
      },
      stdio: ['ignore', 'pipe', 'pipe'],
      detached: true,
    },
  );

  child.stdout.on('data', (chunk) => {
    stdoutChunks.push(chunk);
    process.stdout.write(chunk);
  });
  child.stderr.on('data', (chunk) => {
    stdoutChunks.push(chunk);
    process.stderr.write(chunk);
  });
  let childExited = null;
  child.on('exit', (code, signal) => {
    childExited = { code, signal };
  });

  let failed = null;
  try {
    const target = await waitForJson(
      `http://127.0.0.1:${debugPort}/json/list`,
      180_000,
      (body) => pickRendererTarget(body) !== null,
      () =>
        childExited
          ? `exit ${childExited.code ?? 'null'} signal ${childExited.signal ?? 'null'}`
          : null,
    );
    const page = pickRendererTarget(target);
    if (!page) {
      throw new Error('Forge CDP had no loopback renderer target');
    }

    let snapshot = null;
    const started = Date.now();
    let lastError = 'no snapshot';
    while (Date.now() - started < 45_000) {
      try {
        snapshot = await cdpEvaluate(page.webSocketDebuggerUrl, SNAPSHOT_EXPRESSION);
        if (
          snapshot &&
          (snapshot.login || snapshot.keystore) &&
          snapshot.product &&
          snapshot.healthBusy === false
        ) {
          break;
        }
        lastError = `incomplete snapshot ${JSON.stringify(snapshot)}`;
      } catch (error) {
        lastError = error instanceof Error ? error.message : String(error);
      }
      await sleep(500);
    }

    assertSmokeSnapshot(snapshot);

    const protocolText = existsSync(protocolLog) ? readFileSync(protocolLog, 'utf8') : '';
    const combinedLogs = `${protocolText}\n${Buffer.concat(stdoutChunks).toString('utf8')}`;
    const evalHits = combinedLogs
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter((line) => line.length > 0 && isCspEvalViolation(line));

    if (evalHits.length > 0) {
      throw new Error(`CSP eval violation in Forge logs: ${evalHits[0]}`);
    }
    if (CANARY.test(combinedLogs)) {
      throw new Error('Forge smoke logs contained a prohibited canary');
    }

    const core = await probeCoreApi(apiBase);
    const evidence = {
      kind: 'forge-development-smoke',
      product: 'Clinic Doctor',
      packaged: false,
      href: snapshot.href,
      origin: snapshot.origin,
      login: snapshot.login,
      keystore: snapshot.keystore,
      clinic: snapshot.clinicType,
      doctor: snapshot.doctorType,
      pharmacy: snapshot.pharmacyType,
      nodeGlobals: {
        require: snapshot.requireType,
        process: snapshot.processType,
        electron: snapshot.electronType,
      },
      genericInvoke: snapshot.hasInvoke,
      cspEvalViolations: evalHits,
      apiBase,
      coreApi: core,
      healthStatus: snapshot.healthStatus,
      healthMessage: snapshot.healthMessage,
      mainTransportReachedCore: Boolean(snapshot.healthStatus),
    };

    mkdirSync(dirname(evidencePath), { recursive: true });
    writeFileSync(evidencePath, `${JSON.stringify(evidence, null, 2)}\n`);
    writeFileSync(stdoutLog, Buffer.concat(stdoutChunks));
    process.stdout.write(`Forge Doctor smoke passed. Evidence: ${evidencePath}\n`);
  } catch (error) {
    failed = error;
  } finally {
    stopChild(child);
    await sleep(1000);
    try {
      rmSync(workDir, { recursive: true, force: true });
    } catch {
      // best-effort
    }
  }

  if (failed) {
    throw failed;
  }
}

const invokedDirectly =
  Boolean(process.argv[1]) && fileURLToPath(import.meta.url) === resolve(process.argv[1]);
if (invokedDirectly) {
  main().catch((error) => {
    process.stderr.write(`${error instanceof Error ? error.stack ?? error.message : String(error)}\n`);
    process.exit(1);
  });
}
