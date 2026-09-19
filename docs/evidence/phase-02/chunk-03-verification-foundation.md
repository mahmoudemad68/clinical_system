# Phase 02 chunk 03 — Verification foundation (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** Verification module persistence and services for doctor
verification cases, document metadata/references, and append-only reviewer
decisions; narrow `DoctorApplicantService`; doctor own-status/submit HTTP;
compact submit outcome sized for the Platform 255-byte idempotency pointer;
versioned outbox events; architecture-boundary tests.

**Explicitly deferred:** secure upload/quarantine/malware scanner, Admin
verification UI/HTTP, Pharmacy verification, Clinics/locations/memberships,
approved document-requirement and rejection-reason catalogues, clinical
capability activation, public listing on approval, staging infrastructure,
and remaining Phase 02/03 work.

**SF-001** remains unresolved / unaccepted. `FEATURE_IDENTITY_PROFILE_CLAIM`
remains off. ADR 0014 and G-08-04 are not closed by this slice. Staging
`Deploy to staging` remains fail-closed; this chunk does not bypass it.

- **Branch:** `cursor/verification-foundation-cc7f`
- **Recorded:** 2026-09-19
- **Environment:** host PHP 8.3.6 with `pdo_pgsql`, database `clinic_test`.
  Gates below were executed on this host after the compact-submit revision.

## Commands actually executed

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` | **PASS** |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | **PASS** (0 errors) |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | **PASS** (0 violations, 0 uncovered, 1845 allowed) |
| `./vendor/bin/pest tests/Feature/Verification tests/Unit/Verification tests/Unit/Platform/ArchitectureBoundaryTest.php tests/Feature/Doctors tests/Unit/Doctors tests/Unit/Identity/IdentityRulesTest.php` | **91 passed** (2584 assertions) |
| `./vendor/bin/pest` (full Core suite) | **591 passed**, 14 skipped (10277 assertions; 605 tests) |
| `npm run contracts:lint` | **PASS** (`core@v1` valid) |
| `npm run contracts:events` | **PASS** (18 schemas) |
| `npm run contracts:ai-internal` | **PASS** (1 schema) |
| `npm run contracts:generate:ts` | **PASS** (committed client not stale) |
| `npm run contracts:breaking` | **PASS** (no breaking changes against `origin/main`) |
| `python3 scripts/ci/run-isr015-validators.py` | **PASS** (path-filters, license-gate, OpenVEX including gRPC S2, catalog S3/S4, Gitleaks NID static, SF-001, promotion isolation) |
| `php artisan module:list` | Verification enabled, priority 54 |

Gitleaks, Trivy image scans, and OpenVEX were **not** weakened. ISR-015 static
validators passed locally; container Gitleaks/Trivy remain CI jobs.

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- Document requirement catalogue and rejection-reason catalogue are
  `ENGINEERING_DEFAULT` (`professional_id`; `approved`, `evidence_incomplete`,
  `identity_mismatch`, `documents_illegible`). Unknown codes deny. Product and
  security owners have not approved a production policy catalogue.
- There is no HTTP upload, complete callback, or scanner worker. Documents
  become `available` only through the trusted in-process
  `VerificationDocumentService::registerValidatedMetadata` seam. A client
  cannot mark a document scanned or available.
- Admin decision HTTP and the verification work-queue UI are deferred.
  `recordDecision` / `claimCase` are testable at the service boundary.
- Pharmacy verification, Clinics, locations, memberships, and scheduling are
  out of scope.
- Approval does **not** list the doctor or grant clinical capabilities.
  Pending/rejected/suspended doctors remain without clinical privileges.
- `object_id` is an opaque UUIDv7, never a storage key. Raw object keys must
  not appear in events, logs, public DTOs, URLs, or analytics.
- Secure-files quarantine, malware scanning, magic-byte MIME enforcement on
  real bytes, and signed review URLs remain future work.
- Subject erasure of a doctor profile does not automatically purge verification
  cases in this slice.
- Staging remains unprovisioned; the post-merge deploy gate stays fail-closed.
