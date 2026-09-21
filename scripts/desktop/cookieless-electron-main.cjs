'use strict';

/**
 * Real Electron net.fetch cookieless probe for DOC-LOGIN-CSRF-001.
 *
 * Does not log cookie values. Result JSON may include cookie names and counts.
 */
const { app, net, session } = require('electron');
const { writeFileSync } = require('node:fs');

const fixtureUrl = String(process.env.CLINIC_COOKIELESS_FIXTURE_URL || '').replace(/\/$/, '');
const resultPath = String(process.env.CLINIC_COOKIELESS_RESULT_PATH || '');
const userData = String(process.env.CLINIC_COOKIELESS_USER_DATA || '');

function writeResult(payload) {
  if (!resultPath) {
    return;
  }
  writeFileSync(resultPath, `${JSON.stringify(payload)}\n`);
}

function cookieNames(list) {
  if (!Array.isArray(list)) {
    return [];
  }
  return list
    .map((cookie) => (cookie && typeof cookie.name === 'string' ? cookie.name : ''))
    .filter((name) => name === 'clinic_session' || name === 'XSRF-TOKEN')
    .sort();
}

async function main() {
  if (userData) {
    app.setPath('userData', userData);
  }
  await app.whenReady();

  if (fixtureUrl === '' || resultPath === '') {
    writeResult({ ok: false, error: 'RUNTIME_FAILED' });
    app.exit(1);
    return;
  }

  const control = session.fromPartition('cookie-control');
  await control.fetch(`${fixtureUrl}/set-cookies`, {
    method: 'GET',
    credentials: 'include',
    useSessionCookies: true,
  });
  const controlNames = cookieNames(await control.cookies.get({ url: fixtureUrl }));

  await net.fetch(`${fixtureUrl}/set-cookies`, { method: 'GET', credentials: 'omit' });
  const afterPrime = cookieNames(await session.defaultSession.cookies.get({ url: fixtureUrl }));

  const login = await net.fetch(`${fixtureUrl}/api/v1/auth/login`, {
    method: 'POST',
    credentials: 'omit',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      phone: '01000000000',
      password: 'fixture',
      client_class: 'doctor_desktop',
      platform: 'linux',
      device_label: 'cookieless-probe',
    }),
  });
  const afterLogin = cookieNames(await session.defaultSession.cookies.get({ url: fixtureUrl }));

  const mfa = await net.fetch(`${fixtureUrl}/api/v1/auth/mfa/challenges/fixture-challenge/verify`, {
    method: 'POST',
    credentials: 'omit',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ code: '123456' }),
  });
  const afterMfa = cookieNames(await session.defaultSession.cookies.get({ url: fixtureUrl }));

  const me = await net.fetch(`${fixtureUrl}/api/v1/me`, {
    method: 'GET',
    credentials: 'omit',
    headers: { Authorization: 'Bearer fixture-access', Accept: 'application/json' },
  });

  const upload = await net.fetch(`${fixtureUrl}/upload`, {
    method: 'PUT',
    credentials: 'omit',
    redirect: 'error',
    headers: { 'Content-Type': 'application/pdf' },
    body: Buffer.from('%PDF-1.4\n%%EOF\n'),
  });

  writeResult({
    ok:
      login.status === 200 &&
      mfa.status === 200 &&
      me.status === 200 &&
      upload.status === 200 &&
      afterPrime.length === 0 &&
      afterLogin.length === 0 &&
      afterMfa.length === 0 &&
      controlNames.includes('clinic_session') &&
      controlNames.includes('XSRF-TOKEN'),
    credentialsMode: 'omit',
    controlCookieCount: controlNames.length,
    controlCookieNames: controlNames,
    defaultCookieCount: afterMfa.length,
    defaultCookieNames: afterMfa,
    loginStatus: login.status,
    mfaStatus: mfa.status,
    meStatus: me.status,
    uploadStatus: upload.status,
    csrfMismatch: login.status === 403 || mfa.status === 403 || me.status === 403,
  });
  app.exit(0);
}

main().catch(() => {
  writeResult({ ok: false, error: 'RUNTIME_FAILED' });
  app.exit(1);
});
