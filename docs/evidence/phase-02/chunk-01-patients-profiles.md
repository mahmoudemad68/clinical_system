# Phase 02 chunk 01 — Patients profiles (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete.
Doctors, pharmacies, clinics, verification, Flutter/Electron onboarding,
PostGIS locations, and scanner work are out of scope.

**SF-001** remains unresolved / unaccepted. `FEATURE_IDENTITY_PROFILE_CLAIM`
remains off in defaults, phpunit, and environment files.

- **Branch:** `phase-02-patients-profiles`
- **Original chunk commit:** `06bb607f44d167043b63cffe2d5be117b4fa24cf`
- **Remediation baseline:** that SHA (new commit; not amended)
- **Recorded:** 2026-09-01
- **Environment:** host PHP has no `pdo_pgsql`. Pest, PHPStan, and Deptrac ran
  in `clinic-php-pgsql:local` on Docker network `clinic_default` with
  `DB_HOST=postgres`, database `clinic_test`. Compose `postgres` + `redis`
  were already healthy.

## Remediation commands actually executed

| Command | Result |
| --- | --- |
| Host `./vendor/bin/pint --test` on remediation PHP paths | `{"tool":"pint","result":"passed"}` |
| Docker `./vendor/bin/phpstan analyse --memory-limit=1G` | `[OK] No errors` (305 files) |
| Docker `./vendor/bin/deptrac analyse --report-uncovered --fail-on-uncovered` | 0 violations, 0 uncovered, 1533 allowed |
| Docker `./vendor/bin/pest tests/Feature/Patients tests/Unit/Platform/ArchitectureBoundaryTest.php tests/Unit/Identity/IdentityRulesTest.php` | **41 passed** (498 assertions) |
| Docker `./vendor/bin/pest tests/Feature/Identity tests/Unit/Identity` | Identity feature + unit **passed** in the combined Identity/Auth run (Auth later re-run isolated) |
| Docker `./vendor/bin/pest tests/Feature/Auth/AuthenticationFlowsTest.php tests/Feature/Access` | **23 passed** (152 assertions) |
| `npm run contracts:verify` | OpenAPI valid; **15 event schemas**; TS + Dart generated (`PatientOnboardingResult` compact) |

Patients feature subset from the 41-test run:

- `PatientPostgresPrivilegeTest` — 2 passed
- `PatientProfileFlowsTest` — 14 passed
- `PatientProfileRaceTest` — 4 passed (includes two-user same-NID concurrent create)

Auth note: `AuthenticationFlowsTest` **22 passed** when run without
`AuditChainConcurrentAppendTest` in the same process. A combined
Identity-then-Auth run can leave one committed `users` row because that
audit race uses `CommittedDatabaseTestCase` and excepts `audit_events` from
truncation. That interaction is unchanged Phase 01 test hygiene, not a
Patients onboarding regression.

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- Subject-erasure tombstone leaves `full_name` ciphertext in append-only
  `patient_demographic_revisions`.
- Unlinked walk-in profiles are not selected by user-id erasure.
- `identity_profile_links` is still unused; ownership is `patient_profiles.user_id`.
- Claim never auto-attaches (Phase 01 ceremony preserved; flag off).
- Height/weight bounds are named `ENGINEERING_DEFAULT`, not clinical protocol.
- No Flutter / Electron / admin UI in this chunk.
- Unlinked create/resolve stay default-denied until Phase 03 grants exist.
- Exercised National-ID canary sinks: HTTP body, outbox payload, audit
  metadata, Monolog `TestHandler` on the default log channel, and
  `PlatformMetrics::render()`. Other sinks were not claimed.
