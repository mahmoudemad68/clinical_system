import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

/**
 * Phase 01 abuse harness. Synthetic destinations only. Bounded VUs.
 *
 * Does not store or print OTP codes, passwords, or National IDs.
 * 401/403/404/422/429 are expected. 5xx is a failure.
 */
const BASE = __ENV.CLINIC_API_BASE_URL || 'http://127.0.0.1:8080';
const unexpectedServer = new Rate('unexpected_server_error');

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 499 }));

export const options = {
  scenarios: {
    login_spray: {
      executor: 'constant-arrival-rate',
      exec: 'loginSpray',
      rate: 20,
      timeUnit: '1s',
      duration: '30s',
      preAllocatedVUs: 10,
      maxVUs: 20,
    },
    otp_flood: {
      executor: 'constant-arrival-rate',
      exec: 'otpFlood',
      rate: 10,
      timeUnit: '1s',
      duration: '30s',
      preAllocatedVUs: 5,
      maxVUs: 10,
      startTime: '30s',
    },
    refresh_reuse: {
      executor: 'constant-arrival-rate',
      exec: 'refreshReuse',
      rate: 10,
      timeUnit: '1s',
      duration: '20s',
      preAllocatedVUs: 5,
      maxVUs: 10,
      startTime: '60s',
    },
  },
  thresholds: {
    unexpected_server_error: ['rate==0'],
    checks: ['rate>0.95'],
  },
};

function headers() {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'Idempotency-Key': `k6-${__VU}-${__ITER}-${Date.now()}`,
  };
}

function isExpectedAuthFailure(status) {
  return status === 401 || status === 403 || status === 404 || status === 422 || status === 429;
}

export function loginSpray() {
  const res = http.post(
    `${BASE}/api/v1/auth/login`,
    JSON.stringify({
      phone: '01900000000',
      password: 'not-a-real-password-12',
      client_class: 'patient_mobile',
      platform: 'android',
      device_label: 'k6',
    }),
    { headers: headers() },
  );
  unexpectedServer.add(res.status >= 500);
  check(res, { login_denied_or_limited: (r) => isExpectedAuthFailure(r.status) });
  sleep(0.1);
}

export function otpFlood() {
  const res = http.post(
    `${BASE}/api/v1/auth/otp-requests`,
    JSON.stringify({
      phone: '01900000000',
      purpose: 'registration',
      language: 'en',
    }),
    { headers: headers() },
  );
  unexpectedServer.add(res.status >= 500);
  check(res, {
    otp_denied_limited_or_accepted: (r) => isExpectedAuthFailure(r.status) || r.status === 200 || r.status === 201,
  });
  sleep(0.1);
}

export function refreshReuse() {
  const res = http.post(
    `${BASE}/api/v1/auth/token/refresh`,
    JSON.stringify({ refresh_token: 'k6-not-a-real-refresh-token' }),
    { headers: headers() },
  );
  unexpectedServer.add(res.status >= 500);
  check(res, { refresh_denied_or_limited: (r) => isExpectedAuthFailure(r.status) });
  sleep(0.1);
}
