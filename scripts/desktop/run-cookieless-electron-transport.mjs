#!/usr/bin/env node
/**
 * Runtime proof that Electron net.fetch with credentials: 'omit' does not
 * acquire or send Laravel session/XSRF cookies (DOC-LOGIN-CSRF-001).
 *
 * Not packaged E2E. Not Chunk 11 closeout. No cookie values are logged.
 */
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import {
  createServer,
} from 'node:http';
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
const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const doctorGateway = join(repoRoot, 'apps', 'doctor-desktop', 'src', 'main', 'platform-gateway.ts');
const pharmacyGateway = join(repoRoot, 'apps', 'pharmacy-desktop', 'src', 'main', 'platform-gateway.ts');
const electronMain = join(here, 'cookieless-electron-main.cjs');

export function assertDeviceGatewaySourcesCookieless(source) {
  if (typeof source !== 'string' || source.length === 0) {
    throw new Error('platform-gateway source missing');
  }
  const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
  if (!code.includes("DEVICE_NET_FETCH_CREDENTIALS = 'omit'")) {
    throw new Error('device transport is not explicitly cookieless');
  }
  if (!code.includes('credentials: DEVICE_NET_FETCH_CREDENTIALS')) {
    throw new Error('net.fetch is not bound to cookieless credentials');
  }
  if (code.includes("credentials: 'include'") || code.includes("credentials: 'same-origin'")) {
    throw new Error('device transport must not include session credentials');
  }
  if (/\bXSRF-TOKEN\b/.test(code) || code.includes('X-XSRF-TOKEN') || code.includes('X-CSRF-TOKEN')) {
    throw new Error('device transport must not copy browser CSRF cookies');
  }
  if ((code.match(/net\.fetch\(/g) || []).length !== 1) {
    throw new Error('device net.fetch must go through one cookieless helper');
  }
}

export function cookieNamesOnly(names) {
  if (!Array.isArray(names)) {
    return [];
  }
  return names.filter((name) => name === 'clinic_session' || name === 'XSRF-TOKEN');
}

export function requestHasSessionCookies(cookieHeader) {
  const raw = String(cookieHeader || '');
  return /(?:^|;\s*)clinic_session=/.test(raw) || /(?:^|;\s*)XSRF-TOKEN=/.test(raw);
}

function proxyEnvPresent(env) {
  for (const key of ['HTTP_PROXY', 'HTTPS_PROXY', 'http_proxy', 'https_proxy', 'ALL_PROXY', 'all_proxy']) {
    if (typeof env[key] === 'string' && env[key].trim() !== '') {
      return true;
    }
  }
  return false;
}

function envelope(data) {
  return JSON.stringify({
    data,
    meta: {},
    errors: [],
    request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
  });
}

function startFixture() {
  const seen = {
    loginCookie: false,
    mfaCookie: false,
    meCookie: false,
    uploadCookie: false,
    uploadAuthorization: false,
    csrfMismatch: false,
    setCookieResponses: 0,
  };

  const server = createServer((req, res) => {
    const url = new URL(req.url || '/', 'http://127.0.0.1');
    const cookie = String(req.headers.cookie || '');
    const authorization = String(req.headers.authorization || '');
    const hasCookies = requestHasSessionCookies(cookie);

    const setSessionCookies = () => {
      seen.setCookieResponses += 1;
      res.setHeader('Set-Cookie', [
        'clinic_session=fixture; Path=/; HttpOnly',
        'XSRF-TOKEN=fixture; Path=/',
      ]);
    };

    if (url.pathname === '/set-cookies' && req.method === 'GET') {
      setSessionCookies();
      res.statusCode = 200;
      res.setHeader('Content-Type', 'application/json');
      res.end(envelope({ primed: true }));
      return;
    }

    if (hasCookies && (url.pathname === '/api/v1/auth/login' || url.pathname.includes('/auth/mfa/') || url.pathname === '/api/v1/me')) {
      seen.csrfMismatch = true;
      if (url.pathname === '/api/v1/auth/login') {
        seen.loginCookie = true;
      }
      if (url.pathname.includes('/auth/mfa/')) {
        seen.mfaCookie = true;
      }
      if (url.pathname === '/api/v1/me') {
        seen.meCookie = true;
      }
      res.statusCode = 403;
      res.setHeader('Content-Type', 'application/json');
      res.end(JSON.stringify({
        data: null,
        meta: {},
        errors: [{ code: 'CSRF_MISMATCH' }],
        request_id: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      }));
      return;
    }

    if (url.pathname === '/api/v1/auth/login' && req.method === 'POST') {
      seen.loginCookie = hasCookies;
      setSessionCookies();
      res.statusCode = 200;
      res.setHeader('Content-Type', 'application/json');
      res.end(envelope({
        status: 'mfa_required',
        mfa_required: true,
        challenge_id: 'fixture-challenge',
        session_kind: 'device',
        account_type: 'doctor',
      }));
      return;
    }

    if (url.pathname === '/api/v1/auth/mfa/challenges/fixture-challenge/verify' && req.method === 'POST') {
      seen.mfaCookie = hasCookies;
      res.statusCode = 200;
      res.setHeader('Content-Type', 'application/json');
      res.end(envelope({
        status: 'active',
        mfa_required: false,
        session_kind: 'device',
        account_type: 'doctor',
        access_token: 'fixture-access',
        refresh_token: 'fixture-refresh',
      }));
      return;
    }

    if (url.pathname === '/api/v1/me' && req.method === 'GET') {
      seen.meCookie = hasCookies;
      res.statusCode = 200;
      res.setHeader('Content-Type', 'application/json');
      res.end(envelope({
        user_id: '0199a5c8-0000-7000-8000-000000000001',
        account_type: 'doctor',
        status: 'active',
      }));
      return;
    }

    if (url.pathname === '/upload' && req.method === 'PUT') {
      seen.uploadCookie = hasCookies;
      seen.uploadAuthorization = authorization.startsWith('Bearer ');
      if (seen.uploadCookie || seen.uploadAuthorization) {
        res.statusCode = 400;
        res.end();
        return;
      }
      res.statusCode = 200;
      res.end();
      return;
    }

    res.statusCode = 404;
    res.end();
  });

  return new Promise((resolvePromise) => {
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      const port = address && typeof address === 'object' ? address.port : 0;
      resolvePromise({
        server,
        seen,
        url: `http://127.0.0.1:${port}`,
      });
    });
  });
}

function electronBinary() {
  const pkg = join(repoRoot, 'node_modules', 'electron');
  if (!existsSync(pkg)) {
    throw new Error('Electron package is not installed at the workspace root');
  }
  return require(pkg);
}

function ensureElectronRuntime() {
  const dist = join(repoRoot, 'node_modules', 'electron', 'dist');
  if (existsSync(join(dist, 'electron')) || existsSync(join(dist, 'electron.exe'))) {
    return;
  }
  const installer = join(repoRoot, 'node_modules', 'electron', 'install.js');
  const installed = spawnSync(process.execPath, [installer], { cwd: repoRoot, stdio: 'inherit' });
  if (installed.status !== 0) {
    throw new Error(`Electron download failed with status ${installed.status ?? 'null'}`);
  }
}

async function main() {
  if (process.platform === 'linux' && !process.env.DISPLAY && process.env.CLINIC_COOKIELESS_NESTED !== '1') {
    const rerun = spawnSync('xvfb-run', ['-a', process.execPath, fileURLToPath(import.meta.url)], {
      stdio: 'inherit',
      env: { ...process.env, CLINIC_COOKIELESS_NESTED: '1' },
    });
    process.exit(rerun.status ?? 1);
  }

  if (proxyEnvPresent(process.env)) {
    throw new Error('Cookie-strip or HTTP proxy env is set; this proof must not use a proxy workaround');
  }

  assertDeviceGatewaySourcesCookieless(readFileSync(doctorGateway, 'utf8'));
  assertDeviceGatewaySourcesCookieless(readFileSync(pharmacyGateway, 'utf8'));

  ensureElectronRuntime();
  const sandbox = spawnSync(process.execPath, [join(here, 'ensure-linux-chromium-sandbox.mjs')], {
    cwd: join(repoRoot, 'apps', 'doctor-desktop'),
    stdio: 'inherit',
  });
  if (sandbox.status !== 0) {
    throw new Error(`Linux Chromium sandbox helper failed with status ${sandbox.status ?? 'null'}`);
  }

  const workDir = mkdtempSync(join(tmpdir(), 'clinic-cookieless-electron-'));
  const resultPath = join(workDir, 'result.json');
  const fixture = await startFixture();

  try {
    const child = spawn(electronBinary(), [electronMain], {
      cwd: repoRoot,
      env: {
        ...process.env,
        CLINIC_COOKIELESS_FIXTURE_URL: fixture.url,
        CLINIC_COOKIELESS_RESULT_PATH: resultPath,
        CLINIC_COOKIELESS_USER_DATA: join(workDir, 'user-data'),
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    const output = [];
    child.stdout.on('data', (chunk) => output.push(chunk));
    child.stderr.on('data', (chunk) => output.push(chunk));

    const status = await new Promise((resolvePromise) => {
      child.on('exit', (code, signal) => resolvePromise({ code, signal }));
    });

    if (!existsSync(resultPath)) {
      throw new Error(`Electron cookieless probe produced no result (exit ${status.code ?? 'null'})`);
    }
    const result = JSON.parse(readFileSync(resultPath, 'utf8'));
    if (result.ok !== true || result.csrfMismatch === true) {
      throw new Error('Electron cookieless login/MFA probe failed');
    }
    if (fixture.seen.loginCookie || fixture.seen.mfaCookie || fixture.seen.meCookie || fixture.seen.uploadCookie) {
      throw new Error('Fixture observed Laravel session cookies on a device request');
    }
    if (fixture.seen.uploadAuthorization) {
      throw new Error('Upload PUT included a Core Authorization header');
    }
    if (fixture.seen.csrfMismatch) {
      throw new Error('Fixture returned CSRF_MISMATCH');
    }
    if (cookieNamesOnly(result.defaultCookieNames).length !== 0) {
      throw new Error('defaultSession retained Core device-session cookies');
    }

    const evidenceDir = join(repoRoot, 'tests', 'desktop-e2e', 'logs');
    mkdirSync(evidenceDir, { recursive: true });
    writeFileSync(
      join(evidenceDir, 'cookieless-electron-transport.json'),
      `${JSON.stringify({
        kind: 'cookieless-electron-net-fetch',
        proxyEnvPresent: false,
        fixtureHost: '127.0.0.1',
        credentialsMode: result.credentialsMode,
        controlCookieCount: result.controlCookieCount,
        controlCookieNames: cookieNamesOnly(result.controlCookieNames),
        defaultCookieCount: result.defaultCookieCount,
        defaultCookieNames: cookieNamesOnly(result.defaultCookieNames),
        loginStatus: result.loginStatus,
        mfaStatus: result.mfaStatus,
        meStatus: result.meStatus,
        uploadStatus: result.uploadStatus,
        csrfMismatch: false,
        fixtureSawDeviceCookies: false,
        uploadHadAuthorization: false,
      }, null, 2)}\n`,
    );
    process.stdout.write('Cookieless Electron net.fetch runtime passed.\n');
  } finally {
    fixture.server.close();
    try {
      rmSync(workDir, { recursive: true, force: true });
    } catch {
      // best-effort
    }
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
