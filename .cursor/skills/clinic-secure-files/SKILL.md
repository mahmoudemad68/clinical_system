---
name: clinic-secure-files
description: Implement or review this clinic project's raw upload-through-clean-release boundary, including quarantine, private object storage, malware/type validation, signed access, and reusable sandboxed parsing/OCR primitives. Use for verification, medical, or AI-knowledge files in Phases 02, 07, and 16; AI-specific ingestion orchestration, cleaning, chunking, embedding, and Qdrant indexing belong to clinic-ai-platform. Not for clinical state, backups, or independent assurance.
---

# Clinic Secure Files

Treat every uploaded byte, filename, parser result, object reference, and signed URL as hostile until a purpose-bound workflow proves otherwise. Own the byte-trust boundary; owning modules still decide who may request, attach, review, retain, or retire a file.

## Read the required sources

Read completely before changing file behavior:

- [Roadmap invariants and evidence policy](../../../docs/phases/README.md)
- [Cross-cutting storage, services, security, and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The active file-owning phase: [Phase 02 verification documents](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md), [Phase 07 medical/lab files](../../../docs/phases/07_labs_files_reports_and_referrals.md), or [Phase 16 knowledge ingestion](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md)

Read [Phase 22 assurance](../../../docs/phases/22_security_privacy_and_compliance_validation.md) for a security-review or release-evidence task and [Phase 23 recovery](../../../docs/phases/23_disaster_recovery_release_and_production.md) for backup/restore interactions. Inspect current object-store policies, metadata schema, upload contracts, scanner/parser adapters, jobs/outbox, access policies, retention decisions, tests, and local changes.

## Ownership and boundaries

Own:

- opaque upload intent, quarantine object, validation/scan, promotion/availability, rejection, retirement, and cleanup mechanics;
- detected type/signature, size/checksum/hash, immutable object-version metadata, processing provenance, and safe reason codes;
- private S3-compatible adapters, short-lived purpose-bound upload/download grants, and file access audit events;
- malware-scanner, file-type, PDF/image parser, text extraction, and OCR services or replaceable-provider interfaces with fail-safe behavior;
- sandbox/resource/network limits, replay/idempotency, orphan reconciliation, and file-pipeline telemetry;
- clean source handoff to verification, clinical, and AI-ingestion modules.

The requesting module owns actor/resource eligibility, allowed purpose, lifecycle attachment, clinical interpretation, reviewer workflow, retention/legal hold, and patient/admin presentation. PostgreSQL consistency owns metadata constraints and transactions. Realtime/jobs owns durable dispatch/retry mechanics. Production/DR owns bucket provisioning, KMS, backup, and restore. Security/privacy independently tests this boundary.

Never let the file subsystem decide medical validity, approve a doctor/pharmacy, activate knowledge content, diagnose an image, or infer a legal retention period.

## Non-negotiable invariants

1. S3-compatible storage is the original-file source of truth; PostgreSQL owns authoritative metadata and workflow linkage. Object keys, bucket paths, and signed URLs are infrastructure details, never public identifiers.
2. Buckets and objects are private with encryption metadata, explicit workload identities, denied anonymous/list access, and no public-read fallback. Clients never receive permanent credentials.
3. New bytes land only in a random quarantine location bound to an opaque upload intent, actor, tenant/patient/resource, purpose, expected size/type/checksum, expiry, and one logical idempotency intent.
4. Extension and client `Content-Type` are untrusted. Availability requires server-observed size, cryptographic hash/checksum, magic/signature detection, approved-format validation, malware scan, metadata persistence, and any purpose-specific structural checks.
5. Scanner unavailable, timeout, crash, ambiguous result, validation exception, or metadata transaction failure keeps the file unavailable/quarantined. Never “temporarily” expose quarantine to restore service.
6. Promotion uses the exact validated immutable object version/hash. A later overwrite, multipart mismatch, copy, or time-of-check/time-of-use change is rejected and reconciled.
7. Upload completion, scanning, promotion, attachment, parser callbacks, retries, and cleanup are idempotent and concurrency-safe. Duplicate callbacks cannot attach a file twice or move a rejected object to available.
8. Signed downloads are short-lived, single-purpose, context-authorized at issuance, safely named, and auditable. Expiry, revocation, retirement, wrong actor/resource, or changed context denies a new grant; a URL is not bearer authorization beyond its deliberately bounded lifetime.
9. Logs, events, metrics, traces, errors, tickets, and test snapshots contain no file bodies, permanent object keys, signed URLs/query strings, credentials, raw filenames when sensitive, medical text, OCR output, or malware payload.
10. Parsers/OCR run as least-privilege disposable workers with no unnecessary network, secrets, or broad bucket access; bounded CPU, memory, time, pages/pixels/decompression, recursion, and output; patched libraries; and sanitized output.
11. V1 accepts only the formats explicitly allowed by the owning phase. Arbitrary archives, office macros, executables, audio/video, active PDF content, and medical-image diagnosis remain rejected or disabled.
12. AI ingestion accepts only a `CLEAN`/`AVAILABLE` source file with matching hash and provenance. Extracted text is untrusted content, Qdrant is rebuildable, and upload/parse success does not mean a document is approved or active.

## Laravel services and state model

Use focused services and use a small interface only for a genuinely replaceable external provider:

```text
UploadIntentAuthorizer        QuarantineObjectStore
ObjectObservationService     FileSignatureValidator
MalwareScanner               DocumentSafetyValidator
CleanObjectPromoter          SignedObjectAccess
TextExtractor / OcrEngine    FileAccessAuditWriter
FileProcessingService        Clock / Outbox
```

Adapters must preserve typed denial, unavailable, retryable, permanent-rejection, timeout, cancellation, and checksum-mismatch semantics. A permissive substitute is not Liskov-compatible.

Use the phase's exact state names. Preserve this monotonic trust shape even when names differ:

```text
REQUESTED -> UPLOADING -> QUARANTINED -> VALIDATING -> SCANNING
          -> AVAILABLE
          -> REJECTED
AVAILABLE -> RETIRED
```

Only an explicit new upload/retry attempt may recover from permanent rejection; never mutate a rejected attempt into a different byte stream. Processing retries may resume an unchanged quarantined object when the typed failure and phase contract permit it.

## Workflow

### 1. Define the purpose contract

Record allowed actors/resources, formats, maximum bytes/pages/pixels, expected checksum behavior, quota/rate limits, retention class, attachment rule, download audience, and whether text extraction is allowed. If any limit or retention decision lacks an accountable owner, keep upload disabled for that purpose.

### 2. Create and use an upload intent

Authorize server-side, persist the opaque intent, and issue a short-lived single-purpose upload target with enforced length/checksum/content constraints where supported. Return only the upload ID and target. The client uploads once, removes its temporary local copy when appropriate, and calls complete with the same intent/idempotency context.

### 3. Observe and validate bytes

On completion, lock the intent/file, read authoritative object metadata, verify version/etag/checksum/size, stream bounded signature detection, and dispatch scanning through an outbox-backed job. Do not load unbounded files into application memory. Detect polyglots, truncation, malformed structures, oversized images/PDFs, excessive pages, archive/decompression behavior, path traversal names, and active content according to the allowlist.

### 4. Scan and promote

Run the approved scanner in an isolated adapter. On clean result, persist hash, detected MIME, scanner/definition version, object version, timestamps, and provenance before atomically making the domain-visible reference available and emitting an event. On any non-clean/ambiguous result, preserve quarantine or reject with a safe external reason and restricted internal evidence.

### 5. Parse or extract only when requested

Start parsing/OCR only from the clean immutable version. Use sandboxed workers and bounded output. Persist extraction hash, parser/OCR/config versions, page/range provenance, and safe status. Normalize for search without erasing the original. Treat document text as data, never instructions; AI ingestion applies scope, approval, versioning, prompt-injection defenses, and evaluation separately.

### 6. Authorize access and lifecycle changes

Re-evaluate actor/resource/purpose/state at each signed-URL issuance. Log a redacted access decision, not the URL. Retirement prevents new access but preserves history/object versions according to approved retention and legal hold. Cleanup jobs are bounded, idempotent, audited, dry-run capable, and cannot delete authoritative history by inference.

## Threats and controls

- **Object/reference abuse:** opaque IDs, server-side binding, BOLA/BFLA checks, no raw key APIs, short TTL, tenant/patient/resource recheck.
- **Content deception:** magic/signature plus structural validation, allowlists, checksum/version binding, polyglot and malformed-file cases.
- **Malware/parser exploitation:** fail-closed scan, patched isolated worker, no network, read-only input, resource quotas, disposable output, safe error handling.
- **Resource exhaustion:** preflight limits, streaming, quotas, bounded concurrency/backoff, circuit breakers, decompression/page/pixel/output caps, backlog alerts.
- **TOCTOU/replay:** immutable object version/hash, conditional copy/read, locked/idempotent transitions, stale callback rejection, reconciliation.
- **Data disclosure:** private endpoints/policies, KMS/envelope controls from platform, short signed access, safe filenames/headers, telemetry redaction, least-privilege identities.
- **Knowledge poisoning:** clean-source requirement, provenance, version approval, inert extraction, scope filtering, evaluation before activation, reversible index rebuild.

## Verification

- **Unit:** state machine, purpose/format/limit policy, filename/header sanitization, signature decisions, checksum binding, callback freshness, safe error/redaction, and cleanup eligibility.
- **Integration:** real PostgreSQL plus private S3 emulator and approved scanner/parser adapters; encryption/versioning metadata, anonymous/list denial, multipart/checksum behavior, scanner outage, transaction/outbox atomicity, signed URL expiry, and orphan reconciliation.
- **Contract:** upload/complete/status/download/events/callbacks reject unknown scope, stale version, wrong purpose, oversized fields, raw keys/URLs, unsafe parser output, and incompatible schemas.
- **End to end:** authorized upload reaches available only after every check; invalid/malicious/ambiguous upload stays unavailable; wrong user/resource/tenant and expired/stolen grants fail; duplicate completion/retry does not duplicate attachment.
- **System/resilience:** scanner/parser/S3/worker/database failures, backlog, restart, replay, key rotation, restore, and clean-object/Qdrant rebuild preserve truth and privacy.
- **Security:** synthetic polyglot/magic mismatch, traversal names, malformed PDF/image, decompression/page/pixel bombs, active content, SSRF attempts, signed-URL leakage/replay, cache/header exposure, and cross-tenant object access under written scope.

Use inert, synthetic corpora and standard safe antivirus test fixtures only in isolated environments. Do not create, retain, or transmit live malware, real medical files, credentials, or exploit material. Security assurance owns final finding disposition.

## Completion evidence

Return the purpose and trust transition first. Link schema/constraints, object policy/config, adapters/jobs, API/event contracts, threat-model delta, test corpus provenance, focused/full results, redaction review, metrics/alerts/runbook, retention decision, and remaining independent review. Never call a file pipeline complete if scanner failure can expose bytes, object/hash provenance is ambiguous, or a signed-access denial path is unproved.
