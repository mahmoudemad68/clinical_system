/**
 * SLO thresholds from plan.md section 132.
 *
 * Centralized so every scenario asserts the same numbers, and so changing a
 * target is one reviewable edit rather than a search across scenario files.
 *
 * These are acceptance targets the platform must be *tested* against. Phase 21
 * owns the conclusion about whether they are met under real load.
 */

export const SLO = {
  apiRead: 250,
  apiWrite: 400,
  profile: 250,
  availability: 300,
  medicineSearch: 300,
  medicineGeoSearch: 500,
  prescriptionRead: 300,
  queueRealtime: 1000,
  startConsultation: 300,
  posSale: 400,
  ragRetrieval: 700,
};

/**
 * Standard thresholds for a scenario.
 *
 * The error-rate ceiling is deliberately low. A 1% failure rate on a booking
 * endpoint is one patient in a hundred unable to book, which is not a rounding
 * error.
 */
export function standardThresholds(p95Ms, { errorRate = 0.01 } = {}) {
  return {
    http_req_duration: [`p(95)<${p95Ms}`],
    http_req_failed: [`rate<${errorRate}`],
    // A single check failure means a response was structurally wrong, which
    // matters regardless of how fast it arrived.
    checks: ['rate>0.99'],
  };
}
