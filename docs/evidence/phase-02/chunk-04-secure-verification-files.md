# Phase 02 chunk 04 — Secure verification document upload pipeline (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** doctor-verification fail-closed upload intent,
private quarantine storage, server-side observation (streamed SHA-256 +
magic MIME + structural bounds), clamd-compatible malware scan, durable
outbox processing, transactional promotion to `TrustedDocumentEvidence` /
AVAILABLE verification documents, TOCTOU binding, applicant-safe status,
BOLA, idempotency, bounded cleanup, audit/redaction, OpenAPI + generated
TypeScript contracts.

**Explicitly deferred:** Admin verification UI/HTTP, reviewer download URLs,
Pharmacy verification, Clinics/locations/memberships, approved document
catalogues beyond `ENGINEERING_DEFAULT`, OCR/AI ingestion, generic file
management APIs, staging infrastructure, production promotion, Phase 03.

**SF-001** remains unresolved / unaccepted (`MERGE_ONLY`,
`promotion_allowed=false`). `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.
Staging `Deploy to staging` remains fail-closed; this chunk does not bypass
it.

- **Branch:** `cursor/secure-verification-files-cc7f`
- **Base (GitHub `main`):** `c9b676baed44f08239580e1fe1324f4887908939`
- **Recorded:** 2026-09-19

## Trust flow

```
REQUESTED
  -> UPLOADING          (persisted on intent create; REQUESTED is not a wait state)
  -> QUARANTINED        (client complete accepted; object not yet trusted)
  -> VALIDATING         (server observes bytes)
  -> SCANNING           (clamd INSTREAM or fail-closed miss)
  -> AVAILABLE          (only after exact object + hash + magic + structure + clean scan)
or REJECTED             (terminal unsafe)
```

No path jumps from client upload completion to AVAILABLE.

`CLIENT CLAIM != SERVER OBSERVATION != SCANNER VERDICT`.

Create persists `uploading`. Complete means only: the client claims it
finished; the server may now inspect. Duplicate complete is safe.

## Upload purpose and policy (`ENGINEERING_DEFAULT`)

| Item | Value | Status |
| --- | --- | --- |
| Case type | `doctor_verification` | ENGINEERING_DEFAULT |
| Requirement | `professional_id` | ENGINEERING_DEFAULT |
| Allowed formats | `application/pdf`, `image/jpeg`, `image/png` (magic + declared must match) | ENGINEERING_DEFAULT |
| Max bytes | 20_971_520 (20 MiB) | ENGINEERING_DEFAULT |
| Upload grant expiry | 900 seconds | ENGINEERING_DEFAULT |
| Max active uploads per requirement | 3 | ENGINEERING_DEFAULT |
| Max processing attempts | 8 | ENGINEERING_DEFAULT |
| Rejected-object cleanup eligibility | 86_400 seconds | ENGINEERING_DEFAULT; not a legal retention schedule |
| Archives / Office / SVG / HTML / executables / AV | denied | fail closed |

## Storage adapter

Platform `StoreObject`: production/local `S3StoreObject` (MinIO locally,
private bucket, no public ACL). Tests use `InMemoryStoreObject` with a
shared persist directory so race workers see the same bytes.

Server generates the opaque object id and random quarantine locator
(`verification/q/...`). The locator is classified, never returned in HTTP,
events, logs, or metrics, and is not authorization. Short-lived PUT grants
only. Anonymous GET/list is denied (MinIO contract test).

## Server observation

`BoundedDocumentInspector` streams chunks (default 65_536 bytes), computes
SHA-256 without loading the whole object into a PHP string, detects PDF /
JPEG / PNG magic, and applies structural bounds (PDF `%%EOF` / page bound,
PNG IHDR/IEND, JPEG SOF/EOI, zero-byte and oversize reject). Declared MIME
cannot override detection.

Object version for this slice is the observed SHA-256 (S3/MinIO local
foundation does not expose a distinct version-id on every PUT). Promotion
re-observes and rejects on hash/size/MIME/version mismatch (`toctou_mismatch`).

## Scanner adapter

| Item | Value |
| --- | --- |
| Contract | Platform `ScanObject::scanStream(resource, sizeBytes)` — no URLs |
| Production bind | `ClamdScanObject` when `CLAMAV_HOST` is set |
| Fail-closed bind | `DisabledScanObject` (`unavailable`) when host is empty |
| Protocol | clamd `nINSTREAM` over private TCP |
| Local/integration image | `clamav/clamav:1.4.6@sha256:f156095071757e3838caa50265d65e36cdf7f934a27aacf851ea6d2fadbe8200` |
| Digest source | Docker Hub tag `clamav/clamav:1.4.6` index digest, verified 2026-09-19 |
| Network | Compose publishes `127.0.0.1:3310` only; port 7357 unpublished; no secrets; no host filesystem |
| Timeouts | `CLAMAV_TIMEOUT_MS` default 10_000; bounded max bytes 20_971_520 |
| Verdicts | `clean` / `infected` / `unavailable` (retryable) / `invalid` |
| Test fixtures | clean PDF/JPEG/PNG; EICAR *string* inside an otherwise valid PDF; no live malware committed |

GitHub Core CI does **not** add ClamAV as a workflow `image:` service (ISR-015
pin catalog would then require bidirectional workflow refs). CI scanner tests
use a local `clamd-stub.php` or skip when the daemon is down.

Unavailable/timeout/malformed scanner responses leave the intent
quarantined/scanning and throw `TransientProviderFailure` for outbox retry.
They never mint AVAILABLE.

## TrustedDocumentEvidence issuance

- Default `TrustedDocumentEvidenceIssuer` remains `DisabledTrustedDocumentEvidenceIssuer`.
- `ProcessingTrustedDocumentEvidenceIssuer` is bound as a **concrete class
  only**, reachable from `VerificationUploadProcessor`.
- HTTP controllers never receive the processing issuer.
- Applicants and Admin `ActorContext` cannot mint evidence.
- Tests may use `TestingTrustedDocumentEvidenceIssuer` as an explicit fixture
  under `tests/Support` (not a production adapter).

Promotion transaction: lock upload (advisory) → confirm draft case →
re-observe exact object → issue evidence → `registerValidatedMetadataWithin`
→ persist AVAILABLE document + intent. Duplicate `object_id` rolls back
(`StateConflict`); no AVAILABLE row and no success outbox/audit from that
transaction.

Applicant attribution on the processing path uses
`verification_upload_intents.created_by_user_id` recorded at HTTP create so
`clinic_worker` does not query `doctor_profiles`. The test-fixture registrar
still uses `DoctorApplicantService::findById`.

## Queue / outbox / retry

Complete records `verification.upload_completed` v1 (`upload_id` only) in
the transactional outbox. `VerificationUploadCompletedConsumer` calls
`VerificationUploadProcessor`.

Retry-safe: versioned row updates, processing-attempt bound, terminal
rejected/available cannot be overwritten to AVAILABLE by a stale worker
(PostgreSQL trigger). Scanner misses retry the same immutable locator.

`clinic_worker` least privilege for this slice:

| Table | Worker |
| --- | --- |
| `verification_cases` | `SELECT` |
| `verification_documents` | `SELECT, INSERT` |
| `verification_upload_intents` | `SELECT, UPDATE, DELETE` |
| `verification_decisions` | none |
| `doctor_profiles` | none |

Audit append still uses `pgsql_audit` / `clinic_append_audit_event`, not
worker DML on `audit_events`.

## TOCTOU

Promotion binds object id, observed SHA-256, size, detected MIME, and the
storage version (SHA-256). Bytes replaced during scan fail `toctou_mismatch`.
Chunk 03 submitted-document freeze remains: after the case leaves `draft`,
INSERT/UPDATE/DELETE on `verification_documents` is rejected.

## Cleanup

`verification:reconcile-uploads` (hourly):

- expired `requested`/`uploading` intents → `rejected`/`expired` and object delete
- `rejected` rows past `cleanup_eligible_at` → object delete
- never deletes `AVAILABLE` or submitted evidence
- never accepts a user-supplied path
- object I/O runs after the DB commit

Legal retention of rejected objects: **OPEN_LEGAL_DECISION**.

## Authorization / BOLA

Doctor-only HTTP. Own draft `doctor_verification` case. Known requirement.
Closed JSON. Cross-doctor upload id → 404 (no existence leak). Client cannot
set state, object key, scanner, or AVAILABLE.

## Audit / redaction

Audited: intent created, completion accepted, validation started, scan
started, available, rejected, cleanup. Metadata is reason/requirement/state
only. Canary tests assert locator, National ID, signed-URL markers, and
`object_key` stay out of HTTP bodies.

Metric `clinic_secure_file_results_total` labels: `result`, `detected_type`,
`requirement_code`. No upload/document/user/case ids as labels.

## HTTP

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/api/v1/verification-uploads` | Idempotent create + bounded PUT grant |
| POST | `/api/v1/verification-uploads/{upload_id}/complete` | Empty closed body; not evidence |
| GET | `/api/v1/verification-uploads/{upload_id}` | Applicant-safe status |

## Commands actually executed

Recorded 2026-09-19 on host PHP 8.3 with `pdo_pgsql` against `clinic_test`.
GitHub PR CI on the final HEAD is the merge-review evidence; this file does
not treat a local run as GitHub evidence.

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations, 0 uncovered, **2019** allowed |
| focused Verification/file Pest (`tests/Feature/Verification`, `tests/Unit/Verification`, `ArchitectureBoundaryTest`, `BoundedDocumentInspectorTest`, `ClamdScanObjectTest`, `ProviderPortContractTest`, `S3StoreObjectContractTest`) | **91 passed**, 2 skipped, 93 tests (2854 assertions) |
| `./vendor/bin/pest` (full Core suite) | **625 passed**, 15 skipped, 640 tests (10947 assertions) |
| `npm run contracts:lint` | OpenAPI valid |
| `npm run contracts:events` | **19** event schemas checked |
| `npm run contracts:generate:ts` | generated client matches commit (`TS_CLIENT_FRESH`) |
| `npm run contracts:breaking` vs `origin/main` | no breaking changes against `origin/main` |
| `python3 scripts/ci/run-isr015-validators.py` | **PASS** |

Local skips on this host (Docker daemon unavailable):

- `ClamdScanObjectTest::it scans a live clamd when one is reachable` — clamd not on `:3310`
- `S3StoreObjectContractTest::Private objects are not anonymously readable` — MinIO not on `:9000`

In-process coverage still ran: `clamd-stub.php` INSTREAM (clean / EICAR FOUND / timeout / malformed) and `InMemoryStoreObject` (including race workers via the shared persist directory). Pre-existing Auth Redis/Reverb/Octane/two-connection race skips remain opt-in.

Gitleaks, Semgrep, Trivy filesystem/image, and SBOM remain GitHub PR `security` / `image-scan` jobs. This host could not pull `clamav/clamav:1.4.6` (no Docker). The compose sidecar is digest-pinned; it is **not** added as a GitHub Actions `services:` image (ISR-015 pin catalog / bidirectional workflow refs). Prior GitHub SAST on `2746c4e` flagged `unlink()` in the in-memory persist adapter; deletion now allowlists a SHA-256 hex basename and uses Laravel `Filesystem::delete` (no `unlink()` in that adapter).

Phase 02 as a whole is **not** PASS. GitHub PR CI binds to the pushed HEAD;
this file does not claim production approval.

## Residual (this chunk)

- Document requirement catalogue remains `ENGINEERING_DEFAULT` (`professional_id`).
- Allowed MIME/size/expiry/active-upload/cleanup windows are ENGINEERING_DEFAULT.
- Object version id is the observed SHA-256 on this MinIO foundation.
- GitHub Actions does not run a live ClamAV or MinIO service; those tests
  skip-if-down or use in-process fixtures/stubs.
- Reviewer signed download URLs and Admin decision HTTP remain deferred.
- Approval still does not list the doctor or grant clinical capabilities.
- Subject erasure of a doctor profile does not automatically purge
  verification cases or quarantine objects in this slice.
- Staging remains unprovisioned; post-merge deploy stays fail-closed.
- SF-001 remains MERGE_ONLY / `promotion_allowed=false`.
