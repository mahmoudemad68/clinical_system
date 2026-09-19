# Phase 02 chunk 03 — Verification foundation (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** Verification module persistence and services for doctor
verification cases, document metadata/references, and append-only reviewer
decisions; narrow `DoctorApplicantService`; doctor own-status/submit HTTP;
compact submit outcome sized for the Platform 255-byte idempotency pointer;
fail-closed trusted document evidence issuer; PostgreSQL freeze of submitted
document snapshots; assignment-gated reviewer evidence access; versioned
outbox events; architecture-boundary tests.

**Explicitly deferred:** secure upload/quarantine/malware scanner, Admin
verification UI/HTTP, Pharmacy verification, Clinics/locations/memberships,
approved document-requirement and rejection-reason catalogues, clinical
capability activation, public listing on approval, staging infrastructure,
and remaining Phase 02/03 work.

**SF-001** remains unresolved / unaccepted. `FEATURE_IDENTITY_PROFILE_CLAIM`
remains off. ADR 0014 and G-08-04 are not closed by this slice. Staging
`Deploy to staging` remains fail-closed; this chunk does not bypass it.

- **Branch:** `cursor/verification-foundation-cc7f`
- **Reviewed HEAD before remediation:** `cdb5e5948535b7444b5cc86ffa6bb3a7898f5dcd`
- **Trusted-boundary / freeze / reviewer remediation:** `f4a0cb304ce64e21e1abc770bc8d3592b41af71d`
- **Applicant-attribution remediation:** `6b12578423221ef61b3aadeff4ecd19013794b70`
- **Recorded:** 2026-09-19
- **Environment:** host PHP 8.3.6 with `pdo_pgsql`, database `clinic_test`.
  Gates below were re-run after the applicant-attribution remediation.

## Commands actually executed

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations, 0 uncovered, **1870** allowed |
| `./vendor/bin/pest tests/Feature/Verification tests/Unit/Verification tests/Unit/Platform/ArchitectureBoundaryTest.php` | **64 passed** (2328 assertions) |
| `./vendor/bin/pest` (full Core suite) | **604 passed**, 14 skipped, 618 tests (10433 assertions) |
| `npm run contracts:lint` | OpenAPI valid |
| `npm run contracts:events` | **18** event schemas checked |
| `npm run contracts:breaking` | no breaking changes against `origin/main` |
| `python3 scripts/ci/run-isr015-validators.py` | **PASS** (path-filters, license-gate, OpenVEX including gRPC S2, catalog S3/S4, Gitleaks NID static, SF-001, promotion isolation) |

Phase 02 as a whole is **not** PASS. GitHub PR CI is recorded on the evidence
commit that follows this local run; this file does not claim production
approval.

## Residual (this chunk)

- Document requirement catalogue and rejection-reason catalogue remain
  `ENGINEERING_DEFAULT` (`professional_id`; `approved`, `evidence_incomplete`,
  `identity_mismatch`, `documents_illegible`). Unknown codes deny. Product and
  security owners have not approved a production policy catalogue.
- Production cannot manufacture `AVAILABLE`/`CLEAN` evidence. The bound issuer
  is `DisabledTrustedDocumentEvidenceIssuer` (`ProviderNotEnabled`). A doctor
  or admin `ActorContext` is not scanner trust. Tests use
  `TestingTrustedDocumentEvidenceIssuer` only as an explicit test fixture.
- Document-registration audit attribution is derived from the case applicant
  through `DoctorApplicantService`. Callers cannot supply an applicant user ID.
- Secure upload, quarantine, malware scanning, magic-byte MIME enforcement on
  real bytes, and signed review URLs remain deferred. There is no HTTP upload.
- Document content identity is immutable. After the parent case leaves `draft`,
  PostgreSQL rejects INSERT/UPDATE/DELETE on `verification_documents`.
- Reviewer detailed evidence access is assignment-gated: claim, then inspect
  only `AVAILABLE`+`CLEAN` metadata, then decide. Unassigned privileged
  reviewers do not receive document evidence.
- `recordDecision` requires the currently assigned reviewer. Reviewer identity
  is server-derived.
- Admin decision HTTP and the verification work-queue UI remain deferred.
- Approval does **not** list the doctor or grant clinical capabilities.
- `object_id` is an opaque UUIDv7, never a storage key.
- Subject erasure of a doctor profile does not automatically purge verification
  cases in this slice.
- Staging remains unprovisioned; the post-merge deploy gate stays fail-closed.
- Independent retest of this applicant-attribution change remains outstanding.
  This implementer commit cannot close that finding.
