# Phase 02 chunk 04 — Secure verification document upload pipeline (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** doctor-verification fail-closed upload intent,
private **ingress** object plus server-only **canonical/sealed** object,
server-side observation (streamed SHA-256 + magic MIME + structural
bounds, including trailing-payload rejection), exact-byte clamd INSTREAM,
durable outbox processing, transactional promotion to
`TrustedDocumentEvidence` / AVAILABLE verification documents, TOCTOU
binding to canonical bytes, applicant-safe status, BOLA, create-idempotency
grant reissue, bounded cleanup, audit/redaction, OpenAPI + generated
TypeScript contracts, digest-pinned live MinIO/clamd CI lane, blocking
Trivy of the pinned ClamAV image.

**Explicitly deferred:** Admin verification UI/HTTP, reviewer download URLs,
Pharmacy verification, Clinics/locations/memberships, approved document
catalogues beyond `ENGINEERING_DEFAULT`, OCR/AI ingestion, generic file
management APIs, staging infrastructure, production promotion, Phase 03.

**SF-001** remains unresolved / unaccepted (`MERGE_ONLY`,
`promotion_allowed=false`). `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.
Staging `Deploy to staging` remains fail-closed; this chunk does not bypass
it.

- **Branch:** `cursor/secure-verification-files-cc7f`
- **Draft PR:** #10 (kept Draft; not merged)
- **Base (GitHub `main`):** `c9b676baed44f08239580e1fe1324f4887908939`
- **Independent-review remediation recorded:** 2026-09-20
- **Reviewed-then-remediated HEAD (before this work):** `39632c0bbbfd3127a6c179a0657ff82f1bcf4d87`
- **GitHub live-provider proof HEAD:** `c5ee8d63a4252269002e223e4186f50c2c3992d7`
- **GitHub `pull-request` run:** [35495868880](https://github.com/mahmoudemad68/clinical_system/actions/runs/35495868880) **SUCCESS**

## Trust flow

```
REQUESTED
  -> UPLOADING          (persisted on intent create; REQUESTED is not a wait state)
  -> QUARANTINED        (client complete accepted; ingress copied to canonical)
  -> VALIDATING         (server observes canonical bytes)
  -> SCANNING           (clamd INSTREAM of canonical bytes, or fail-closed miss)
  -> AVAILABLE          (only after exact canonical object + hash + magic + structure + clean scan)
or REJECTED             (terminal unsafe)
```

No path jumps from client upload completion to AVAILABLE.

`CLIENT CLAIM != SERVER OBSERVATION != SCANNER VERDICT`.

Create persists `uploading` and issues a PUT grant **only** for the ingress
locator (`verification/q/...`). Complete means: the client claims it
finished; the server copies the exact observed ingress bytes to a
server-only canonical locator (`verification/c/...`) and may now inspect
that copy. Duplicate complete is safe. Inspection, malware scanning,
re-observation, promotion, and later trusted access use the canonical
locator only.

## Upload purpose and policy (`ENGINEERING_DEFAULT`)

| Item | Value | Status |
| --- | --- | --- |
| Case type | `doctor_verification` | ENGINEERING_DEFAULT |
| Requirement | `professional_id` | ENGINEERING_DEFAULT |
| Allowed formats | `application/pdf`, `image/jpeg`, `image/png` (magic + declared must match) | ENGINEERING_DEFAULT |
| Max bytes | 20_971_520 (20 MiB) | ENGINEERING_DEFAULT |
| Upload grant expiry | 900 seconds, capped by the upload-intent `expires_at` | ENGINEERING_DEFAULT |
| Max active uploads per requirement | 3 | ENGINEERING_DEFAULT |
| Max processing attempts | 8 | ENGINEERING_DEFAULT |
| Rejected-object cleanup eligibility | 86_400 seconds | ENGINEERING_DEFAULT; not a legal retention schedule |
| Archives / Office / SVG / HTML / executables / AV | denied | fail closed |

## Canonical sealed-object design

Client-writable ingress and server-only canonical objects are separate
locators. SHA-256 is a content hash, **not** an S3/MinIO version-id.

```
client PUT grant
  -> writable ingress locator (verification/q/<random>)
  -> complete: server copyExact to canonical locator (verification/c/<random>)
  -> validate + scan + re-observe canonical bytes
  -> AVAILABLE document hash/MIME/size bind canonical bytes only
```

| Rule | Mechanism |
| --- | --- |
| Signed upload URL writes only ingress | `createUploadGrant` / `issueUploadGrant` refuse `/c/` locators |
| Canonical locator is classified | never in HTTP, events, audit metadata, or metrics |
| `storage_locator` is not client-selectable | server-generated; identity trigger makes it immutable |
| Canonical locator is immutable once set | PostgreSQL protect trigger |
| AVAILABLE requires canonical locator | state-consistency CHECK |
| Ingress overwrite after seal cannot change trusted bytes | processor uses `trustedRef()` / `canonicalRef()` |
| Provider version-id | `StoreObject::providerVersionId()` stores a real S3 `VersionId` when present; otherwise NULL. Immutability is the server-only canonical locator plus SHA-256 |

`object_version` is **not** populated with SHA-256.

Local MinIO in this foundation typically has object versioning off, so
`providerVersionId` is null. That is documented, not treated as a storage
version.

## Server observation / polyglot bounds

`BoundedDocumentInspector` streams chunks (default 65_536 bytes), computes
SHA-256 without loading the whole object into a PHP string, detects PDF /
JPEG / PNG magic, and applies structural bounds:

- PDF: global `/Type /Page` minus `/Type /Pages` across the stream (carry
  so needles that split across chunks count once); `maxPages` is global;
  last `%%EOF` in the terminal window may be followed only by whitespace;
  meaningful trailing payload is `malformed`
- JPEG: first EOI (`FF D9`) is terminal; any later bytes are `malformed`
- PNG: IEND is terminal; leftover bytes after IEND are `malformed`
- PNG/JPEG dimension and chunk/resource caps retained
- zero-byte, oversize, MIME mismatch, unsupported magic, active PDF
  tokens (`/JavaScript`, `/JS `, `/Launch`, `/EmbeddedFile`, `/RichMedia`,
  `/XFA`) remain rejected

Declared MIME cannot override detection. Synthetic inert fixtures cover
oversized PDF page counts, PDF+trailing payload, JPEG+trailing ZIP-like
bytes, PNG+trailing payload, and valid PDF/JPEG/PNG.

## Scanner adapter

| Item | Value |
| --- | --- |
| Contract | Platform `ScanObject::scanStream(resource, sizeBytes)` — no URLs |
| Production bind | `ClamdScanObject` when `CLAMAV_HOST` is set |
| Fail-closed bind | `DisabledScanObject` (`unavailable`) when host is empty |
| Protocol | clamd `nINSTREAM` over private TCP |
| Write helper | `BoundedSocketWriter::writeAll` — every frame is fully written or fail closed |
| Exact bytes | `sent === sizeBytes` required before the terminal zero-length frame and before accepting a result. Short streams are `invalid` (never clean). Longer streams remain `invalid` |
| Local/integration image | `clamav/clamav:1.4.6@sha256:f156095071757e3838caa50265d65e36cdf7f934a27aacf851ea6d2fadbe8200` |
| Digest source | Docker Hub tag `clamav/clamav:1.4.6` index digest, verified 2026-09-19 |
| Network | Compose publishes `127.0.0.1:3310` only; port 7357 unpublished; no secrets; no host filesystem |
| Timeouts | `CLAMAV_TIMEOUT_MS` default 10_000; bounded max bytes 20_971_520 |
| Verdicts | `clean` / `infected` / `unavailable` (retryable) / `invalid` |
| Test fixtures | clean PDF/JPEG/PNG; EICAR *string* inside an otherwise valid PDF; no live malware committed |

Unavailable/timeout/malformed scanner responses leave the intent
quarantined/scanning and throw `TransientProviderFailure` for outbox retry.
They never mint AVAILABLE.

## Create-idempotency recovery

Create responses include a signed `upload_target` longer than the 255-byte
idempotency reference. The middleware still stores only
`{ref: verification_upload, id: <upload_id>}`. Signed URLs are never stored.

Verification registers `VerificationUploadIdempotencyReplayHydrator` on the
generic Platform `IdempotencyReplayHydrator` port. Same key + same payload
replays the same `upload_id` and, while the intent is still `uploading` and
unexpired, reissues a bounded PUT grant for that intent's existing ingress
locator. Grant expiry is the intent `expires_at` (never extended). Terminal /
rejected / available states get no grant. Only the owning authenticated
doctor can obtain a reissue. Tests PUT via the recovered intent and complete
it.

## MinIO bucket provisioning

Compose starts digest-pinned MinIO (`quay.io/minio/minio`) and an
idempotent `minio-init` using digest-pinned `quay.io/minio/mc`. Docker Hub
`minio/minio` / `minio/mc` are gone (404 / pull access denied); the same
RELEASE tags and index digests remain on quay.io. It creates
`clinic-local-private`, sets anonymous
access to `none`, and uses local-only credentials
(`clinic_local` / `local_dev_only_not_a_secret`). Those values must not be
reused in shared environments. Application roles in real environments must
not receive CreateBucket.

CI uses `scripts/ci/start-secure-file-providers.sh` and
`scripts/ci/provision-minio-bucket.sh` (same local credentials, host
network, fail closed). The `mc` image entrypoint is overridden to
`/bin/sh` so bucket init is not passed to `mc` as a subcommand.
`CLINIC_REQUIRE_OBJECT_STORE=1` /
`CLINIC_REQUIRE_CLAMAV=1` turn provider absence into a test failure. A
reachable provider with a missing bucket, bad auth, or public policy fails
rather than skips.

## Live provider CI (GitHub)

Job `secure-file-providers` on `pull-request`:

- digest-pinned `docker run` of MinIO, `mc` bucket init, and clamd (not
  unpinned GHA `services:` images)
- ISR-015 pin catalogue updated; workflow blob references every catalogued
  image ref
- runs live S3 contract tests, live Clamd tests, and a provider-backed
  upload → scan → AVAILABLE path
- proves anonymous HTTP GET/list of the bucket is not 200
- `CLINIC_REQUIRE_*` makes skip-if-absent fatal

Job `security` additionally:

- generates an SPDX SBOM of the pinned ClamAV image
- blocking Trivy image scan (`CRITICAL,HIGH`, `ignore-unfixed=false`, no
  new ignore file)

If Trivy reports High/Critical on the ClamAV image, follow ADR 0008: High
blocks promotion; Critical blocks merge. Do not create an undocumented
ignore. This chunk is **not** production-promotable while High/Critical
scanner-image findings remain, and is not production-promotable while
SF-001 remains MERGE_ONLY.

## TrustedDocumentEvidence issuance

- Default `TrustedDocumentEvidenceIssuer` remains `DisabledTrustedDocumentEvidenceIssuer`.
- `ProcessingTrustedDocumentEvidenceIssuer` is bound as a **concrete class
  only**, reachable from `VerificationUploadProcessor`.
- HTTP controllers never receive the processing issuer.
- Applicants and Admin `ActorContext` cannot mint evidence.
- Tests may use `TestingTrustedDocumentEvidenceIssuer` as an explicit fixture
  under `tests/Support` (not a production adapter).

Promotion transaction: lock upload (advisory) → confirm draft case →
re-observe exact **canonical** object → issue evidence →
`registerValidatedMetadataWithin` → persist AVAILABLE document + intent.
Duplicate `object_id` rolls back (`StateConflict`); no AVAILABLE row and no
success outbox/audit from that transaction.

## Queue / outbox / retry

Complete records `verification.upload_completed` v1 (`upload_id` only) in
the transactional outbox. `VerificationUploadCompletedConsumer` calls
`VerificationUploadProcessor`.

Retry-safe: versioned row updates, processing-attempt bound, terminal
rejected/available cannot be overwritten to AVAILABLE by a stale worker
(PostgreSQL trigger). Scanner misses retry the same canonical locator.

`clinic_worker` least privilege for this slice:

| Table | Worker | App |
| --- | --- | --- |
| `verification_cases` | `SELECT` | existing |
| `verification_documents` | `SELECT, INSERT` | existing |
| `verification_upload_intents` | `SELECT, UPDATE` (no DELETE) | `SELECT, INSERT, UPDATE` (no DELETE) |
| `verification_decisions` | none | insert-only |
| `doctor_profiles` | none | existing |

Upload-intent history is preserved. Privilege tests assert
`clinic_worker DELETE = false` and `clinic_app DELETE = false`.

Audit append still uses `pgsql_audit` / `clinic_append_audit_event`, not
worker DML on `audit_events`.

## TOCTOU

Promotion binds object id, observed SHA-256, size, detected MIME, and a
true provider version-id when one exists. Replacing **canonical** bytes
during scan fails `toctou_mismatch`. Replacing **ingress** bytes after
complete, including between complete and worker execution and after
AVAILABLE, does not change the canonical hash or the persisted document
hash.

Chunk 03 submitted-document freeze remains: after the case leaves `draft`,
INSERT/UPDATE/DELETE on `verification_documents` is rejected.

## Cleanup

`verification:reconcile-uploads` (hourly):

- expired `requested`/`uploading` intents → `rejected`/`expired` and object delete
- `rejected` rows past `cleanup_eligible_at` → delete ingress and canonical objects
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
| POST | `/api/v1/verification-uploads` | Idempotent create + bounded PUT grant; replay reissues a grant for the same ingress |
| POST | `/api/v1/verification-uploads/{upload_id}/complete` | Empty closed body; not evidence; seals canonical copy |
| GET | `/api/v1/verification-uploads/{upload_id}` | Applicant-safe status |

## Commands actually executed

GitHub `pull-request` run
[35495868880](https://github.com/mahmoudemad68/clinical_system/actions/runs/35495868880)
on `c5ee8d63a4252269002e223e4186f50c2c3992d7` is the recorded live-provider
evidence. Local host PHP is supplementary; it does not replace GitHub.

| Gate | Result |
| --- | --- |
| Pint `--test` (Core API job) | PASS, 563 files |
| PHPStan (Core API job) | `[OK] No errors` |
| Deptrac `--fail-on-uncovered` (Core API job) | PASS (job succeeded) |
| Core API Pest `./vendor/bin/pest` | **633 passed**, 12 skipped, 645 tests (11052 assertions) |
| Secure-file providers Pest | **32 passed** (337 assertions), 0 skipped |
| Live MinIO | bucket `clinic-local-private` created; anonymous access `private`; S3 contract tests passed |
| Live clamd | `clamd is ready`; `it scans a live clamd when one is reachable` passed |
| Provider-backed upload → scan → AVAILABLE | `it promotes a real provider-backed upload and ignores later ingress overwrite` passed |
| OpenAPI lint | valid |
| Event schemas | **19** checked |
| TypeScript client | committed generated client matches (`TS_CLIENT_FRESH`) |
| Breaking contracts vs `origin/main` | none (rule-of-record `npm run contracts:breaking`) |
| ISR-015 | Supply-chain policy PASS |
| Gitleaks + Semgrep | Security scans PASS |
| Trivy filesystem HIGH/CRITICAL | 0 (composer/npm/pip/pub + Dockerfiles) |
| Trivy image `clamav/clamav:1.4.6@sha256:f156095071757e3838caa50265d65e36cdf7f934a27aacf851ea6d2fadbe8200` | **0** HIGH/CRITICAL (alpine 3.24.1); `--exit-code 1`, `ignore-unfixed=false`, no extra ignore |
| ClamAV scanner SPDX SBOM | artifact `clamav-scanner.sbom.spdx.json` id `10600364304` (6708 bytes) |
| Runtime image scan core-api / ai-service | SUCCESS |

Local skips on a host without Docker/` :9000` / `:3310` remain skip-if-absent.
Those four live tests are **fatal** when `CLINIC_REQUIRE_OBJECT_STORE=1` /
`CLINIC_REQUIRE_CLAMAV=1`. On GitHub run 35495868880 they passed; Core API
job skips them because that job does not start MinIO/clamd (12 remaining
skips are pre-existing Auth Redis/Reverb/Octane/two-connection opt-in).

In-process coverage still ran: `clamd-stub.php` INSTREAM (clean / EICAR
FOUND / timeout / malformed / short stream / long stream / partial write
helper) and `InMemoryStoreObject` including ingress overwrite after seal
and race workers via the shared persist directory.

This chunk is **not** production-promotable: SF-001 remains MERGE_ONLY.

Phase 02 as a whole is **not** PASS. This file does not claim production
approval or READY_TO_MERGE.

## Residual (this chunk)

- Document requirement catalogue remains `ENGINEERING_DEFAULT` (`professional_id`).
- Allowed MIME/size/expiry/active-upload/cleanup windows are ENGINEERING_DEFAULT.
- MinIO local/CI typically has no object versioning; immutability is the
  server-only canonical locator plus SHA-256, not a provider version-id.
- Reviewer signed download URLs and Admin decision HTTP remain deferred.
- Approval still does not list the doctor or grant clinical capabilities.
- Subject erasure of a doctor profile does not automatically purge
  verification cases or quarantine objects in this slice.
- Staging remains unprovisioned; post-merge deploy stays fail-closed.
- SF-001 remains MERGE_ONLY / `promotion_allowed=false`.
- ClamAV image Trivy on run 35495868880 reported **0** HIGH/CRITICAL. That
  does not make the chunk production-promotable while SF-001 is MERGE_ONLY.
- oasdiff cross-check remains `continue-on-error` (pre-existing); the
  rule-of-record is `npm run contracts:breaking`.
- Local MinIO/ClamAV tests skip only when the provider TCP port is down and
  `CLINIC_REQUIRE_*` is unset. A reachable but broken bucket/auth/policy fails.
