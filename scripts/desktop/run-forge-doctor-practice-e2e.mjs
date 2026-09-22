#!/usr/bin/env node
/**
 * Forge development GUI E2E for Phase 02 Chunk 12 clinic locations/staff.
 *
 * Requires local Core. When Core is unreachable the script records a skip
 * and exits 0 so GitHub desktop jobs without Postgres still pass.
 */
import { spawn, spawnSync } from 'node:child_process';
import { createHmac, randomUUID } from 'node:crypto';
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
import { pickRendererTarget } from './run-forge-doctor-smoke.mjs';

const require = createRequire(import.meta.url);
const WebSocket = require('ws');

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const doctorApp = join(repoRoot, 'apps', 'doctor-desktop');
const coreApi = join(repoRoot, 'apps', 'core-api');

function sleep(ms) {
  return new Promise((resolveSleep) => {
    setTimeout(resolveSleep, ms);
  });
}

function totpCode(secret, nowMs = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const cleaned = String(secret).replace(/=+$/g, '').toUpperCase();
  let bits = '';
  for (const char of cleaned) {
    const value = alphabet.indexOf(char);
    if (value < 0) {
      continue;
    }
    bits += value.toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let index = 0; index + 8 <= bits.length; index += 8) {
    bytes.push(parseInt(bits.slice(index, index + 8), 2));
  }
  const key = Buffer.from(bytes);
  const counter = Math.floor(nowMs / 1000 / 30);
  const buffer = Buffer.alloc(8);
  buffer.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
  buffer.writeUInt32BE(counter >>> 0, 4);
  const hmac = createHmac('sha1', key).update(buffer).digest();
  const offset = hmac[hmac.length - 1] & 0xf;
  const binary =
    ((hmac[offset] & 0x7f) << 24) |
    (hmac[offset + 1] << 16) |
    (hmac[offset + 2] << 8) |
    hmac[offset + 3];
  return String(binary % 1_000_000).padStart(6, '0');
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
  return new Promise((resolveEval, reject) => {
    const ws = new WebSocket(wsUrl);
    let nextId = 0;
    const pending = new Map();
    const timeout = setTimeout(() => {
      ws.close();
      reject(new Error('CDP evaluate timed out'));
    }, 20_000);

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
          resolveEval(result?.result?.value);
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

function writeEvidence(path, payload) {
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, `${JSON.stringify(payload, null, 2)}\n`);
}

async function waitSnapshot(wsUrl, test, timeoutMs, label) {
  const started = Date.now();
  let last = null;
  while (Date.now() - started < timeoutMs) {
    last = await cdpEvaluate(
      wsUrl,
      `(() => ({
        login: Boolean(document.querySelector('[data-testid="login-form"]')),
        mfa: Boolean(document.querySelector('[data-testid="mfa-code"]')),
        keystore: Boolean(document.querySelector('[data-testid="keystore-unavailable"]')),
        workspace: Boolean(document.querySelector('[data-testid="doctor-workspace"]')),
        practiceNav: Boolean(document.querySelector('[data-testid="practice-locations-nav"]')),
        locations: Boolean(document.querySelector('[data-testid="practice-locations"]')),
        empty: Boolean(document.querySelector('[data-testid="locations-empty"]')),
        create: Boolean(document.querySelector('[data-testid="location-create-form"]')),
        edit: Boolean(document.querySelector('[data-testid="location-edit-form"]')),
        submitEnabled: Boolean(
          document.querySelector('[data-testid="location-submit"]') &&
            !document.querySelector('[data-testid="location-submit"]')?.disabled,
        ),
        details: Boolean(document.querySelector('[data-testid="practice-location-details"]')),
        version: document.querySelector('[data-testid="location-version"]')?.textContent ?? null,
        name: document.querySelector('[data-testid="location-public-name"]')?.textContent ?? null,
        staff: Boolean(document.querySelector('[data-testid="location-staff"]')),
        invite: document.querySelector('[data-testid="invitation-result"]')?.textContent ?? null,
        membershipStatus: document.querySelector('[data-membership-status]')?.getAttribute('data-membership-status') ?? null,
        unavailable: Boolean(document.querySelector('[data-testid="location-unavailable"]')),
        denied: Boolean(document.querySelector('[data-testid="account-denied"]')),
        hash: location.hash,
        pharmacy: typeof window.clinic?.pharmacy,
        invoke: Boolean(window.clinic && 'invoke' in window.clinic),
        scheduleTab: Boolean(document.querySelector('[data-testid="tab-schedule"]')),
      }))()`,
    );
    if (test(last)) {
      return last;
    }
    await sleep(400);
  }
  throw new Error(`${label}: ${JSON.stringify(last)}`);
}

async function evalAction(wsUrl, expression) {
  return cdpEvaluate(wsUrl, expression);
}

function setReactValueExpression(selector, value) {
  return `(() => {
    const el = document.querySelector(${JSON.stringify(selector)});
    if (!el) return false;
    const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    const desc = Object.getOwnPropertyDescriptor(proto, 'value');
    desc.set.call(el, ${JSON.stringify(value)});
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  })()`;
}

async function main() {
  if (process.platform === 'linux' && !process.env.DISPLAY && process.env.CLINIC_FORGE_PRACTICE_NESTED !== '1') {
    const rerun = spawnSync('xvfb-run', ['-a', process.execPath, fileURLToPath(import.meta.url)], {
      stdio: 'inherit',
      env: { ...process.env, CLINIC_FORGE_PRACTICE_NESTED: '1' },
    });
    process.exit(rerun.status ?? 1);
  }

  const evidencePath = join(repoRoot, 'tests', 'desktop-e2e', 'logs', 'doctor-forge-practice-e2e.json');
  const apiBase = process.env.CLINIC_API_BASE_URL?.trim() || 'http://localhost:8080';
  const core = await probeCoreApi(apiBase);
  if (!core.reachable) {
    writeEvidence(evidencePath, {
      kind: 'forge-development-practice-e2e',
      skipped: true,
      reason: 'Core API was not reachable',
      apiBase,
      coreApi: core,
    });
    process.stdout.write(`Forge Doctor practice E2E skipped. Evidence: ${evidencePath}\n`);
    return;
  }

  const workDir = mkdtempSync(join(tmpdir(), 'clinic-doctor-forge-practice-'));
  const fixturesPath = join(workDir, 'fixtures.json');
  const seeded = spawnSync('php', [join(coreApi, 'tests/Support/bin/seed-chunk12-doctor-practice.php')], {
    cwd: coreApi,
    env: { ...process.env, CLINIC_PRACTICE_E2E_FIXTURES: fixturesPath },
    encoding: 'utf8',
  });
  if (seeded.status !== 0) {
    throw new Error(`Fixture seed failed: ${seeded.stderr || seeded.stdout}`);
  }
  const fixtures = JSON.parse(readFileSync(fixturesPath, 'utf8'));

  const debugPort = 9222 + Math.floor(Math.random() * 400);
  const protocolLog = join(workDir, 'protocol.log');
  const stdoutChunks = [];
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
  const proof = {
    kind: 'forge-development-practice-e2e',
    skipped: false,
    apiBase,
    coreApi: core,
    created: false,
    edited: false,
    invited: false,
    accepted: false,
    revoked: false,
    crossOwner: false,
    pendingGated: false,
    pharmacyAbsent: false,
    noScheduleTab: false,
    noGenericInvoke: false,
  };

  try {
    const target = await waitForJson(
      `http://127.0.0.1:${debugPort}/json/list`,
      180_000,
      (body) => pickRendererTarget(body) !== null,
      () =>
        childExited ? `exit ${childExited.code ?? 'null'} signal ${childExited.signal ?? 'null'}` : null,
    );
    const page = pickRendererTarget(target);
    if (!page) {
      throw new Error('Forge CDP had no loopback renderer target');
    }
    const ws = page.webSocketDebuggerUrl;

    await waitSnapshot(ws, (snap) => snap.login || snap.keystore, 45_000, 'login shell');
    await evalAction(ws, setReactValueExpression('input[name="phone"]', fixtures.doctorA.phone));
    await evalAction(ws, setReactValueExpression('input[name="password"]', fixtures.password));
    await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.mfa, 15_000, 'mfa prompt');
    await evalAction(ws, setReactValueExpression('[data-testid="mfa-code"]', totpCode(fixtures.doctorA.totp_secret)));
    await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.workspace && snap.practiceNav, 30_000, 'approved doctor workspace');

    await evalAction(ws, `document.querySelector('[data-testid="open-practice-locations"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.locations, 15_000, 'location list');
    await evalAction(ws, `document.querySelector('[data-testid="add-location"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.create, 10_000, 'create form');
    await evalAction(ws, setReactValueExpression('#location-public-name', 'Cairo Clinic'));
    await evalAction(ws, setReactValueExpression('#location-address', '1 Tahrir Square, Cairo'));
    await evalAction(ws, setReactValueExpression('#location-latitude', '30.0444'));
    await evalAction(ws, setReactValueExpression('#location-longitude', '31.2357'));
    await evalAction(ws, `document.querySelector('[data-testid="confirm-coordinates"] input')?.click() || document.querySelector('#location-create-form input[type="checkbox"]')?.click(); true`);
    await evalAction(ws, `document.querySelector('[data-testid="location-submit"]')?.click(); true`);
    const created = await waitSnapshot(ws, (snap) => snap.details && Boolean(snap.version), 20_000, 'created details');
    proof.created = true;
    const locationId = String(created.hash).match(/[0-9a-f-]{36}/i)?.[0] ?? null;
    if (!locationId) {
      throw new Error('Created location id missing from hash');
    }

    await evalAction(ws, `document.querySelector('[data-testid="tab-location"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.edit, 8_000, 'location edit form');
    await evalAction(ws, setReactValueExpression('#location-public-name', 'Cairo Nile Clinic'));
    await evalAction(ws, setReactValueExpression('#location-address', '1 Tahrir Square, Cairo'));
    await evalAction(ws, setReactValueExpression('#location-latitude', '30.0444'));
    await evalAction(ws, setReactValueExpression('#location-longitude', '31.2357'));
    await evalAction(
      ws,
      `document.querySelector('[data-testid="confirm-coordinates"] input')?.click() || document.querySelector('#location-edit-form input[type="checkbox"]')?.click(); true`,
    );
    await waitSnapshot(ws, (snap) => snap.submitEnabled, 8_000, 'edit submit enabled');
    await evalAction(ws, `document.querySelector('[data-testid="location-submit"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => String(snap.version ?? '').includes('2'), 20_000, 'edited version');
    proof.edited = true;

    await evalAction(ws, `document.querySelector('[data-testid="tab-staff"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.staff, 8_000, 'staff tab');
    await evalAction(ws, setReactValueExpression('#staff-invite-phone', fixtures.secretary.phone));
    await evalAction(ws, `document.querySelector('[data-testid="send-invite"]')?.click(); true`);
    const invited = await waitSnapshot(ws, (snap) => Boolean(snap.invite), 15_000, 'pending invitation');
    proof.invited = true;
    const invitationId = String(invited.invite).match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i)?.[0];
    if (!invitationId) {
      throw new Error('Invitation id missing from safe invitation result');
    }
    const accepted = spawnSync('php', [join(coreApi, 'tests/Support/bin/accept-chunk12-invitation.php')], {
      cwd: coreApi,
      env: {
        ...process.env,
        CLINIC_PRACTICE_SECRETARY_USER_ID: fixtures.secretary.user_id,
        CLINIC_PRACTICE_INVITATION_ID: invitationId,
      },
      encoding: 'utf8',
    });
    if (accepted.status !== 0) {
      throw new Error(`Secretary accept failed: ${accepted.stderr || accepted.stdout}`);
    }
    proof.accepted = true;
    await evalAction(ws, `document.querySelector('[data-testid="refresh-memberships"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.membershipStatus === 'active', 15_000, 'active membership');
    await evalAction(
      ws,
      `document.querySelector('[data-testid^="revoke-"]')?.click(); true`,
    );
    await sleep(400);
    await evalAction(ws, `document.querySelector('[data-testid="confirm-revoke"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.membershipStatus === 'revoked', 15_000, 'revoked membership');
    proof.revoked = true;

    await evalAction(ws, `document.querySelector('[data-testid="sign-out"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.login, 15_000, 'signed out');
    await evalAction(ws, setReactValueExpression('input[name="phone"]', fixtures.doctorB.phone));
    await evalAction(ws, setReactValueExpression('input[name="password"]', fixtures.password));
    await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.mfa, 15_000, 'doctor B mfa');
    await evalAction(ws, setReactValueExpression('[data-testid="mfa-code"]', totpCode(fixtures.doctorB.totp_secret)));
    await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.workspace && snap.practiceNav, 30_000, 'doctor B workspace');
    await evalAction(ws, `location.hash = '/practice/locations/${locationId}'; window.dispatchEvent(new HashChangeEvent('hashchange')); true`);
    const cross = await waitSnapshot(ws, (snap) => snap.unavailable || snap.locations, 15_000, 'cross-owner');
    proof.crossOwner = Boolean(cross.unavailable);

    await evalAction(ws, `document.querySelector('[data-testid="sign-out"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.login, 15_000, 'signed out pending');
    await evalAction(ws, setReactValueExpression('input[name="phone"]', fixtures.pendingDoctor.phone));
    await evalAction(ws, setReactValueExpression('input[name="password"]', fixtures.password));
    await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.mfa, 15_000, 'pending doctor mfa');
    await evalAction(ws, setReactValueExpression('[data-testid="mfa-code"]', totpCode(fixtures.pendingDoctor.totp_secret)));
    await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
    const pending = await waitSnapshot(ws, (snap) => snap.workspace, 30_000, 'pending doctor workspace');
    proof.pendingGated = pending.practiceNav === false;
    proof.pharmacyAbsent = pending.pharmacy === 'undefined';
    proof.noScheduleTab = pending.scheduleTab === false;
    proof.noGenericInvoke = pending.invoke === false;

    const logs = Buffer.concat(stdoutChunks).toString('utf8');
    if (/CANARY-SECRETARY-PHONE|CANARY-ADDRESS/.test(logs)) {
      throw new Error('Forge practice logs contained a protected canary');
    }

    writeEvidence(evidencePath, proof);
    process.stdout.write(`Forge Doctor practice E2E passed. Evidence: ${evidencePath}\n`);
  } catch (error) {
    failed = error;
    writeEvidence(evidencePath, { ...proof, error: error instanceof Error ? error.message : String(error) });
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
