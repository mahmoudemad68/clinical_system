import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

function listen(server) {
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      resolve(typeof address === 'object' && address !== null ? address.port : 0);
    });
  });
}

async function startProxy(t, apiPort) {
  const child = spawn(process.execPath, [fileURLToPath(new URL('./admin-web-server.mjs', import.meta.url))], {
    env: {
      ...process.env,
      CLINIC_WEB_BASE_URL: `http://127.0.0.1:${String(apiPort)}`,
      CLINIC_ADMIN_WEB_PORT: '0',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  t.after(() => {
    child.kill('SIGTERM');
  });

  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (chunk) => {
    stdout += chunk.toString();
  });
  const previewPort = await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error(`proxy did not start: ${stderr}`)), 10_000);
    const onExit = (code, signal) => {
      clearTimeout(timeout);
      reject(new Error(`proxy exited code=${String(code)} signal=${String(signal)}: ${stderr}`));
    };
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString();
      const match = /listening on http:\/\/127\.0\.0\.1:(\d+)/.exec(stderr);
      if (match) {
        clearTimeout(timeout);
        child.off('exit', onExit);
        resolve(Number.parseInt(match[1], 10));
      }
    });
    child.once('exit', onExit);
  });

  return {
    child,
    previewPort,
    stderr: () => stderr,
    stdout: () => stdout,
    combined: () => `${stdout}${stderr}`,
  };
}

function proxyGet(port, { path, headers = {} }) {
  return new Promise((resolve, reject) => {
    const req = http.request(
      {
        hostname: '127.0.0.1',
        port,
        path,
        method: 'GET',
        headers,
      },
      (res) => {
        res.resume();
        res.on('end', () => resolve(res.statusCode ?? 0));
      },
    );
    req.on('error', reject);
    req.end();
  });
}

async function waitUntil(predicate, timeoutMs = 2_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (predicate()) {
      return;
    }
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  throw new Error('timed out waiting for proxy access log');
}

function proxyPost(port, { path, headers, body }) {
  return new Promise((resolve, reject) => {
    const req = http.request(
      {
        hostname: '127.0.0.1',
        port,
        path,
        method: 'POST',
        headers,
      },
      (res) => {
        res.resume();
        res.on('end', () => resolve(res.statusCode ?? 0));
      },
    );
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

test('admin web proxy forwards multiple Set-Cookie headers and stays alive', async (t) => {
  const api = http.createServer((_req, res) => {
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Set-Cookie', [
      'clinic_session=e2e-session; Path=/; HttpOnly; SameSite=Lax',
      'XSRF-TOKEN=e2e-xsrf; Path=/; SameSite=Lax',
    ]);
    res.writeHead(401);
    res.end('{"errors":[{"code":"UNAUTHENTICATED","message":"unauthenticated"}]}');
  });
  const apiPort = await listen(api);
  t.after(
    () =>
      new Promise((resolve) => {
        api.close(resolve);
      }),
  );

  const { child, previewPort, stderr } = await startProxy(t, apiPort);

  const first = await fetch(`http://127.0.0.1:${String(previewPort)}/api/v1/me`);
  assert.equal(first.status, 401);
  const cookies = first.headers.getSetCookie();
  assert.equal(cookies.length, 2);
  assert.ok(cookies.some((cookie) => cookie.startsWith('clinic_session=')));
  assert.ok(cookies.some((cookie) => cookie.startsWith('XSRF-TOKEN=')));
  assert.equal(child.exitCode, null);

  const second = await fetch(`http://127.0.0.1:${String(previewPort)}/api/v1/health`);
  assert.equal(second.status, 401);
  assert.equal(child.exitCode, null);
  assert.doesNotMatch(stderr(), /ERR_HTTP_HEADERS_SENT/);
});

test('admin web proxy forwards Cookie with RFC casing and the API Host on POST', async (t) => {
  /** @type {{ cookieName: string, host: string, cookie: string }[]} */
  const seen = [];
  const api = http.createServer((req, res) => {
    let cookieName = '';
    const raw = req.rawHeaders;
    for (let i = 0; i < raw.length; i += 2) {
      if (raw[i].toLowerCase() === 'cookie') {
        cookieName = raw[i];
        break;
      }
    }
    seen.push({
      cookieName,
      host: req.headers.host ?? '',
      cookie: req.headers.cookie ?? '',
    });
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end('{"ok":true}');
  });
  const apiPort = await listen(api);
  t.after(
    () =>
      new Promise((resolve) => {
        api.close(resolve);
      }),
  );

  const { previewPort } = await startProxy(t, apiPort);
  const status = await proxyPost(previewPort, {
    path: '/api/v1/admin/verification-cases/00000000-0000-7000-8000-000000000001/claim',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Cookie: 'clinic_session=e2e-session; XSRF-TOKEN=e2e-xsrf',
      'X-XSRF-TOKEN': 'e2e-xsrf',
    },
    body: '{"expected_case_version":1}',
  });

  assert.equal(status, 200);
  assert.equal(seen.length, 1);
  assert.equal(seen[0]?.cookieName, 'Cookie');
  assert.equal(seen[0]?.cookie, 'clinic_session=e2e-session; XSRF-TOKEN=e2e-xsrf');
  assert.equal(seen[0]?.host, `127.0.0.1:${String(apiPort)}`);
});

test('admin web proxy logs pathname only and never query signatures', async (t) => {
  const reviewerPath =
    '/api/v1/verification-review-files/0199a5c8-0000-7000-8000-000000000021/0199a5c8-0000-7000-8000-000000000041';
  const query =
    'signature=SIGNED_SECRET_CANARY&X-Amz-Signature=AMZ_SECRET_CANARY&expires=1&cursor=SIGNED_CURSOR_CANARY&token=TOKEN_SECRET_CANARY';
  /** @type {string[]} */
  const upstreamUrls = [];
  const api = http.createServer((req, res) => {
    upstreamUrls.push(req.url ?? '');
    res.writeHead(200, { 'content-type': 'application/pdf', 'content-length': '4' });
    res.end('test');
  });
  const apiPort = await listen(api);
  t.after(
    () =>
      new Promise((resolve) => {
        api.close(resolve);
      }),
  );

  const { previewPort, combined } = await startProxy(t, apiPort);
  const status = await proxyGet(previewPort, {
    path: `${reviewerPath}?${query}`,
    headers: {
      Authorization: 'Bearer BEARER_SECRET_CANARY',
      Cookie: 'clinic_session=COOKIE_SECRET_CANARY',
    },
  });
  assert.equal(status, 200);
  assert.equal(upstreamUrls.length, 1);
  assert.equal(upstreamUrls[0], `${reviewerPath}?${query}`);

  await waitUntil(() => combined().includes(`${reviewerPath} qs=1`));

  const logs = combined();
  assert.match(logs, /GET \/api\/v1\/verification-review-files\/0199a5c8-0000-7000-8000-000000000021\/0199a5c8-0000-7000-8000-000000000041 qs=1 /);
  assert.equal(logs.includes('SIGNED_SECRET_CANARY'), false, 'signature canary must not enter proxy logs');
  assert.equal(logs.includes('AMZ_SECRET_CANARY'), false, 'X-Amz-Signature canary must not enter proxy logs');
  assert.equal(logs.includes('SIGNED_CURSOR_CANARY'), false, 'cursor canary must not enter proxy logs');
  assert.equal(logs.includes('TOKEN_SECRET_CANARY'), false, 'token canary must not enter proxy logs');
  assert.equal(logs.includes('BEARER_SECRET_CANARY'), false, 'authorization value must not enter proxy logs');
  assert.equal(logs.includes('COOKIE_SECRET_CANARY'), false, 'cookie value must not enter proxy logs');
  assert.equal(logs.includes('signature='), false);
  assert.equal(logs.includes('X-Amz-Signature='), false);
  assert.equal(logs.includes('?'), false);
});
