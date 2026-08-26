import http from 'k6/http';
import { check, sleep } from 'k6';

/**
 * Phase 01 abuse harness. Synthetic destinations only. Bounded VUs.
 *
 * Does not store or print OTP codes, passwords, or National IDs.
 */
const BASE = __ENV.CLINIC_API_BASE_URL || 'http://localhost:8080';

export const options = {
  scenarios: {
    login_spray: {
      executor: 'constant-arrival-rate',
      rate: 20,
      timeUnit: '1s',
      duration: '30s',
      preAllocatedVUs: 10,
      maxVUs: 20,
    },
    otp_flood: {
      executor: 'constant-arrival-rate',
      rate: 10,
      timeUnit: '1s',
      duration: '30s',
      preAllocatedVUs: 5,
      maxVUs: 10,
      startTime: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.2'],
    checks: ['rate>0.9'],
  },
};

function envelopeOkOrSafeClientError(res) {
  return res.status === 401 || res.status === 404 || res.status === 422 || res.status === 429 || res.status === 201 || res.status === 200;
}

export default function () {
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'Idempotency-Key': `k6-${__VU}-${__ITER}-${Date.now()}`,
  };

  if (__ITER % 2 === 0) {
    const res = http.post(
      `${BASE}/api/v1/auth/login`,
      JSON.stringify({
        phone: '01900000000',
        password: 'not-a-real-password-12',
        client_class: 'patient_mobile',
        platform: 'android',
        device_label: 'k6',
      }),
      { headers },
    );
    check(res, { login_safe_status: envelopeOkOrSafeClientError });
  } else {
    const res = http.post(
      `${BASE}/api/v1/auth/otp-requests`,
      JSON.stringify({
        phone: '01900000000',
        purpose: 'registration',
        language: 'en',
      }),
      { headers },
    );
    check(res, { otp_safe_status: envelopeOkOrSafeClientError });
  }

  sleep(0.2);
}
