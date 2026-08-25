import http from 'k6/http';
import { check, group } from 'k6';
import { SLO, standardThresholds } from '../lib/thresholds.js';

/**
 * Phase 00 foundation smoke test.
 *
 * Exercises only what Phase 00 delivers: health, readiness, and version. It
 * proves the harness, the thresholds, and the reporting work end to end so that
 * the phases which add real workflows inherit a working setup rather than
 * building one under load-testing pressure.
 *
 * This carries no authentication and touches no patient data by construction.
 */

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

export const options = {
  scenarios: {
    // Steady low rate. Phase 00 is not a capacity test; 500 RPS sustained is a
    // Phase 21 exercise against a real environment.
    foundation: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RATE || 20),
      timeUnit: '1s',
      duration: __ENV.DURATION || '30s',
      preAllocatedVUs: 10,
      maxVUs: 50,
    },
  },
  thresholds: {
    ...standardThresholds(SLO.apiRead),
    // Readiness is allowed to be slower: it performs bounded dependency checks.
    'http_req_duration{endpoint:ready}': ['p(95)<500'],
  },
};

export default function () {
  group('operational probes', () => {
    const live = http.get(`${BASE_URL}/live`, { tags: { endpoint: 'live' } });

    check(live, {
      'live returns 200': (r) => r.status === 200,
      'live reports alive': (r) => r.json('status') === 'alive',
      // A liveness probe that touches dependencies restarts healthy processes
      // during a blip, so it must stay fast.
      'live is fast': (r) => r.timings.duration < 50,
    });

    const ready = http.get(`${BASE_URL}/ready`, { tags: { endpoint: 'ready' } });

    check(ready, {
      'ready responds': (r) => r.status === 200 || r.status === 503,
      'ready names its checks': (r) => Array.isArray(r.json('checks')),
      // Reconnaissance guard: the body must not name infrastructure.
      'ready leaks no host detail': (r) => {
        const body = r.body || '';
        return !['postgres:', '5432', 'redis:', '6379', 'password'].some((s) =>
          body.includes(s),
        );
      },
    });
  });

  group('public metadata', () => {
    const health = http.get(`${BASE_URL}/api/v1/health`, {
      headers: { 'Accept-Language': 'ar' },
      tags: { endpoint: 'health' },
    });

    check(health, {
      'health returns 200': (r) => r.status === 200,
      'health uses the envelope': (r) =>
        r.json('data') !== undefined && r.json('request_id') !== undefined,
      'health honours Accept-Language': (r) => r.json('meta.locale') === 'ar',
      'health echoes a correlation id': (r) =>
        (r.headers['X-Request-Id'] || '').length === 36,
    });

    const version = http.get(`${BASE_URL}/api/v1/meta/version`, {
      tags: { endpoint: 'version' },
    });

    check(version, {
      'version returns 200': (r) => r.status === 200,
      'version reports v1': (r) => r.json('data.api_version') === 'v1',
    });
  });
}
