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

  let stderr = '';
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
  assert.doesNotMatch(stderr, /ERR_HTTP_HEADERS_SENT/);
});
