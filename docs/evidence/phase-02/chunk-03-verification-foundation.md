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
- **Recorded:** 2026-09-19
- **Environment:** host PHP 8.3.6 with `pdo_pgsql`, database `clinic_test`.
  Gates below are filled after the security-review remediation.

## Commands actually executed

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` | pending this commit |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | pending this commit |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | pending this commit |
| `./vendor/bin/pest` focused Verification/Doctors/Identity/Architecture | pending this commit |
| `./vendor/bin/pest` full Core suite | pending this commit |
| `npm run contracts:lint` | pending this commit |
| `npm run contracts:events` | pending this commit |
| `npm run contracts:breaking` | pending this commit |
| `python3 scripts/ci/run-isr015-validators.py` | pending this commit |

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- Document requirement catalogue and rejection-reason catalogue remain
  `ENGINEERING_DEFAULT` (`professional_id`; `approved`, `evidence_incomplete`,
  `identity_mismatch`, `documents_illegible`). Unknown codes deny. Product and
  security owners have not approved a production policy catalogue.
- Production cannot manufacture `AVAILABLE`/`CLEAN` evidence. The bound issuer
  is `DisabledTrustedDocumentEvidenceIssuer` (`ProviderNotEnabled`). A doctor
  or admin `ActorContext` is not scanner trust. Tests use
  `TestingTrustedDocumentEvidenceIssuer` only as an explicit test fixture.
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

