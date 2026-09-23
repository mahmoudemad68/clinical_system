import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';
import { Trend, Counter, Rate } from 'k6/metrics';

const BASE = __ENV.CLINIC_API_BASE_URL || 'http://127.0.0.1:18090';
const ACTORS_PATH = __ENV.CLINIC_P02_ACTORS_FILE || '/workspace/tmp/phase-02-perf/actors.json';
const RATE = Number(__ENV.CLINIC_P02_RATE || '250');
const DURATION = __ENV.CLINIC_P02_DURATION || '90s';
const PRE_VUS = Number(__ENV.CLINIC_P02_PRE_VUS || '300');
const MAX_VUS = Number(__ENV.CLINIC_P02_MAX_VUS || '1500');

const actors = new SharedArray('actors', () => [JSON.parse(open(ACTORS_PATH))]);

const patientRead = new Trend('op_patient_profile_read', true);
const doctorRead = new Trend('op_doctor_profile_read', true);
const clinicRead = new Trend('op_clinic_locations_read', true);
const pharmacyOrgRead = new Trend('op_pharmacy_org_read', true);
const pharmacyBranchRead = new Trend('op_pharmacy_branches_read', true);
const verificationRead = new Trend('op_verification_read', true);
const patientWrite = new Trend('op_patient_demographic_update', true);
const doctorWrite = new Trend('op_doctor_onboarding_status', true);
const clinicWrite = new Trend('op_clinic_location_update', true);
const membershipWrite = new Trend('op_membership_operations', true);
const unexpected = new Rate('unexpected_request_failure');
const expectedConflict = new Counter('expected_conflict_409');
const status2xx = new Counter('http_2xx');
const status4xx = new Counter('http_4xx');
const status5xx = new Counter('http_5xx');

export const options = {
  insecureSkipTLSVerify: true,
  discardResponseBodies: false,
  summaryTrendStats: ['min', 'med', 'avg', 'p(90)', 'p(95)', 'p(99)', 'max'],
  scenarios: {
    dataset_v1: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: PRE_VUS,
      maxVUs: MAX_VUS,
    },
  },
  thresholds: {
    unexpected_request_failure: ['rate<1'],
  },
};

const WEIGHTS = [
  [20, 'patient_read'],
  [15, 'doctor_read'],
  [13, 'clinic_read'],
  [10, 'pharmacy_org_read'],
  [10, 'pharmacy_branch_read'],
  [7, 'verification_read'],
  [8, 'patient_write'],
  [6, 'doctor_write'],
  [5, 'clinic_write'],
  [6, 'membership_write'],
];

function pickOp() {
  const slot = Math.random() * 100;
  let acc = 0;
  for (const [weight, name] of WEIGHTS) {
    acc += weight;
    if (slot < acc) {
      return name;
    }
  }
  return 'patient_read';
}

function auth(token) {
  return {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    tags: {},
    timeout: '30s',
  };
}

function idempotencyKey() {
  return `k6-${__VU}-${__ITER}-${Date.now()}-${Math.floor(Math.random() * 1e9)}`;
}

function idem(headers) {
  return Object.assign({}, headers, {
    headers: Object.assign({}, headers.headers, {
      'Idempotency-Key': idempotencyKey(),
    }),
  });
}

function record(metric, res, okStatuses) {
  metric.add(res.timings.duration);
  const code = res.status;
  if (code >= 200 && code < 300) {
    status2xx.add(1);
  } else if (code >= 400 && code < 500) {
    status4xx.add(1);
  } else if (code >= 500) {
    status5xx.add(1);
  }
  if (code === 409) {
    expectedConflict.add(1);
  }
  const ok = okStatuses.indexOf(code) !== -1;
  unexpected.add(ok ? 0 : 1);
  check(res, {
    'expected status': (r) => okStatuses.indexOf(r.status) !== -1,
  });
  return ok;
}

function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

export default function () {
  const bundle = actors[0];
  const op = pickOp();

  if (op === 'patient_read') {
    const actor = pick(bundle.patients);
    const res = http.get(`${BASE}/api/v1/patients/me/profile`, auth(actor.token));
    record(patientRead, res, [200]);
    return;
  }

  if (op === 'doctor_read') {
    const actor = pick(bundle.doctors_approved);
    const res = http.get(`${BASE}/api/v1/doctors/me/profile`, auth(actor.token));
    record(doctorRead, res, [200]);
    return;
  }

  if (op === 'clinic_read') {
    const actor = pick(bundle.doctors_approved);
    const res = http.get(`${BASE}/api/v1/clinic-locations`, auth(actor.token));
    record(clinicRead, res, [200]);
    return;
  }

  if (op === 'pharmacy_org_read') {
    const actor = pick(bundle.pharmacies);
    const res = http.get(`${BASE}/api/v1/pharmacy-organizations/me`, auth(actor.token));
    record(pharmacyOrgRead, res, [200]);
    return;
  }

  if (op === 'pharmacy_branch_read') {
    const actor = pick(bundle.pharmacies);
    const res = http.get(
      `${BASE}/api/v1/pharmacy-organizations/${actor.organization_id}/branches`,
      auth(actor.token),
    );
    record(pharmacyBranchRead, res, [200]);
    return;
  }

  if (op === 'verification_read') {
    if (Math.random() < 0.45) {
      const admin = pick(bundle.admins);
      const res = http.get(`${BASE}/api/v1/admin/verification-cases`, auth(admin.token));
      record(verificationRead, res, [200]);
      return;
    }
    const pending = pick(bundle.doctors_pending);
    const res = http.get(`${BASE}/api/v1/doctors/me/verification-status`, auth(pending.token));
    record(verificationRead, res, [200]);
    return;
  }

  if (op === 'patient_write') {
    const actor = pick(bundle.patients);
    const params = auth(actor.token);
    const res = http.patch(
      `${BASE}/api/v1/patients/me/demographics`,
      JSON.stringify({
        version: actor.version || 1,
        marital_status: Math.random() < 0.5 ? 'single' : 'married',
      }),
      params,
    );
    record(patientWrite, res, [200, 409]);
    return;
  }

  if (op === 'doctor_write') {
    if (Math.random() < 0.5) {
      const actor = pick(bundle.doctors_onboard);
      const res = http.post(
        `${BASE}/api/v1/doctors/onboarding`,
        JSON.stringify({
          national_id: actor.national_id,
          professional_display_name: 'Synthetic Doctor Load',
          specialty_id: actor.specialty_id,
        }),
        idem(auth(actor.token)),
      );
      record(doctorWrite, res, [200, 201]);
      return;
    }
    const actor = pick(bundle.doctors_approved);
    const res = http.post(
      `${BASE}/api/v1/doctors/onboarding`,
      JSON.stringify({
        national_id: actor.national_id,
        professional_display_name: 'Synthetic Doctor Load',
        specialty_id: actor.specialty_id,
      }),
      idem(auth(actor.token)),
    );
    record(doctorWrite, res, [200, 201]);
    return;
  }

  if (op === 'clinic_write') {
    const actor = pick(bundle.doctors_approved);
    const res = http.patch(
      `${BASE}/api/v1/clinic-locations/${actor.location_id}`,
      JSON.stringify({
        expected_version: actor.location_version || 1,
        public_name: `Clinic ${actor.location_id.slice(0, 8)}`,
      }),
      auth(actor.token),
    );
    record(clinicWrite, res, [200, 409]);
    return;
  }

  if (op === 'membership_write') {
    const actor = pick(bundle.doctors_approved);
    const suffix = String((__VU * 100000 + __ITER) % 10000000).padStart(7, '0');
    const res = http.post(
      `${BASE}/api/v1/clinic-locations/${actor.location_id}/staff-invitations`,
      JSON.stringify({ phone: `0198${suffix}` }),
      idem(auth(actor.token)),
    );
    record(membershipWrite, res, [200, 201]);
  }
}

export function handleSummary(data) {
  return {
    stdout: textSummary(data),
  };
}

function textSummary(data) {
  const m = data.metrics || {};
  const val = (name, key) => {
    const metric = m[name];
    if (!metric) {
      return null;
    }
    const values = metric.values || metric;
    if (key === 'p(50)' && (values['p(50)'] === undefined || values['p(50)'] === null)) {
      return values.med;
    }
    if (key === 'rate' && (values.rate === undefined || values.rate === null)) {
      return values.value;
    }
    return values[key];
  };
  const lines = [
    `http_reqs=${val('http_reqs', 'count')}`,
    `http_req_rate=${val('http_reqs', 'rate')}`,
    `dropped_iterations=${val('dropped_iterations', 'count')}`,
    `unexpected_request_failure=${val('unexpected_request_failure', 'rate')}`,
    `http_req_failed=${val('http_req_failed', 'rate')}`,
    `p50=${val('http_req_duration', 'p(50)')}`,
    `p95=${val('http_req_duration', 'p(95)')}`,
    `p99=${val('http_req_duration', 'p(99)')}`,
  ];
  return lines.join('\n') + '\n';
}
