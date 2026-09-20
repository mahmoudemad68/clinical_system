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
- **Document-access remediation HEAD:** `ab5c92b5d6f99639d487e7ee5d8fefdcc67abc5b`
- **Prior independently reviewed HEAD:** `c1375248348590a945085f480ca96d52ef515864`
- **Reviewer-download integrity remediation:** this revision (canonical
  hash/size observe-before-serve, exact-length `Content-Length` streaming,
  fail-closed truncation and canonical drift)
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
unauthenticated (bearer URL). On each GET the handler:

1. Verifies the HMAC signature and expiry.
2. Resolves the case/document/upload/canonical relationship under the
   existing Verification transaction and `lockCase` rules (`pending_review`,
   document belongs to that case, AVAILABLE+CLEAN, AVAILABLE upload intent,
   canonical `trustedRef()` only). The accidental consecutive duplicate
   `resolveCanonicalDownloadTarget` call is gone; there is one authorized
   resolve, then observation, then one post-observe re-check.
3. **Outside** the case lock / Verification transaction, observes the
   canonical object with `StoreObject::observe()`. The bytes currently
   stored at the locator must match the trusted document identity:
   `exists`, `sizeBytes == verification_documents.size_bytes`, and
   `sha256 == verification_documents.sha256`. When a real provider
   version-id is recorded on the upload intent **and** the provider
   returns one, those strings are compared as version-ids. SHA-256 is
   never treated as a VersionId.
4. Re-checks the same review-state authorization after observation so a
   GET that starts after a decision fails closed. Hashing is not held
   inside the advisory lock.
5. Opens `StoreObject::openStream()` of `trustedRef()` only.

Any integrity mismatch, missing object, or provider observe/open failure
fails closed as 404. The handler never falls back to ingress, never
serves drifted bytes, and never tells the bearer which field mismatched.
Bounded metrics may increment
`clinic_verification_review_results_total` with `result=integrity_mismatch`
or `result=provider_read_failure` (no IDs, hashes, or locators as labels).

`ReviewerDocumentStream` carries the authoritative expected size.
`ReviewerDocumentStreamResponse` sets `Content-Length: <trusted size_bytes>`
and streams in 64 KiB chunks. It does not `file_get_contents`, does not
buffer the full file, does not pad, and does not `break` on an empty or
failed `fread`. If `fread` returns false, returns empty, or EOF occurs
before the trusted size, the stream aborts (`ReviewerDocumentStreamAborted`)
so the connection cannot complete as a valid document. Content-Length lets
a client or proxy detect truncation. Locators, buckets, keys, expected
SHA, and observed SHA never appear in the HTTP body.

If the case is no longer `pending_review` (including after
approval/rejection/changes_requested), GET fails closed as 404 even when
the HMAC is still unexpired. An issued URL is not a generic permanent
document capability.

Canonical drift after promotion (same-size different bytes, shorter
object, longer object) is denied before any reviewer body is served.
A matching observation followed by a truncated or failed `openStream` /
`fread` does not complete a valid-looking partial document.

Safe download headers (React viewer remains deferred; PDFs are not
rendered inline):

- `Content-Type` = authoritative detected MIME
- `Content-Disposition` = `attachment; filename="verification-document.<ext>"`
  (generic server-owned name from MIME; never the original filename;
  never derived from user input)
- `Content-Length` = persisted `verification_documents.size_bytes`
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
  `pending_review` so a URL stops serving after a decision. Canonical
  hash/size is verified before `openStream`. A residual window remains
  only for bytes already in flight after the last pre-stream state and
  integrity check; a truncated provider stream aborts rather than
  completing as a valid file.
- Approval still does not list the doctor or grant clinical capabilities.
- React Admin verification UI remains deferred.
- SF-001 remains MERGE_ONLY / `promotion_allowed=false`.
- Staging remains unprovisioned; post-merge deploy stays fail-closed.
- Phase 02 as a whole is **not** PASS.

## Commands actually executed

### Reviewer-download integrity (this revision)

Local gates on the integrity remediation (observe-before-serve, exact-length
stream, canonical drift, truncated provider stream):

| Gate | Result |
| --- | --- |
| Focused download + architecture Pest | **26 passed** (2514 assertions) |
| Document access + live MinIO skip + grant race + review HTTP | **15 passed**, 1 skipped live MinIO |
| Claim race + decision rollback | **5 passed** |
| Pint `--test --dirty` | PASS |
| PHPStan (changed Verification download files) | `[OK] No errors` |
| Deptrac `--fail-on-uncovered` | PASS (0 uncovered) |

Full Core API Pest, secure-file providers, ISR-015, and GitHub
`pull-request` CI are recorded against the exact HEAD after push.

### GitHub CI (authoritative, prior document-access HEAD)

GitHub `pull-request` run
[35507430702](https://github.com/mahmoudemad68/clinical_system/actions/runs/35507430702)
on `ab5c92b5d6f99639d487e7ee5d8fefdcc67abc5b` **SUCCESS**. Independently
reviewed HEAD `c1375248348590a945085f480ca96d52ef515864` recorded that
SUCCESS. Local host PHP is supplementary and does not replace GitHub CI.
This file does not claim production approval.

| Gate | Result |
| --- | --- |
| Pint `--test` (Core API job) | PASS, 595 files |
| PHPStan (Core API job) | `[OK] No errors` |
| Deptrac `--fail-on-uncovered` (Core API job) | PASS (0 uncovered) |
| Core API Pest `./vendor/bin/pest` | **676 passed**, 13 skipped (12061 assertions) |
| Browser CSRF Playwright | 4 passed |
| Secure-file providers Pest | **43 passed** (507 assertions), 0 skipped |
| Live MinIO reviewer download | `it issues a live MinIO reviewer grant against the canonical object only` passed (application-signed GET; canonical SHA-256 matched; URL did not contain locators) |
| Live clamd | `it scans a live clamd when one is reachable` passed |
| OpenAPI lint / event schemas / TS freshness / breaking vs `main` | Contracts job SUCCESS (additive GET `/api/v1/verification-review-files/{case_id}/{document_id}`) |
| ISR-015 / Supply-chain policy | SUCCESS (SF-001 remains MERGE_ONLY / fail-closed) |
| Gitleaks + Semgrep + Trivy FS | Security scans SUCCESS |
| Runtime image scan core-api / ai-service | SUCCESS |
| Admin web / Electron desktops / packaged E2E (ubuntu, windows, macos) | SUCCESS |
| AI service / Flutter | SKIPPED (path filters; this chunk did not change those trees) |

Core API's 13 skips are pre-existing Auth Reverb/Octane/two-connection and
live-provider tests that job does not start (Redis rate-limit tests run).
Live MinIO/clamd and the Admin reviewer download ran in `secure-file-providers`
with `CLINIC_REQUIRE_OBJECT_STORE=1` / `CLINIC_REQUIRE_CLAMAV=1`.

Local supplement (no Docker/scanners/MinIO here): Pint, PHPStan, clean-cache
Deptrac, focused Admin document-access/download/race/audit/architecture Pest
(38 passed, 1 skipped live MinIO), Admin HTTP+rollback 14 passed, full Core
API Pest **671 passed** / 18 skipped, OpenAPI lint, generated TS, breaking
vs `main`, ISR-015.

Authorization matrix for this slice is the Admin HTTP Pest file
(`unauthenticated` / patient / doctor / low-assurance / missing capability /
CSRF / password-change-required / foreign reviewer / guessed UUID) plus
existing Verification `reviewer decisions` tests. No separate matrix document
exists in-repo beyond `Capabilities::PRIVILEGED_OPERATOR`.

This chunk is **not** production-promotable: SF-001 remains MERGE_ONLY.

Phase 02 as a whole is **not** PASS. This file does not claim production
approval or READY_TO_MERGE.
