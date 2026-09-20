import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
  formatSanitizedAccessLog,
  sanitizedRequestLogPath,
  stripRequestQueryFromLogLine,
} from './sanitize-request-log.mjs';

const CANARY_QUERY =
  'signature=SIGNED_SECRET_CANARY&X-Amz-Signature=AMZ_SECRET_CANARY&cursor=SIGNED_CURSOR_CANARY&token=TOKEN_SECRET_CANARY';
const REVIEWER_PATH =
  '/api/v1/verification-review-files/0199a5c8-0000-7000-8000-000000000021/0199a5c8-0000-7000-8000-000000000041';

test('sanitizedRequestLogPath keeps pathname and query presence only', () => {
  const withQuery = sanitizedRequestLogPath(`${REVIEWER_PATH}?${CANARY_QUERY}`);
  assert.equal(withQuery.pathname, REVIEWER_PATH);
  assert.equal(withQuery.queryPresent, true);

  const withoutQuery = sanitizedRequestLogPath('/api/v1/me');
  assert.equal(withoutQuery.pathname, '/api/v1/me');
  assert.equal(withoutQuery.queryPresent, false);

  const unparseable = sanitizedRequestLogPath('http://[bad');
  assert.equal(unparseable.pathname, '/unparseable');
  assert.equal(unparseable.queryPresent, false);
});

test('formatSanitizedAccessLog never emits signed query canaries', () => {
  const line = formatSanitizedAccessLog({
    method: 'GET',
    rawUrl: `${REVIEWER_PATH}?${CANARY_QUERY}`,
    cookie: '1',
    cookieNames: 'clinic_session',
    auth: '1',
    xsrf: '0',
    setCookie: '0',
    status: '200',
  });

  assert.equal(line.includes('SIGNED_SECRET_CANARY'), false);
  assert.equal(line.includes('AMZ_SECRET_CANARY'), false);
  assert.equal(line.includes('SIGNED_CURSOR_CANARY'), false);
  assert.equal(line.includes('TOKEN_SECRET_CANARY'), false);
  assert.equal(line.includes('signature='), false);
  assert.equal(line.includes('X-Amz-Signature='), false);
  assert.match(line, /^GET \/api\/v1\/verification-review-files\/0199a5c8-0000-7000-8000-000000000021\/0199a5c8-0000-7000-8000-000000000041 qs=1 /);
});

test('stripRequestQueryFromLogLine redacts php -S REQUEST_URI query strings', () => {
  const phpLine = `[Sun Sep 20 16:59:04 2026] 127.0.0.1:43142 [200]: GET ${REVIEWER_PATH}?${CANARY_QUERY}`;
  const stripped = stripRequestQueryFromLogLine(phpLine);

  assert.equal(stripped.includes('SIGNED_SECRET_CANARY'), false);
  assert.equal(stripped.includes('AMZ_SECRET_CANARY'), false);
  assert.equal(stripped.includes('SIGNED_CURSOR_CANARY'), false);
  assert.equal(stripped.includes('TOKEN_SECRET_CANARY'), false);
  assert.match(stripped, /GET \/api\/v1\/verification-review-files\/0199a5c8-0000-7000-8000-000000000021\/0199a5c8-0000-7000-8000-000000000041 qs=1$/);
});

test('redact-e2e-stdio drops query canaries from piped php -S lines', async () => {
  const child = spawn(process.execPath, [fileURLToPath(new URL('./redact-e2e-stdio.mjs', import.meta.url))], {
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  let stdout = '';
  child.stdout.on('data', (chunk) => {
    stdout += chunk.toString();
  });
  child.stdin.write(
    `[Sun Sep 20 16:59:04 2026] 127.0.0.1:43142 [200]: GET ${REVIEWER_PATH}?${CANARY_QUERY}\n`,
  );
  child.stdin.end();
  const exitCode = await new Promise((resolve) => {
    child.on('exit', (code) => resolve(code ?? 1));
  });
  assert.equal(exitCode, 0);
  assert.equal(stdout.includes('SIGNED_SECRET_CANARY'), false);
  assert.equal(stdout.includes('AMZ_SECRET_CANARY'), false);
  assert.match(
    stdout,
    /GET \/api\/v1\/verification-review-files\/0199a5c8-0000-7000-8000-000000000021\/0199a5c8-0000-7000-8000-000000000041 qs=1\n/,
  );
});
