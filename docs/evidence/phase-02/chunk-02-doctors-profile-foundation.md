# Phase 02 chunk 02 — Doctors profile foundation (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete.
Verification documents/cases/decisions, pharmacy onboarding, clinics,
locations, schedules, appointments, Electron/UI, and clinical capability
activation remain out of scope.

**SF-001** remains unresolved / unaccepted. `FEATURE_IDENTITY_PROFILE_CLAIM`
remains off. ADR 0014 and G-08-04 are not closed by this slice.

- **Branch:** `cursor/doctors-profile-foundation-cc7f`
- **Original chunk commit:** `3d9b78dbe68a313d83001d6b0b268272833f0dfb`
- **Post-test evidence commit:** `22bda859697b9b7196382cc2308cd7c905a6ce8b`
- **Recorded:** 2026-09-19
- **Environment:** host PHP 8.3.6 with `pdo_pgsql`, apt PostgreSQL 16,
  database `clinic_test`, role `clinic_migrator` (superuser for local
  migrate/tests, matching CI's image user). No Docker Compose. Redis/MinIO
  were not provisioned; related existing skips remain.

## Commands actually executed

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations, 0 uncovered, **1655** allowed |
| `php artisan migrate --database=pgsql_migrator --force` | applied through `2026_09_19_140000_create_doctor_profile_tables` |
| `./vendor/bin/pest tests/Feature/Doctors tests/Unit/Doctors tests/Unit/Platform/ArchitectureBoundaryTest.php tests/Unit/Identity/IdentityRulesTest.php` | **50 passed** (1578 assertions) |
| `./vendor/bin/pest tests/Feature/Patients tests/Feature/Identity tests/Feature/Access tests/Unit/Identity tests/Unit/Platform tests/Feature/Platform` | **407 passed**, 2 skipped (7523 assertions) |
| `./vendor/bin/pest` (full Core suite) | **550 passed**, 14 skipped (9271 assertions) |
| `npm run contracts:lint` | OpenAPI valid |
| `npm run contracts:events` | **16** event schemas checked |
| `npm run contracts:generate:ts` | generated client matches commit (`TS_CLIENT_FRESH`) |
| `npm run contracts:breaking` | no breaking changes against `origin/main` |
| `python3 scripts/ci/run-isr015-validators.py` | **PASS** (path-filters, license-gate, OpenVEX, Gitleaks NID static, SF-001, promotion isolation) |

Doctors-focused subset from the 50-test run:

- `DoctorProfileFlowsTest` — onboarding, own-profile, BOLA, mass assignment, idempotent replay/conflict, collision non-disclosure, bound-identity mismatch, erasure tombstone
- `DoctorProfileRaceTest` — same-user concurrent create; two-user same National ID; two-user same syndicate
- `DoctorPostgresPrivilegeTest` — worker/reporter denied; `clinic_app` DML; backup SELECT
- `DoctorProfileInvariantsTest` — verification status never confers clinical capability; syndicate trim-only
- `ArchitectureBoundaryTest` — Doctors/foreign persistence, Platform generic, Verification not leaked into Doctors

Gitleaks, Trivy image scans, and OpenVEX were **not** weakened. ISR-015 static validators passed locally; container Gitleaks/Trivy remain CI jobs.

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- Specialty catalogue is empty until an approved medical-specialty reference dataset exists.
- Public registration remains Patient-only; doctor test actors are provisioned with TOTP `doctor_desktop` login.
- Verification upload, cases, documents, decisions, reviewer assignment, and `POST /doctors/me/verification-submissions` are not implemented.
- `identity:rotate-keys` still rotates Identity/Auth protected columns; doctor National ID / syndicate envelopes are a deferred follow-on (same class of residual as patient `full_name`).
- Creating a doctor profile does not list the doctor or grant clinical capabilities.
- Syndicate identifiers are trimmed only; no syndicate checksum or professional-policy algorithm (none is specified in repository policy).
- Egyptian National ID checksum remains ADR 0014 / synthetic-test policy; this slice does not invent one.
- Exercised National-ID and syndicate canary sinks: HTTP body, outbox payload, audit metadata, Monolog `TestHandler` behind `RedactingLogTap` for National ID, in-process HTTP spans, and `PlatformMetrics::render()`. Syndicate is **not** added to Platform `PatternRedactor` (architecture test forbids Platform doctor business vocabulary).
