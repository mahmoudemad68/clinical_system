# Phase 02 chunk 05 — Admin verification review backend (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** privileged Admin HTTP for the existing doctor
verification workflow: pending-review queue, claim, reviewer-safe case
detail, purpose-bound canonical document-access grant, and
approve/reject/changes_requested decisions. Admin is a transport facade.
Verification remains the owner of cases, documents, decisions, assignment,
and review authorization. Doctors remains the owner of the reviewer-safe
professional projection. Applicant HTTP is unchanged: safe result/reason
only.

**Explicitly deferred:** React Admin verification UI, Electron doctor UX
changes, Pharmacy organization verification, Pharmacy branches,
Clinics/locations, memberships, doctor public listing, clinical capability
activation, scheduling / Phase 03, admin-created doctor bootstrap,
generic document browser, bulk export, bulk decision, reviewer
reassignment UI, SLA escalation.

**SF-001** remains unresolved / unaccepted (`MERGE_ONLY`,
`promotion_allowed=false`). `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.
National-ID checksum remains an open decision. Staging `Deploy to staging`
remains fail-closed. This chunk does not bypass those controls.

- **Branch:** `cursor/admin-verification-review-backend-cc7f`
- **Base (GitHub `main`):** `722bca7e76f6e9565086da0c9709aad4ba57eaed`
- **Recorded:** 2026-09-20

## Boundaries

```
Admin HTTP (cookie + CSRF + AAL2 + verification.case.review)
  -> AdminVerificationReviewService (transport mapping, signed queue cursors)
    -> VerificationService / VerificationDocumentService
      -> Doctors DoctorReviewerService (safe professional fields only)
      -> PostgresVerificationStore (Verification-owned tables only)
```

Admin PHP must not query `verification_*`, `doctor_profiles`, Patients, or
any clinical table. Architecture tests and Deptrac encode that.

There is **one** authoritative business event: Verification-owned
`doctor.verification_decided`. Admin does not emit `admin.verification_decided`.

## Queue

- Filters (closed): `assignment=unassigned|mine|all` (default `unassigned`),
  `case_type=doctor_verification`, `status=pending_review`.
- Order: `submitted_at ASC`, `id ASC` (oldest submitted first).
- Pagination: HMAC-signed `CursorScope` bound to operation
  `admin.verification_cases.list`, reviewer user id, filters, and ordering.
  Reviewer-A cursor fails for reviewer B. Filter-X cursor fails for filter Y.
  Tamper / malformed / oversized / version mismatch fail closed as
  `422 CURSOR_INVALID`.
- Default limit 25, maximum 100 (`ENGINEERING_DEFAULT`).
- No offset pagination and no total-count query.
- Index: new forward migration
  `verification_cases_reviewer_queue_keyset_index`
  on `(case_type, status, submitted_at, id)`.

Queue projection: case id/type/status/version, submitted_at, assignment,
assigned_to_me, doctor_id, professional_display_name, specialty
(id/code/ar/en), doctor verification/public status, profile_version.
No National ID, syndicate, phone, email, address, document ids/hashes,
reviewer notes, or clinical fields.

## Claim

Closed body: `expected_case_version` only. Reviewer identity is
server-derived. Only `pending_review` may be claimed. Self-review denies.
Unassigned claim assigns and increments version. Same-reviewer replay is
safe and does not bump version or emit a second claim audit. Another
reviewer's assignment is `409`. Stale version on an unassigned case is
`409 VERSION_CONFLICT`. Claim does not change applicant status.
PostgreSQL `lockCase` plus version compare-and-set: concurrent claims
yield exactly one assignee.

## Case detail

Privileged reviewers may read a minimum safe summary before claim.
Documents are empty until the current reviewer is assigned. Assigned
detail includes only AVAILABLE+CLEAN metadata (optional SHA-256). Another
reviewer's documents are omitted. Guessed UUIDs are `404`. Locators and
signed URLs are never included.

## Document access

`POST /api/v1/admin/verification-cases/{case_id}/documents/{document_id}/access`

Preconditions: privileged assigned reviewer, case `pending_review`,
document belongs to that case, AVAILABLE+CLEAN, `upload_intent_id`
present, intent AVAILABLE, canonical locator present. Resolves
canonical `trustedRef` only. Never ingress. Never request-supplied
locators.

Returns: `document_id`, short-lived signed GET `url`, `expires_at`,
`detected_mime`, `size_bytes`. TTL ENGINEERING_DEFAULT 120 seconds,
hard-capped 1–300.

**Bearer-URL limitation:** the signed URL is not actor-bound after
issuance. Security relies on reviewer authorization before issuance,
exact case/document binding, canonical-only object, short expiry,
private bucket, no listing, no URL logging, and audited issuance.

The URL is never written to audit metadata, logs, metrics, events,
PostgreSQL, or idempotency replay. The endpoint is not
idempotency-stored.

Successful grants audit `verification.document_access_granted` with
reviewer actor, case object, document id, requirement_code,
`verification_review_access`, and assurance level.

## Decision

`POST /api/v1/admin/verification-cases/{case_id}/decisions` uses platform
idempotency. Closed body: `decision`, `reason_code`,
`expected_case_version`, optional bounded notes.

Allowed decisions: `approved`, `rejected`, `changes_requested`. Reason
codes are VerificationPolicy-only. Notes are encrypted as
`verification_reviewer_note`, never returned to the applicant, and never
copied into events/logs/metrics.

`VerificationService::recordDecision` remains the source of truth. Admin
does not write decision tables.

Atomic outcomes:

| Decision | Case status | Doctor verification_status |
| --- | --- | --- |
| approved | approved | approved |
| rejected | rejected | rejected |
| changes_requested | changes_requested | changes_requested |

Approval does **not** set `public_status=listed`, grant clinical
capability, or create clinic/schedule/appointment capability. Doctor
remains `hidden`.

Same Idempotency-Key + same body replays. Same key + changed body is
`409 IDEMPOTENCY_KEY_REUSED`. Compact result is sized for the 255-byte
pointer. Historical decided cases are read-only (no reopen). A later
applicant submission creates a new case.

## Authorization (BOLA/BFLA)

Every Admin verification route requires authenticated `ActorContext`,
active Admin, privileged MFA (AAL2), `Capabilities::VERIFICATION_REVIEW`,
and no `password_must_change`. Low-assurance Admin, Doctor, Patient,
unauthenticated, unknown capability, and CSRF-missing cookie POSTs deny.
Privileged denials use `RecordPrivilegedFailure`. Authorization denials
and missing records are indistinguishable `404`.

## Residual (this chunk)

- Document requirement and reason catalogues remain ENGINEERING_DEFAULT.
- Reviewer document TTL 120s is ENGINEERING_DEFAULT (cap 300s).
- Queue page size 25/100 is ENGINEERING_DEFAULT.
- Signed GET URLs are bearer-style while valid; they are not actor-bound
  after issuance.
- Document-access audit is a second transaction after URL issuance. If
  that audit insert fails, the HTTP request fails; a minted URL may still
  exist until TTL. Residual, short-lived.
- Approval still does not list the doctor or grant clinical capabilities.
- React Admin verification UI remains deferred.
- SF-001 remains MERGE_ONLY / `promotion_allowed=false`.
- Staging remains unprovisioned; post-merge deploy stays fail-closed.
- Phase 02 as a whole is **not** PASS.

## Commands actually executed

Local host results on this revision. GitHub `pull-request` on the same HEAD is
the scanner-of-record for Gitleaks, Semgrep, Trivy FS, runtime image scans,
and live MinIO/clamd. This implementer revision does not claim production
approval.

| Gate | Result |
| --- | --- |
| Pint `--test` | PASS |
| PHPStan `--memory-limit=1G` | PASS (`[OK] No errors`) |
| Deptrac `--fail-on-uncovered` | PASS (0 violations, 0 uncovered, 2187 allowed) |
| Focused Admin + Verification race + architecture Pest | 45 passed, 1 skipped (live MinIO grant), 2807 assertions |
| Full Core API `./vendor/bin/pest` | **664 passed**, 18 skipped, 682 tests, 11821 assertions |
| OpenAPI lint | valid (`core@v1`) |
| Event schemas | **19** checked |
| Generated TypeScript freshness | PASS (`git diff --quiet` after `contracts:generate:ts`) |
| Breaking contracts vs `origin/main` | none |
| AI internal schemas | 1 schema checked |
| ISR-015 | PASS (SF-001 remains MERGE_ONLY / fail-closed) |
| Admin web lint / typecheck / vitest / build | PASS (5 tests) |
| Desktop typecheck + vitest | PASS (doctor 83, pharmacy 83) |
| Shared TypeScript package tests | PASS |
| Gitleaks / Semgrep / Trivy FS / image scans | **not run locally** — this environment has no `docker`, `gitleaks`, `semgrep`, or `trivy` binaries. GitHub `security` / `image-scan` / `secure-file-providers` jobs are required on this HEAD. |
| Live MinIO document-access grant | **skipped locally** (`CLINIC_REQUIRE_OBJECT_STORE` unset; TCP `:9000` closed). CI `secure-file-providers` now runs `tests/Feature/Admin/AdminVerificationDocumentAccessProviderTest.php` with `CLINIC_REQUIRE_OBJECT_STORE=1`. |

Core API's 18 skips: one new live-MinIO reviewer grant, plus pre-existing
Auth Redis/Reverb/Octane/two-connection and live-provider tests that this job
does not start. Live provider proof is the `secure-file-providers` job.

Authorization matrix for this slice is the Admin HTTP Pest file
(`unauthenticated` / patient / doctor / low-assurance / missing capability /
CSRF / password-change-required / foreign reviewer / guessed UUID) plus
existing Verification `reviewer decisions` tests. No separate matrix document
exists in-repo beyond `Capabilities::PRIVILEGED_OPERATOR`.

This chunk is **not** production-promotable: SF-001 remains MERGE_ONLY.

Phase 02 as a whole is **not** PASS. This file does not claim production
approval or READY_TO_MERGE.
