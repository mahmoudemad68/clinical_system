import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';

/**
 * G-01-20 live auth-abuse harness.
 *
 * Synthetic destinations only. Response bodies are discarded so OTP codes,
 * passwords, refresh tokens, and National IDs cannot appear in k6 output.
 * 5xx is a failure. 429 is required for each abuse scenario after the
 * configured Redis-backed threshold. Traffic below the threshold must not 429.
 */
const BASES = String(__ENV.CLINIC_API_BASE_URLS || __ENV.CLINIC_API_BASE_URL || 'http://127.0.0.1:8080')
  .split(',')
  .map((item) => item.trim())
  .filter((item) => item !== '');

const INVALID_PASSWORD = String(__ENV.K6_INVALID_PASSWORD || '').trim();
if (INVALID_PASSWORD === '') {
  throw new Error(
    'K6_INVALID_PASSWORD is required. Generate it at runtime via scripts/perf/run-k6-auth-abuse.sh; do not commit a password.',
  );
}

const CHALLENGE = '01900000-0000-7000-8000-00000000c0e1';
const MFA_CHALLENGE = '01900000-0000-7000-8000-00000000c0e2';
const RECOVERY_CHALLENGE = '01900000-0000-7000-8000-00000000c0e3';

const unexpectedServer = new Rate('unexpected_server_error');
const belowThreshold429 = new Counter('below_threshold_429');
const loginAbuse429 = new Counter('login_abuse_429');
const otpRequest429 = new Counter('otp_request_429');
const otpResend429 = new Counter('otp_resend_429');
const otpVerify429 = new Counter('otp_verify_429');
const refreshAbuse429 = new Counter('refresh_abuse_429');
const mfaAbuse429 = new Counter('mfa_abuse_429');
const recoveryStart429 = new Counter('recovery_start_429');
const recoveryComplete429 = new Counter('recovery_complete_429');
const retryAfterMissing = new Counter('retry_after_missing');
const requestsByProcess = new Counter('requests_by_process');

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 499 }));

export const options = {
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(50)', 'p(95)', 'p(99)'],
  scenarios: {
    below_threshold: {
      executor: 'shared-iterations',
      exec: 'belowThreshold',
      vus: 2,
      iterations: 4,
      maxDuration: '20s',
    },
    login_abuse: {
      executor: 'constant-vus',
      exec: 'loginAbuse',
      vus: 8,
      duration: '20s',
      startTime: '4s',
    },
    otp_request_abuse: {
      executor: 'constant-vus',
      exec: 'otpRequestAbuse',
      vus: 6,
      duration: '20s',
      startTime: '4s',
    },
    otp_resend_abuse: {
      executor: 'constant-vus',
      exec: 'otpResendAbuse',
      vus: 6,
      duration: '20s',
      startTime: '4s',
    },
    otp_verify_abuse: {
      executor: 'constant-vus',
      exec: 'otpVerifyAbuse',
      vus: 8,
      duration: '20s',
      startTime: '4s',
    },
    refresh_abuse: {
      executor: 'constant-vus',
      exec: 'refreshAbuse',
      vus: 8,
      duration: '20s',
      startTime: '4s',
    },
    mfa_abuse: {
      executor: 'constant-vus',
      exec: 'mfaAbuse',
      vus: 8,
      duration: '20s',
      startTime: '4s',
    },
    recovery_start_abuse: {
      executor: 'constant-vus',
      exec: 'recoveryStartAbuse',
      vus: 6,
      duration: '20s',
      startTime: '4s',
    },
    recovery_complete_abuse: {
      executor: 'constant-vus',
      exec: 'recoveryCompleteAbuse',
      vus: 6,
      duration: '20s',
      startTime: '4s',
    },
  },
  thresholds: {
    unexpected_server_error: ['rate==0'],
    dropped_iterations: ['count==0'],
    below_threshold_429: ['count==0'],
    login_abuse_429: ['count>0'],
    otp_request_429: ['count>0'],
    otp_resend_429: ['count>0'],
    otp_verify_429: ['count>0'],
    refresh_abuse_429: ['count>0'],
    mfa_abuse_429: ['count>0'],
    recovery_start_429: ['count>0'],
    recovery_complete_429: ['count>0'],
    retry_after_missing: ['count==0'],
    checks: ['rate>0.99'],
  },
};

function baseUrl() {
  if (BASES.length === 0) {
    return 'http://127.0.0.1:8080';
  }

  return BASES[(__VU + __ITER) % BASES.length];
}

function jsonHeaders(idempotency) {
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
  if (idempotency) {
    headers['Idempotency-Key'] = idempotency;
  }

  return headers;
}

function uniqueKey(label) {
  return `k6-${label}-${__VU}-${__ITER}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

function retryAfterHeader(res) {
  const headers = res.headers || {};

  return headers['Retry-After'] || headers['retry-after'] || headers['RETRY-AFTER'] || '';
}

function record(res, abuseCounter) {
  requestsByProcess.add(1, { target: baseUrl() });
  unexpectedServer.add(res.status >= 500);

  if (res.status === 429) {
    if (abuseCounter) {
      abuseCounter.add(1);
    }
    const retryAfter = retryAfterHeader(res);
    if (retryAfter === '' || Number.isNaN(Number(retryAfter))) {
      retryAfterMissing.add(1);
    }
  }
}

function loginBody() {
  return JSON.stringify({
    phone: '01000000000',
    password: INVALID_PASSWORD,
    client_class: 'patient_mobile',
    platform: 'android',
    device_label: 'k6',
  });
}

export function belowThreshold() {
  const base = baseUrl();
  const login = http.post(`${base}/api/v1/auth/login`, loginBody(), {
    headers: jsonHeaders(),
    tags: { scenario_name: 'below_threshold' },
  });
  record(login, belowThreshold429);
  check(login, {
    below_threshold_login_not_limited: (r) => r.status === 401,
  });

  const otp = http.post(
    `${base}/api/v1/auth/otp-requests`,
    JSON.stringify({
      phone: '01000000010',
      purpose: 'registration',
      language: 'en',
    }),
    {
      headers: jsonHeaders(uniqueKey('below-otp')),
      tags: { scenario_name: 'below_threshold' },
    },
  );
  record(otp, belowThreshold429);
  check(otp, {
    below_threshold_otp_not_limited: (r) => r.status === 200 || r.status === 201,
  });

  const refresh = http.post(
    `${base}/api/v1/auth/token/refresh`,
    JSON.stringify({ refresh_token: 'k6InvalidRefreshToken' }),
    {
      headers: jsonHeaders(uniqueKey('below-ref')),
      tags: { scenario_name: 'below_threshold' },
    },
  );
  record(refresh, belowThreshold429);
  check(refresh, {
    below_threshold_refresh_not_limited: (r) => r.status === 401,
  });

  sleep(0.2);
}

export function loginAbuse() {
  const res = http.post(`${baseUrl()}/api/v1/auth/login`, loginBody(), {
    headers: jsonHeaders(),
    tags: { scenario_name: 'login_abuse' },
  });
  record(res, loginAbuse429);
  check(res, {
    login_denied_or_limited: (r) => r.status === 401 || r.status === 429,
  });
}

export function otpRequestAbuse() {
  const res = http.post(
    `${baseUrl()}/api/v1/auth/otp-requests`,
    JSON.stringify({
      phone: '01000000020',
      purpose: 'registration',
      language: 'en',
    }),
    {
      headers: jsonHeaders(uniqueKey('otp-req')),
      tags: { scenario_name: 'otp_request_abuse' },
    },
  );
  record(res, otpRequest429);
  check(res, {
    otp_request_accepted_or_limited: (r) => r.status === 200 || r.status === 201 || r.status === 429,
  });
}

export function otpResendAbuse() {
  const res = http.post(
    `${baseUrl()}/api/v1/auth/otp-requests`,
    JSON.stringify({
      phone: '01000000020',
      purpose: 'registration',
      language: 'en',
    }),
    {
      headers: jsonHeaders(uniqueKey('otp-resend')),
      tags: { scenario_name: 'otp_resend_abuse' },
    },
  );
  record(res, otpResend429);
  check(res, {
    otp_resend_accepted_or_limited: (r) => r.status === 200 || r.status === 201 || r.status === 429,
  });
}

export function otpVerifyAbuse() {
  const res = http.post(
    `${baseUrl()}/api/v1/auth/otp-verifications`,
    JSON.stringify({
      challenge_id: CHALLENGE,
      code: '246801',
      client_class: 'patient_mobile',
      platform: 'android',
      device_label: 'k6',
    }),
    {
      headers: jsonHeaders(uniqueKey('otp-ver')),
      tags: { scenario_name: 'otp_verify_abuse' },
    },
  );
  record(res, otpVerify429);
  check(res, {
    otp_verify_denied_or_limited: (r) => r.status === 422 || r.status === 429,
  });
}

export function refreshAbuse() {
  const res = http.post(
    `${baseUrl()}/api/v1/auth/token/refresh`,
    JSON.stringify({ refresh_token: 'k6InvalidRefreshToken' }),
    {
      headers: jsonHeaders(uniqueKey('refresh')),
      tags: { scenario_name: 'refresh_abuse' },
    },
  );
  record(res, refreshAbuse429);
  check(res, {
    refresh_denied_or_limited: (r) => r.status === 401 || r.status === 429,
  });
}

export function mfaAbuse() {
  const res = http.post(
    `${baseUrl()}/api/v1/auth/mfa/challenges/${MFA_CHALLENGE}/verify`,
    JSON.stringify({ code: '246801' }),
    {
      headers: jsonHeaders(),
      tags: { scenario_name: 'mfa_abuse' },
    },
  );
  record(res, mfaAbuse429);
  check(res, {
    mfa_denied_or_limited: (r) => r.status === 422 || r.status === 429,
  });
}

export function recoveryStartAbuse() {
  const res = http.post(
    `${baseUrl()}/api/v1/auth/recovery/start`,
    JSON.stringify({
      phone: '01000000030',
      language: 'en',
    }),
    {
      headers: jsonHeaders(),
      tags: { scenario_name: 'recovery_start_abuse' },
    },
  );
  record(res, recoveryStart429);
  check(res, {
    recovery_start_accepted_or_limited: (r) => r.status === 200 || r.status === 201 || r.status === 429,
  });
}

export function recoveryCompleteAbuse() {
  const res = http.post(
    `${baseUrl()}/api/v1/auth/recovery/complete`,
    JSON.stringify({
      challenge_id: RECOVERY_CHALLENGE,
      code: '246801',
      password: 'RecoveredHorse12',
    }),
    {
      headers: jsonHeaders(uniqueKey('rec-complete')),
      tags: { scenario_name: 'recovery_complete_abuse' },
    },
  );
  record(res, recoveryComplete429);
  check(res, {
    recovery_complete_denied_or_limited: (r) => r.status === 422 || r.status === 429,
  });
}

function metricCount(data, name) {
  const metric = data.metrics[name];
  if (!metric || !metric.values) {
    return 0;
  }

  return metric.values.count || 0;
}

function metricRate(data, name) {
  const metric = data.metrics[name];
  if (!metric || !metric.values) {
    return 0;
  }

  return metric.values.rate || 0;
}

function metricValue(data, name, key) {
  const metric = data.metrics[name];
  if (!metric || !metric.values) {
    return null;
  }

  return metric.values[key] ?? null;
}

export function handleSummary(data) {
  const path = __ENV.CLINIC_K6_SUMMARY || '/tmp/g-01-20-k6-raw.json';
  const summary = {
    gate: 'G-01-20',
    tool: 'k6',
    http_reqs: metricCount(data, 'http_reqs'),
    http_req_failed_rate: metricRate(data, 'http_req_failed'),
    dropped_iterations: metricCount(data, 'dropped_iterations'),
    unexpected_server_error_rate: metricRate(data, 'unexpected_server_error'),
    latency_ms: {
      p50: metricValue(data, 'http_req_duration', 'p(50)'),
      p95: metricValue(data, 'http_req_duration', 'p(95)'),
      p99: metricValue(data, 'http_req_duration', 'p(99)'),
      max: metricValue(data, 'http_req_duration', 'max'),
    },
    counts_429: {
      below_threshold: metricCount(data, 'below_threshold_429'),
      login_abuse: metricCount(data, 'login_abuse_429'),
      otp_request: metricCount(data, 'otp_request_429'),
      otp_resend: metricCount(data, 'otp_resend_429'),
      otp_verify: metricCount(data, 'otp_verify_429'),
      refresh_abuse: metricCount(data, 'refresh_abuse_429'),
      mfa_abuse: metricCount(data, 'mfa_abuse_429'),
      recovery_start: metricCount(data, 'recovery_start_429'),
      recovery_complete: metricCount(data, 'recovery_complete_429'),
    },
    retry_after_missing: metricCount(data, 'retry_after_missing'),
    thresholds: data.metrics
      ? Object.fromEntries(
          Object.entries(data.metrics)
            .filter(([, metric]) => metric.thresholds)
            .map(([name, metric]) => [name, metric.thresholds]),
        )
      : {},
  };

  return {
    [path]: `${JSON.stringify(summary, null, 2)}\n`,
  };
}
