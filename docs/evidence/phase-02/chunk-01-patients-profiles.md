# Phase 02 chunk 01 — Patients profiles (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete.
Doctors, pharmacies, clinics, verification, Flutter/Electron onboarding,
PostGIS locations, and scanner work are out of scope.

**SF-001** remains unresolved / unaccepted. `FEATURE_IDENTITY_PROFILE_CLAIM`
remains off in defaults, phpunit, and environment files.

- **Branch:** `phase-02-patients-profiles`
- **Baseline:** `dd7854be54b79638a7638f5127235126b319f11a`
- **Recorded:** 2026-09-01
- **Environment:** host PHP has no `pdo_pgsql`. Pest ran in `clinic-php-pgsql:local`
  on Docker network `clinic_default` with `DB_HOST=postgres`, database
  `clinic_test`. Compose `postgres` + `redis` were already healthy.

## Commands actually executed

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` (host, `apps/core-api`) | passed |
| Docker `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `[OK] No errors` |
| Docker `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations, 0 uncovered, 1492 allowed |
| Docker `./vendor/bin/pest tests/Feature/Patients` | **18 passed** (including 3 PostgreSQL two-process races + 2 privilege tests) |
| Docker `./vendor/bin/pest tests/Unit tests/Feature/Patients tests/Feature/Identity tests/Feature/Access` | **318 passed** (5833 assertions) on an earlier Patients 16-test slice; privilege tests added after this run and passed separately (2 passed) |
| Docker `./vendor/bin/pest tests/Feature/Auth/AuthenticationFlowsTest.php` | **22 passed** (143 assertions) |
| `npm run contracts:verify` | OpenAPI valid (0 warnings after bound cleanup); **15 event schemas**; TS + Dart generated (`onboardPatientProfile`, `getOwnPatientProfile`, `updateOwnPatientDemographics`) |

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- Subject-erasure tombstone leaves `full_name` ciphertext in append-only
  `patient_demographic_revisions`.
- Unlinked walk-in profiles are not selected by user-id erasure.
- `identity_profile_links` is still unused; ownership is `patient_profiles.user_id`.
- Claim never auto-attaches (Phase 01 ceremony preserved; flag off).
- Height/weight bounds are named `ENGINEERING_DEFAULT`, not clinical protocol.
- No Flutter / Electron / admin UI in this chunk.
