# Phase 02 chunk 05 — Admin verification review backend (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** privileged Admin HTTP for the existing doctor
verification workflow: pending-review queue, claim, reviewer-safe case
detail, purpose-bound **application-signed** document-access grant
(canonical bytes streamed; storage locators never leave the backend),
and approve/reject/changes_requested decisions. Admin is a transport
facade.
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
- **Draft PR:** [#11](https://github.com/mahmoudemad68/clinical_system/pull/11) (kept Draft; not merged)
- **Base (GitHub `main`):** `722bca7e76f6e9565086da0c9709aad4ba57eaed`
- **GitHub `pull-request` HEAD:** `77288f16d08d6206e776b3c1a33d75e4f44e491b`
- **GitHub `pull-request` run:** [35505061053](https://github.com/mahmoudemad68/clinical_system/actions/runs/35505061053) **SUCCESS**
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

Preconditions (re-read under `lockCase`): privileged assigned reviewer,
case `pending_review`, document belongs to that case, AVAILABLE+CLEAN,
`upload_intent_id` present, intent AVAILABLE, canonical locator present.
Resolves canonical `trustedRef()` / `canonicalRef()` server-side only.
Never ingress. Never request-supplied locators, keys, or buckets.

Grant issuance is linearizable with case state. While the case lock is
held the service:

1. validates the review preconditions above;
2. mints an **application-owned** HMAC URL locally (no object-store I/O);
3. appends `verification.document_access_granted`;
4. commits.

Only then is the URL returned. If audit append fails, the transaction
rolls back, the POST fails, and no grant response is returned. The
signing secret is `app.key` and never leaves the server. The signed URL,
signature, and canonical locator are never stored in audit, outbox, or
idempotency persistence.

Returns: `document_id`, short-lived application-signed GET `url`,
`expires_at`, `detected_mime`, `size_bytes`. TTL ENGINEERING_DEFAULT 120
seconds, hard-capped 1–300.

The URL shape is:

`GET /api/v1/verification-review-files/{case_id}/{document_id}?expires=…&signature=…`

It may expose the already-authorized opaque case/document UUIDs. It does
**not** contain `canonical_storage_locator`, ingress `storage_locator`,
bucket name, object key, `object_id` used as a storage locator, or a
direct S3/MinIO presigned URL (`X-Amz-Signature` never appears). Reviewer
HTTP never receives a storage locator.

**Bearer-URL limitation:** the application-signed URL is not actor-bound
after issuance. During its short TTL it is a capability token. Security
relies on reviewer authorization before issuance (serialized with case
state), exact case/document HMAC binding, canonical-only server-side
resolution on GET, fail-closed review-state checks, short expiry,
private bucket, no listing, no URL logging, and atomic audited
issuance.

`GET /api/v1/verification-review-files/{case_id}/{document_id}` is
unauthenticated (bearer URL). On each GET the handler verifies signature
and expiry, then re-resolves the document through Verification-owned
services: the document must still belong to that case, remain
AVAILABLE+CLEAN, retain an AVAILABLE upload intent, and have a canonical
locator. Bytes are opened with `StoreObject::openStream()` of
`trustedRef()` only and streamed in 64 KiB chunks bounded by persisted
`size_bytes` and `VerificationPolicy::maxDocumentBytes()`. The handler
does not `file_get_contents` the object, does not buffer the full file
into one PHP string, does not expose filesystem paths, and does not
redirect to the canonical S3 URL. If the case is no longer
`pending_review` (including after approval/rejection/changes_requested),
GET fails closed as 404 even when the HMAC is still unexpired. An issued
URL is not a generic permanent document capability.

Safe download headers (React viewer remains deferred; PDFs are not
rendered inline):

- `Content-Type` = authoritative detected MIME
- `Content-Disposition` = `attachment; filename="verification-document.<ext>"`
  (generic server-owned name from MIME; never the original filename;
  never derived from user input)
- `X-Content-Type-Options: nosniff`
- `Cache-Control: private, no-store`
- `Referrer-Policy: no-referrer`

Grant vs concurrent decision is linearizable: either the grant
transaction commits first (valid at that instant; later GET fails once
the case is decided) or the decision commits first (grant issuance is
denied). A usable review grant is never issued after a decision commits.

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
- Signed GET URLs are application-owned and bearer-style while valid;
  they are not actor-bound after issuance. GET re-checks
  `pending_review` so a URL stops serving after a decision. A residual
  window remains only for bytes already in flight after the last
  pre-stream state check.
- Approval still does not list the doctor or grant clinical capabilities.
- React Admin verification UI remains deferred.
- SF-001 remains MERGE_ONLY / `promotion_allowed=false`.
- Staging remains unprovisioned; post-merge deploy stays fail-closed.
- Phase 02 as a whole is **not** PASS.

## Commands actually executed

### GitHub CI (authoritative)

GitHub `pull-request` run
[35505061053](https://github.com/mahmoudemad68/clinical_system/actions/runs/35505061053)
on `77288f16d08d6206e776b3c1a33d75e4f44e491b` **SUCCESS**. Local host PHP is
supplementary and does not replace this run. This file does not claim
production approval.

| Gate | Result |
| --- | --- |
| Pint `--test` (Core API job) | PASS, 589 files |
| PHPStan (Core API job) | `[OK] No errors` |
| Deptrac `--fail-on-uncovered` (Core API job) | PASS (0 uncovered after inlining the decision-outcome PHPDoc; `@phpstan-import-type` was parsed as a fake class) |
| Core API Pest `./vendor/bin/pest` | **669 passed**, 13 skipped (11837 assertions) |
| Browser CSRF Playwright | 4 passed |
| Secure-file providers Pest | **43 passed** (489 assertions), 0 skipped |
| Live MinIO reviewer grant | `it issues a live MinIO reviewer grant against the canonical object only` passed |
| Live clamd | `it scans a live clamd when one is reachable` passed |
| OpenAPI lint / event schemas / TS freshness / breaking vs `main` | Contracts job SUCCESS |
| ISR-015 / Supply-chain policy | SUCCESS (SF-001 remains MERGE_ONLY / fail-closed) |
| Gitleaks + Semgrep + Trivy FS | Security scans SUCCESS (Trivy FS: 0 HIGH/CRITICAL) |
| ClamAV image Trivy | 0 HIGH/CRITICAL |
| Runtime image scan core-api / ai-service | SUCCESS |
| Admin web / Electron desktops / packaged E2E (ubuntu, windows, macos) | SUCCESS |
| AI service / Flutter | SKIPPED (path filters; this chunk did not change those trees) |

Core API's 13 skips are pre-existing Auth Reverb/Octane/two-connection and
live-provider tests that job does not start (Redis rate-limit tests run).
Live MinIO/clamd and the Admin reviewer grant ran in `secure-file-providers`
with `CLINIC_REQUIRE_OBJECT_STORE=1` / `CLINIC_REQUIRE_CLAMAV=1`.

Local supplement (no Docker/scanners/MinIO here): Pint, PHPStan, clean-cache
Deptrac, focused Admin/race/architecture Pest, full Core API Pest 664 passed /
18 skipped, OpenAPI lint, events, generated TS, breaking vs `main`, ISR-015,
Admin web and desktop typecheck/tests.

Authorization matrix for this slice is the Admin HTTP Pest file
(`unauthenticated` / patient / doctor / low-assurance / missing capability /
CSRF / password-change-required / foreign reviewer / guessed UUID) plus
existing Verification `reviewer decisions` tests. No separate matrix document
exists in-repo beyond `Capabilities::PRIVILEGED_OPERATOR`.

This chunk is **not** production-promotable: SF-001 remains MERGE_ONLY.

Phase 02 as a whole is **not** PASS. This file does not claim production
approval or READY_TO_MERGE.
