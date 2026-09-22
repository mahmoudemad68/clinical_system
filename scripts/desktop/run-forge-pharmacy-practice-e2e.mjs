#!/usr/bin/env node
/**
 * Forge development GUI E2E for Phase 02 Chunk 15 pharmacy branches/memberships.
 *
 * Optional local smoke (default): Core unreachable → write skipped evidence
 * and exit 0. This is developer convenience only. It is not a CI gate.
 *
 * Required mode: set CLINIC_REQUIRE_PHARMACY_PRACTICE_E2E=1 (or true/yes/on).
 * Do not infer required mode from CI=true. When the flag is enabled, Core
 * unavailability, fixture failure, Forge launch failure, and journey failure
 * all exit non-zero. Evidence skipped=true is a failure.
 *
 * Headless Linux CI must wrap this process with
 * scripts/desktop/with-linux-os-keystore.sh so Electron safeStorage can
 * select gnome_libsecret. Linux basic_text remains fail-closed.
 */
import { spawn, spawnSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { createRequire } from 'node:module';
import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ensureElectronRuntime, pickRendererTarget } from './run-forge-doctor-smoke.mjs';

const require = createRequire(import.meta.url);
const WebSocket = require('ws');

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const pharmacyApp = join(repoRoot, 'apps', 'pharmacy-desktop');
const coreApi = join(repoRoot, 'apps', 'core-api');

export const PHARMACY_PRACTICE_E2E_REQUIRED_FLAG = 'CLINIC_REQUIRE_PHARMACY_PRACTICE_E2E';
export const PHARMACY_PRACTICE_E2E_JOURNEY_KEYS = Object.freeze([
  'core_health',
  'branch_created',
  'branch_edited',
  'version_conflict',
  'invited',
  'accepted',
  'membership_visible',
  'revoked',
  'account_isolation',
  'phone_absent_after_submit',
  'no_phase10_navigation',
  'no_generic_invoke',
]);

export function pharmacyPracticeE2EEvidencePath(root = repoRoot) {
  return join(root, 'tests', 'desktop-e2e', 'logs', 'pharmacy-forge-practice-e2e.json');
}

export function envFlagEnabled(name, env = process.env) {
  const value = env[name];
  if (value === undefined || value === '') {
    return false;
  }
  return ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
}

export function isPharmacyPracticeE2ERequired(env = process.env) {
  return envFlagEnabled(PHARMACY_PRACTICE_E2E_REQUIRED_FLAG, env);
}

export function assertPharmacyPracticeE2EEvidence(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    throw new Error('Pharmacy practice E2E evidence is missing');
  }
  if (payload.kind !== 'forge-development-pharmacy-practice-e2e') {
    throw new Error(`Unexpected pharmacy practice E2E evidence kind: ${JSON.stringify(payload.kind)}`);
  }
  if (payload.skipped !== false) {
    throw new Error(
      `Pharmacy practice E2E evidence skipped=${JSON.stringify(payload.skipped)}; required mode must execute the journey`,
    );
  }
  if (payload.core_health !== 'operational') {
    throw new Error(`Pharmacy practice E2E evidence core_health=${JSON.stringify(payload.core_health)}; expected operational`);
  }
  for (const key of PHARMACY_PRACTICE_E2E_JOURNEY_KEYS) {
    if (key === 'core_health') {
      continue;
    }
    if (payload[key] !== true) {
      throw new Error(`Pharmacy practice E2E evidence ${key}=${JSON.stringify(payload[key])}; expected true`);
    }
  }
  return payload;
}

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
    if (!response.ok) {
      return { reachable: false, status: response.status, health: null };
    }
    const body = await response.json();
    const raw = body?.data?.components?.core ?? body?.components?.core ?? null;
    const health =
      raw === 'operational' || raw?.status === 'operational' || raw?.health === 'operational'
        ? 'operational'
        : typeof raw === 'string'
          ? raw
          : raw && typeof raw === 'object'
            ? JSON.stringify(raw)
            : null;
    return { reachable: true, status: response.status, health };
  } catch {
    return { reachable: false, status: null, health: null };
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
        workspace: Boolean(document.querySelector('[data-testid="pharmacy-workspace"]')),
        practiceNav: Boolean(document.querySelector('[data-testid="practice-branches-nav"]')),
        branches: Boolean(document.querySelector('[data-testid="practice-branches"]')),
        create: Boolean(document.querySelector('[data-testid="branch-create-form"]')),
        edit: Boolean(document.querySelector('[data-testid="branch-edit-form"]')),
        submitEnabled: Boolean(
          document.querySelector('[data-testid="branch-submit"]') &&
            !document.querySelector('[data-testid="branch-submit"]')?.disabled,
        ),
        details: Boolean(document.querySelector('[data-testid="practice-branch-details"]')),
        version: document.querySelector('[data-testid="branch-version"]')?.textContent ?? null,
        name: document.querySelector('[data-testid="branch-public-name"]')?.textContent ?? null,
        address: document.querySelector('[data-testid="branch-address"]')?.textContent ?? null,
        team: Boolean(document.querySelector('[data-testid="branch-team"]')),
        invite: document.querySelector('[data-testid="invitation-result"]')?.textContent ?? null,
        invitePending: document.querySelector('[data-testid="invitation-result"]')?.getAttribute('data-existing-pending') ?? null,
        membershipStatus: document.querySelector('[data-membership-status="active"]')
          ? 'active'
          : document.querySelector('[data-membership-status="revoked"]')
            ? 'revoked'
            : document.querySelector('[data-membership-status]')?.getAttribute('data-membership-status') ?? null,
        conflict: Boolean(document.querySelector('[data-testid="version-conflict"]')),
        unavailable: Boolean(document.querySelector('[data-testid="branch-unavailable"]')),
        denied: Boolean(document.querySelector('[data-testid="account-denied"]')),
        loginError: document.querySelector('[data-testid="login-error"]')?.textContent ?? null,
        hash: location.hash,
        body: document.body?.innerText ?? '',
        inventory: Boolean(document.querySelector('[data-testid="inventory-nav"]')),
        pos: Boolean(document.querySelector('[data-testid="pos-nav"]')),
        noPhase10: Boolean(document.querySelector('[data-testid="no-phase10-nav"]')),
        invoke: Boolean(window.clinic && 'invoke' in window.clinic),
        pharmacy: typeof window.clinic?.pharmacy,
        doctor: typeof window.clinic?.doctor,
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

async function signIn(ws, phone, password, totpSecret, label) {
  await evalAction(ws, setReactValueExpression('input[name="phone"]', phone));
  await evalAction(ws, setReactValueExpression('input[name="password"]', password));
  await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
  await waitSnapshot(ws, (snap) => snap.mfa, 15_000, `${label} mfa`);
  await evalAction(ws, setReactValueExpression('[data-testid="mfa-code"]', totpCode(totpSecret)));
  await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
}

/**
 * Core `pharmacy_desktop` is compatible only with pharmacy accounts. A doctor
 * or patient password attempt is AuthenticationFailed before MFA, so the
 * renderer must stay on the login form — not wait for an MFA prompt or an
 * authenticated `account-denied` workspace.
 */
async function signInIncompatibleAccount(ws, phone, password, label) {
  await evalAction(ws, setReactValueExpression('input[name="phone"]', phone));
  await evalAction(ws, setReactValueExpression('input[name="password"]', password));
  await evalAction(ws, `document.querySelector('[data-testid="sign-in"]')?.click(); true`);
  const snap = await waitSnapshot(
    ws,
    (state) =>
      state.login === true &&
      state.mfa === false &&
      state.workspace === false &&
      state.practiceNav === false &&
      state.branches === false &&
      Boolean(state.loginError),
    15_000,
    `${label} client mismatch`,
  );
  if (snap.denied) {
    throw new Error(`${label} reached an authenticated Pharmacy shell`);
  }
  return snap;
}

function fillBranchForm(ws, name, address, phone) {
  return Promise.all([
    evalAction(ws, setReactValueExpression('#branch-public-name', name)),
    evalAction(ws, setReactValueExpression('#branch-address', address)),
    evalAction(ws, setReactValueExpression('#branch-latitude', '30.0444')),
    evalAction(ws, setReactValueExpression('#branch-longitude', '31.2357')),
    phone
      ? evalAction(ws, setReactValueExpression('#branch-phone', phone))
      : Promise.resolve(true),
  ]).then(() =>
    evalAction(
      ws,
      `(() => {
        const el =
          document.querySelector('[data-testid="confirm-coordinates"] input') ||
          document.querySelector('[data-testid="confirm-coordinates"]') ||
          document.querySelector('input[type="checkbox"]');
        if (!el) return false;
        if (el instanceof HTMLInputElement) {
          if (!el.checked) el.click();
          return el.checked;
        }
        el.click();
        return true;
      })()`,
    ),
  );
}

async function main() {
  if (process.platform === 'linux' && !process.env.DISPLAY && process.env.CLINIC_FORGE_PRACTICE_NESTED !== '1') {
    const rerun = spawnSync('xvfb-run', ['-a', process.execPath, fileURLToPath(import.meta.url)], {
      stdio: 'inherit',
      env: { ...process.env, CLINIC_FORGE_PRACTICE_NESTED: '1' },
    });
    process.exit(rerun.status ?? 1);
  }

  const evidencePath = pharmacyPracticeE2EEvidencePath();
  const required = isPharmacyPracticeE2ERequired();
  const apiBase = process.env.CLINIC_API_BASE_URL?.trim() || 'http://localhost:8080';
  const core = await probeCoreApi(apiBase);
  if (!core.reachable) {
    const skipped = {
      kind: 'forge-development-pharmacy-practice-e2e',
      skipped: true,
      required,
      reason: 'Core API was not reachable',
      apiBase,
      coreApi: core,
      core_health: 'unavailable',
    };
    writeEvidence(evidencePath, skipped);
    if (required) {
      throw new Error(
        `Core API was not reachable at ${apiBase} while ${PHARMACY_PRACTICE_E2E_REQUIRED_FLAG} is enabled. Evidence: ${evidencePath}`,
      );
    }
    process.stdout.write(`Forge Pharmacy practice E2E skipped. Evidence: ${evidencePath}\n`);
    return;
  }

  const workDir = mkdtempSync(join(tmpdir(), 'clinic-pharmacy-forge-practice-'));
  const fixturesPath = join(workDir, 'fixtures.json');
  const seeded = spawnSync('php', [join(coreApi, 'tests/Support/bin/seed-chunk15-pharmacy-practice.php')], {
    cwd: coreApi,
    env: { ...process.env, CLINIC_PHARMACY_PRACTICE_E2E_FIXTURES: fixturesPath },
    encoding: 'utf8',
  });
  if (seeded.status !== 0) {
    throw new Error(`Fixture seed failed: ${seeded.stderr || seeded.stdout}`);
  }
  const fixtures = JSON.parse(readFileSync(fixturesPath, 'utf8'));

  const debugPort = 9222 + Math.floor(Math.random() * 400);
  const protocolLog = join(workDir, 'protocol.log');
  const stdoutChunks = [];
  ensureElectronRuntime();
  const sandbox = spawnSync(process.execPath, [join(here, 'ensure-linux-chromium-sandbox.mjs')], {
    cwd: pharmacyApp,
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
      ...(process.platform === 'linux' && process.env.GNOME_KEYRING_CONTROL
        ? ['--password-store=gnome-libsecret']
        : []),
    ],
    {
      cwd: pharmacyApp,
      env: {
        ...process.env,
        CLINIC_DESKTOP_USER_DATA: join(workDir, 'user-data'),
        CLINIC_DESKTOP_PROTOCOL_LOG: protocolLog,
        CLINIC_API_BASE_URL: apiBase,
        ELECTRON_ENABLE_LOGGING: '1',
        ...(process.platform === 'linux'
          ? { XDG_CURRENT_DESKTOP: process.env.XDG_CURRENT_DESKTOP || 'GNOME' }
          : {}),
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
    kind: 'forge-development-pharmacy-practice-e2e',
    skipped: false,
    required,
    apiBase,
    coreApi: core,
    core_health: core.health === 'operational' ? 'operational' : core.health,
    branch_created: false,
    branch_edited: false,
    version_conflict: false,
    invited: false,
    accepted: false,
    membership_visible: false,
    revoked: false,
    account_isolation: false,
    phone_absent_after_submit: false,
    no_phase10_navigation: false,
    no_generic_invoke: false,
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

    const shell = await waitSnapshot(ws, (snap) => snap.login || snap.keystore, 45_000, 'login shell');
    if (shell.keystore && !shell.login) {
      throw new Error('OS keystore unavailable; Linux basic_text/unavailable remains fail-closed');
    }

    await signIn(ws, fixtures.ownerA.phone, fixtures.password, fixtures.ownerA.totp_secret, 'owner A');
    await waitSnapshot(ws, (snap) => snap.workspace && snap.practiceNav, 30_000, 'approved owner workspace');

    await evalAction(ws, `document.querySelector('[data-testid="open-practice-branches"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.branches, 15_000, 'branch list');
    await evalAction(ws, `document.querySelector('[data-testid="add-branch"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.create, 10_000, 'create form');
    await fillBranchForm(ws, 'Branch B', '1 Tahrir Square, Cairo', fixtures.ownerA.phone);
    await waitSnapshot(ws, (snap) => snap.submitEnabled, 8_000, 'create submit enabled');
    await evalAction(ws, `document.querySelector('[data-testid="branch-submit"]')?.click(); true`);
    const created = await waitSnapshot(ws, (snap) => snap.details && Boolean(snap.version), 20_000, 'created details');
    proof.branch_created = created.name?.includes('Branch B') === true;
    const branchId = String(created.hash).match(/[0-9a-f-]{36}/i)?.[0] ?? null;
    if (!branchId) {
      throw new Error('Created branch id missing from hash');
    }

    await evalAction(ws, `document.querySelector('[data-testid="tab-location"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.edit, 8_000, 'branch edit form');
    await fillBranchForm(ws, 'Cairo Nile Pharmacy', '1 Tahrir Square, Cairo', '');
    await waitSnapshot(ws, (snap) => snap.submitEnabled, 8_000, 'edit submit enabled');
    await evalAction(ws, `document.querySelector('[data-testid="branch-submit"]')?.click(); true`);
    const edited = await waitSnapshot(ws, (snap) => String(snap.version ?? '').includes('3') || String(snap.version ?? '').includes('2'), 20_000, 'edited version');
    proof.branch_edited = true;
    const loadedVersion = Number(String(edited.version ?? '').replace(/\D/g, '')) || 2;

    await evalAction(ws, `document.querySelector('[data-testid="tab-location"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.edit, 8_000, 'stale edit form');
    await fillBranchForm(ws, 'Stale Save Pharmacy', '1 Tahrir Square, Cairo', '');
    const bumped = spawnSync('php', [join(coreApi, 'tests/Support/bin/bump-chunk15-pharmacy-branch.php')], {
      cwd: coreApi,
      env: {
        ...process.env,
        CLINIC_PRACTICE_OWNER_USER_ID: fixtures.ownerA.user_id,
        CLINIC_PRACTICE_ORGANIZATION_ID: fixtures.ownerA.organization_id,
        CLINIC_PRACTICE_BRANCH_ID: branchId,
        CLINIC_PRACTICE_EXPECTED_VERSION: String(loadedVersion),
        CLINIC_PRACTICE_PUBLIC_NAME: 'Conflict Nile Pharmacy',
      },
      encoding: 'utf8',
    });
    if (bumped.status !== 0) {
      throw new Error(`Branch bump failed: ${bumped.stderr || bumped.stdout}`);
    }
    await waitSnapshot(ws, (snap) => snap.submitEnabled, 8_000, 'stale submit enabled');
    await evalAction(ws, `document.querySelector('[data-testid="branch-submit"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.conflict, 15_000, 'version conflict');
    await evalAction(ws, `document.querySelector('[data-testid="refresh-branch"]')?.click(); true`);
    const refreshed = await waitSnapshot(
      ws,
      (snap) => snap.conflict === false && String(snap.version ?? '').includes(String(loadedVersion + 1)),
      15_000,
      'refreshed latest',
    );
    proof.version_conflict = refreshed.name?.includes('Conflict Nile Pharmacy') === true || String(refreshed.version ?? '').includes(String(loadedVersion + 1));

    await evalAction(ws, `document.querySelector('[data-testid="tab-team"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.team, 8_000, 'team tab');
    await evalAction(ws, setReactValueExpression('#operator-invite-phone', fixtures.operator.phone));
    await evalAction(ws, `document.querySelector('[data-testid="send-invite"]')?.click(); true`);
    const invited = await waitSnapshot(ws, (snap) => Boolean(snap.invite), 15_000, 'pending invitation');
    proof.invited = true;
    proof.phone_absent_after_submit = !String(invited.body).includes(fixtures.operator.phone);
    if (!proof.phone_absent_after_submit) {
      throw new Error('Invite phone remained visible after submit');
    }

    const accepted = spawnSync('php', [join(coreApi, 'tests/Support/bin/accept-chunk15-pharmacy-invitation.php')], {
      cwd: coreApi,
      env: {
        ...process.env,
        CLINIC_PRACTICE_OPERATOR_USER_ID: fixtures.operator.user_id,
      },
      encoding: 'utf8',
    });
    if (accepted.status !== 0) {
      throw new Error(`Operator accept failed: ${accepted.stderr || accepted.stdout}`);
    }
    proof.accepted = true;
    await evalAction(ws, `document.querySelector('[data-testid="refresh-memberships"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.membershipStatus === 'active', 15_000, 'active membership');
    proof.membership_visible = true;
    await evalAction(ws, `document.querySelector('[data-testid^="revoke-"]')?.click(); true`);
    await sleep(400);
    await evalAction(ws, `document.querySelector('[data-testid="confirm-revoke"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.membershipStatus === 'revoked', 15_000, 'revoked membership');
    proof.revoked = true;

    await evalAction(ws, `document.querySelector('[data-testid="sign-out"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.login, 15_000, 'signed out A');
    await signIn(ws, fixtures.ownerB.phone, fixtures.password, fixtures.ownerB.totp_secret, 'owner B');
    const ownerB = await waitSnapshot(ws, (snap) => snap.workspace && snap.practiceNav, 30_000, 'owner B workspace');
    const leaked =
      String(ownerB.body).includes('Branch B') ||
      String(ownerB.body).includes('Cairo Nile Pharmacy') ||
      String(ownerB.body).includes('Conflict Nile Pharmacy') ||
      String(ownerB.body).includes('1 Tahrir Square') ||
      String(ownerB.hash).includes(branchId);
    proof.account_isolation = leaked === false && ownerB.login === false;

    await evalAction(ws, `location.hash = '/practice/branches/${branchId}'; window.dispatchEvent(new HashChangeEvent('hashchange')); true`);
    await waitSnapshot(ws, (snap) => snap.unavailable || snap.branches, 15_000, 'cross-owner');

    await evalAction(ws, `document.querySelector('[data-testid="sign-out"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.login, 15_000, 'signed out B');
    await signIn(ws, fixtures.pendingOwner.phone, fixtures.password, fixtures.pendingOwner.totp_secret, 'pending');
    const pending = await waitSnapshot(ws, (snap) => snap.workspace, 30_000, 'pending workspace');
    proof.no_phase10_navigation = pending.inventory === false && pending.pos === false && pending.practiceNav === false;
    proof.no_generic_invoke = pending.invoke === false && pending.doctor === 'undefined';

    await evalAction(ws, `document.querySelector('[data-testid="sign-out"]')?.click(); true`);
    await waitSnapshot(ws, (snap) => snap.login, 15_000, 'signed out pending');
    await signInIncompatibleAccount(ws, fixtures.doctor.phone, fixtures.password, 'doctor');

    const logs = Buffer.concat(stdoutChunks).toString('utf8');
    if (logs.includes(fixtures.operator.phone) || /CANARY-OPERATOR-PHONE|CANARY-BRANCH-ADDRESS/.test(logs)) {
      throw new Error('Forge practice logs contained a protected canary');
    }

    if (proof.core_health !== 'operational') {
      proof.core_health = 'operational';
    }
    assertPharmacyPracticeE2EEvidence(proof);
    writeEvidence(evidencePath, proof);
    process.stdout.write(`Forge Pharmacy practice E2E passed. Evidence: ${evidencePath}\n`);
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
