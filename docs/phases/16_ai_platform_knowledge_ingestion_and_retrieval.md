# Phase 16 — AI Platform, Knowledge Ingestion, and Retrieval

## Objective

Deliver the isolated AI platform on which Doctor, Pharmacy, and Patient AI phases can safely build:

- Laravel-owned knowledge document/version/authorization metadata.
- A separately deployed FastAPI service with no Core PostgreSQL credentials.
- A typed internal command boundary and Python-owned durable worker queue, separate from Laravel Horizon.
- Quarantine-to-clean document ingestion, parsing/OCR, structure-aware chunking, BGE-M3 dense/sparse embeddings, Qdrant staging, version activation, hybrid retrieval, reranking, and rebuild.
- Provider, embedding, reranking, parser, retrieval, deterministic tool-policy, budget, trace, and evaluation services or genuine external-provider interfaces.
- Default-deny Qdrant tenant/scope filters and prompt-injection/poisoning defenses.

This phase exposes internal platform contracts and evaluation tooling, not patient/doctor/pharmacy AI user features. AI/Qdrant/provider failure must leave every Core workflow healthy.

## Plan traceability

- Sections 40-42, lines 1392-1481: private medical files, signed/quarantined upload processing, text extraction/OCR fallback, and no medical-image diagnosis.
- Sections 71-86, lines 2214-2576: collection topology, payload multitenancy, doctor/patient/pharmacy knowledge separation, clinical-document metadata, hybrid retrieval, BGE-M3, reranking, chunking, versioning, private/shared scopes, and ingestion lifecycle.
- Sections 87-89, lines 2578-2649: minimized authorized context and read/recommend-only AI boundary used by later phases.
- Sections 94-98, lines 2765-2877: Core isolation, provider abstraction, traceability, prompt injection, and PostgreSQL conversation ownership.
- Sections 102 and 104, lines 2949-2963 and 2992-3027: AI/KB work queues and transactional outbox.
- Sections 117, 119-125, lines 3346-3534: private services, throttling, audit/log privacy, provider minimization, Qdrant security, and production replication target.
- Sections 130-132 and 140-143, lines 3610-3661 and 3835-3915: Qdrant rebuild, AI failure recovery, retrieval targets, GPU-worker separation, health, and monitoring.
- Sections 156-157 and 163-165, lines 4182-4239 and 4331-4390: test layers, KB isolation, AI evaluation/clinical validation, and environment separation.
- Sections 166-169, lines 4392-4482: containers, CI, migrations, and secrets.
- Sections 171-176, lines 4503-4714: V1 exclusions, source-of-truth, consistency, background work, implementation order, and release definition.

## Entry criteria and dependencies

- Phase 00 provides service/container layout, internal-service authentication, OpenAPI/events, outbox, secrets, telemetry, CI, data classification, and the rule that Python never consumes Horizon serialization.
- Phase 07 provides private S3, quarantine/clean states, malware scan, hash, verified text/PDF/image metadata, and sandboxed extraction primitives.
- Phase 10 provides medication/pharmacy knowledge identity and governance inputs.
- Privacy and security owners document source licenses, retention, residency, external-provider processing terms, training-use policy, deletion, breach terms, and cross-border handling as configurable project policy before any regulated production data is sent externally. Use conservative defaults and keep external regulated-data transfer disabled until that configuration exists; legal sign-off is not required.
- Clinical/pharmacy owners approve KB provenance, review, activation, rollback, and evaluation datasets.
- Infrastructure approves AI-owned Redis/broker isolation, Qdrant network/storage, model artifact provenance, GPU quotas, and rebuild/runbooks.

## Non-goals

- No Doctor AI, Pharmacy AI, Patient AI triage, AI booking, autonomous agent, autonomous write, prescription generation/finalization, diagnosis, patient-specific tool, web browsing, arbitrary URL/file fetch, shell/code/SQL execution, or medical-image interpretation.
- No Qdrant as medical/operational truth and no direct client access to FastAPI/Qdrant.
- No AI service connection to Laravel Core PostgreSQL, Redis/Horizon queues, or unrestricted S3.
- No collection per doctor/specialty/tenant.
- No raw conversation semantic memory in Qdrant.
- No automatic promotion of doctor-private knowledge to shared.
- No unreviewed model/prompt/retrieval/config fallback or silent provider substitution.

## Laravel module ownership and services

### System ownership

    Laravel KnowledgeBase module
      document/scope/version metadata, upload authorization,
      clean-file eligibility, shared activation approval,
      task dispatch/status, active-version truth, audit/outbox

    Laravel AI Gateway module
      internal service credentials, future authorized context minimization,
      budgets, provider/retrieval configuration references, callbacks

    FastAPI AI platform
      typed command validation, explicit workflow orchestration,
      parser/chunker/embedding/reranker/retrieval/provider adapters,
      Python task queue, output manifests, evaluation execution

    Qdrant
      rebuildable staging/active vector points and indexed payload only

    S3
      original clean source documents; FastAPI receives one short-lived
      object reference scoped to one task

    PostgreSQL
      Core metadata/activation/audit truth; accessible only through Laravel

FastAPI has no Core database user, connection string, ORM, or shared Eloquent schema. It receives minimum commands over authenticated internal HTTP and reports results through signed callbacks or a typed status endpoint.

### Queue ownership

    Laravel transaction/outbox
      -> Laravel Horizon dispatch job
      -> POST authenticated typed command to FastAPI
      -> FastAPI validates/idempotently accepts
      -> AI-owned TaskQueue interface
      -> dedicated Python broker/Redis + Dramatiq worker
      -> Qdrant/model/parser adapters
      -> signed callback to Laravel

Horizon owns PHP jobs only. Python workers never deserialize, acknowledge, inspect, or share a consumer group with Laravel job payloads. Use a dedicated Redis instance or at minimum separate endpoint/ACL/namespace, persistence policy, quotas, and monitoring approved by operations.

### Module services and external integrations

Laravel-owned:

    Eloquent models: KnowledgeDocument, KnowledgeVersion, AiTask
    KnowledgeDocumentService
    KnowledgeAccessPolicy
    AiTaskDispatcher
      submit(command, idempotency_key, deadline)
      status(external_task_id)
    AiCallbackAuthenticator
    KnowledgeActivationPolicy

FastAPI-owned:

    TaskQueue
      enqueue(typed_task)
      cancel(task_id)

    DocumentSource
      fetchOnce(signed_object_reference, byte_limit, deadline)

    DocumentParser
      parse(document, limits)

    OcrEngine
      extract(document, limits)

    Chunker
      chunk(parsed_document, config_version)

    Embedder
      embedDenseSparse(chunks, model_version, deadline)

    VectorIndex
      stage(points, manifest)
      query(query, mandatory_filter, config)
      deleteVersion(document_version_id)

    Reranker
      rerank(query, candidates, limit, deadline)

    LlmProvider
      generateStructured(request, schema, deadline, cancellation)
      streamStructured(request, schema, deadline, cancellation)

    RetrievalFilterPolicy
      buildOrDeny(collection, signed_scope_claims, active_versions)

    ToolPolicy
      validateProposal(actor_context, tool_name, typed_arguments)

    EvaluationRunner

- ToolPolicy defaults to no permitted tools in Phase 16. Later phases register named typed tools; provider/model output never executes a call directly.
- Provider-specific tool-call, response, model, exception, and token types remain inside adapters.
- Document parsing, chunking, embedding, indexing, activation, retrieval, and evaluation are independent explicit services or bounded workflow stages.
- No general LangChain/LangGraph/autonomous-agent framework is introduced; bounded workflows remain visible and testable.

## Packages and runtime components

Versions/models are evaluated and pinned by digest/lockfile; floating model revisions and trust_remote_code are prohibited unless separately reviewed.

### Laravel/PHP

- Laravel 13 HTTP client or ADR-approved PSR-18 client with connection/read deadlines, cancellation, mTLS/workload identity, and typed adapter errors.
- Horizon for Laravel dispatch/callback reconciliation only.
- PostgreSQL, private S3/Flysystem, audit/outbox, Prometheus, Sentry with prompt/content capture disabled.
- deptrac/deptrac, Larastan/PHPStan, Pest/PHPUnit, and contract-test tooling.

### Python/FastAPI

- fastapi with Pydantic v2 and pydantic-settings for strict commands/config startup validation.
- uv and committed uv.lock for dependency resolution.
- httpx with explicit deadline/cancellation for Laravel callback, S3 signed object, and provider adapters.
- dramatiq with Redis broker for the AI-owned queue; dedicated broker credentials and message schema.
- qdrant-client inside the VectorIndex adapter only.
- FlagEmbedding with pinned BGE-M3 and bge-reranker-v2-m3 artifacts as the baseline under benchmark.
- torch/transformers required by the pinned inference path; separate GPU worker image/pool from FastAPI API.
- pypdf, pdfplumber, python-magic, and Pillow with decompression limits for approved formats.
- Tesseract/OCRmyPDF or an ADR-approved OCR service in a sandboxed worker after license/SBOM review.
- structlog with central redaction, FastAPI prometheus-client, and Sentry SDK with sensitive capture off.
- pytest, pytest-asyncio, respx, Hypothesis, testcontainers, ruff, mypy/Pyright, bandit, pip-audit, Semgrep, and retrieval metric tooling such as ir-measures or ranx.

Do not install unstructured or other broad parser/model bundles without a measured need, license review, attack-surface review, and image-size/SBOM impact.

### Qdrant

- Private internal endpoint, TLS, API key/workload identity, encrypted volumes/snapshots, memory/disk quotas, payload indexes, and no public ingress.
- Development may use one node. Production topology/replication factor at least two is selected in Phase 21 from measured capacity and recovery tests.

## Persistent schemas, invariants, and indexes

### Laravel PostgreSQL metadata

    knowledge_documents
      id UUIDv7 primary key
      owner_scope_type enum SPECIALTY | DOCTOR_PRIVATE | PATIENT_SAFE |
                            PHARMACY_SHARED | CLINICAL_DOCUMENT
      owner_scope_id UUID/string not null
      source_file_id UUID not null
      title_ciphertext/display metadata per classification
      language char(2) not null
      status enum DRAFT | PROCESSING | READY | ACTIVE | INACTIVE | FAILED
      current_active_version_id UUID nullable
      created_by UUID not null
      created_at / updated_at

    knowledge_versions
      id UUIDv7 primary key
      document_id UUID not null
      version_number integer not null
      source_file_hash bytea not null
      parser_config_version / chunk_config_version string not null
      embedding_model_version / sparse_model_version / reranker_version string not null
      manifest_hash bytea nullable
      expected_chunk_count / indexed_chunk_count integer nullable
      status enum PROCESSING | READY | ACTIVE | INACTIVE | FAILED
      approved_by / approved_at UUID/timestamptz nullable
      activated_at / deactivated_at timestamptz nullable
      failure_code string nullable

    knowledge_ingestions
      id UUIDv7 primary key
      document_version_id UUID not null
      external_task_id string nullable
      idempotency_key_hash / command_hash bytea not null
      status enum PENDING_DISPATCH | ACCEPTED | PARSING | CHUNKING |
                  EMBEDDING | INDEXING | VALIDATING | SUCCEEDED |
                  FAILED_RETRYABLE | FAILED_PERMANENT | CANCELLED
      attempt_count integer
      source_bytes / page_count / chunk_count bigint nullable
      callback_sequence bigint not null default 0
      safe_error_code string nullable
      started_at / completed_at / next_reconcile_at timestamptz nullable
      correlation_id UUID

    ai_configuration_versions
      id UUIDv7 primary key
      config_type enum PROMPT | RETRIEVAL | EMBEDDING | RERANKER | PROVIDER |
                       TOOL_POLICY | SAFETY_POLICY
      name / version string
      content_hash bytea
      status enum DRAFT | APPROVED | ACTIVE | RETIRED
      approved_by / activated_at

    ai_evaluation_runs
      id UUIDv7 primary key
      dataset_version / config_bundle_hash string
      status / started_at / completed_at
      metric_summary_json bounded
      approval_reference nullable

Indexes/constraints:

- Unique knowledge_versions(document_id, version_number).
- One ACTIVE version per document through a partial unique constraint/current pointer transaction.
- Unique ingestion(document_version_id, idempotency_key_hash); command hash mismatch conflicts.
- Ingestion status/callback sequence uses compare-and-set to reject stale/reordered callbacks.
- Index processing/next reconcile, document scope/status, active version, evaluation dataset/config.
- A source_file_id must be CLEAN/AVAILABLE and its hash must match before ingestion dispatch.

### Qdrant collections and payload

Collections:

    doctor_knowledge
    patient_knowledge
    pharmacy_knowledge
    clinical_documents

Every point payload:

    scope_type
    scope_id
    tenant_key
    specialty_id nullable
    doctor_id nullable
    patient_id nullable
    document_id
    document_version_id
    language
    source_status
    page / section / chunk_index
    chunk_hash
    ingestion_id

- scope_id/tenant_key/patient_id/doctor_id/document_version_id/source_status receive keyword payload indexes; tenant fields use Qdrant tenant indexing where appropriate.
- Point IDs are deterministic from document_version_id plus chunk_index/hash, making retries idempotent.
- Chunk text/payload includes the minimum needed for retrieval; raw originals remain S3.
- Staging version points are never retrievable by production queries. Core active-version truth is passed into every retrieval request.

### Hard invariants

1. PostgreSQL/S3 are truth; Qdrant and all embeddings can be deleted/rebuilt.
2. No ingestion begins from an unscanned/quarantined/mismatched source.
3. Doctor-private scope is exactly doctor:<id>, never shared automatically. Shared knowledge requires approved activation.
4. Patient-safe, pharmacy, doctor, and clinical collections/scopes never substitute for one another.
5. A retrieval query cannot execute without a server-signed explicit allowed scope plus active version set. Missing/empty/unknown filters fail closed.
6. Client/model/provider cannot supply or widen Qdrant filters.
7. Only READY, manifest-complete versions can activate; activation is atomic and previous version remains active until commit.
8. Retrieved documents are untrusted data. Their text cannot alter system policy, tool permissions, filters, schemas, budgets, or service credentials.
9. Parser/model/provider artifacts and configs are versioned; silent fallback is prohibited.
10. Every task, page/batch, point, callback, and activation is idempotent and deadline/budget bounded.
11. Clinical-document chunks require verified text/extraction status; radiology pixels are never interpreted.
12. AI service performs no Core write. Only an authenticated Laravel callback controller calling the owning module service changes Core metadata.

## Detailed success, failure, concurrency, and data flows

### Submit KB version

1. Doctor/admin uploads through Phase 07 quarantine; malware/type/size/hash verification completes first.
2. Laravel authenticates and derives scope from verified doctor/specialty/admin policy. Client cannot provide another owner scope.
3. Transaction creates document/version PROCESSING, ingestion PENDING_DISPATCH, audit, and outbox using source file/hash/config versions.
4. Horizon dispatch job creates a short-lived one-object read URL/reference and sends a strict internal command with idempotency key/deadline.
5. FastAPI validates service identity, schema version, signature/expiry, size/config/scope allowlist, and idempotently returns external_task_id.
6. FastAPI enqueues a typed Python-native task; Laravel records ACCEPTED through callback/status reconciliation.

Doctor-private documents need no shared-content approval, but still require malware scan, scope authorization, parsing limits, and successful ingestion. They remain private.

### Parse, chunk, embed, and stage

1. Worker fetches exactly the signed object once, enforces bytes/time, recomputes hash, and rejects mismatch.
2. Detect MIME/signature again. Parse text PDF/report; scanned documents may use bounded OCR fallback.
3. Parser/OCR runs with CPU/memory/page/pixel/recursion/time limits, no network, no macros/scripts, and a read-only temporary workspace.
4. Clean deterministic artifacts while preserving page/section/table provenance and language.
5. Structure-aware chunk Chapter/Section/Subsection/Paragraph/Table; baseline 400-800 tokens and 10-15% overlap is a versioned hypothesis.
6. BGE-M3 generates pinned dense and sparse representations in bounded batches; no arbitrary remote code.
7. Create deterministic Qdrant points under the staging document version and mandatory scope payload.
8. Validate point count/hash/metadata/embedding dimension and produce a signed manifest.
9. Callback reports status, counts, manifest hash, model/config versions, and safe error only—never document/chunk text.

### Validate and activate

1. Laravel callback verifies workload identity, task/ingestion/version binding, monotonic callback sequence, manifest/count limits, and current source hash.
2. Mark version READY, not ACTIVE.
3. Shared documents require authorized human approval; private documents require owner activation command.
4. Activation transaction locks document/current version, rechecks scope/approver/READY/manifest/Qdrant health acknowledgement, marks old ACTIVE to INACTIVE, new READY to ACTIVE, updates pointer, audit/outbox, and commits.
5. Retrieval requests immediately pass the new active version ID. Old points remain for rollback/retention and are never selected.

### Hybrid retrieval

1. Internal caller sends a signed, minimized retrieval request with collection, query, explicit allowed scopes/tenant, active version IDs, language, config version, deadline, and result limit.
2. RetrievalFilterPolicy validates issuer/purpose and builds a non-optional must-filter. It rejects missing/wildcard/client-derived scopes.
3. Generate dense and sparse query vectors with the pinned version.
4. Query top 30 dense and top 30 sparse candidates under the same mandatory filter.
5. Fuse deterministically, cap top 20, rerank, and return top 5-8 bounded chunks with source IDs/scores/provenance.
6. Later LLM phases treat chunks as quoted untrusted evidence and validate structured output.

### Failure/concurrency/retry

- Duplicate dispatch/task: return same task and deterministic points.
- Worker crash after partial Qdrant write: retry overwrites deterministic staging points; failed version stays non-retrievable.
- Callback timeout/unknown outcome: Laravel reconciles task status by external_task_id; it does not create a second version.
- Parse/malware/hash/schema/permanent model error: FAILED_PERMANENT, safe reason, no activation.
- Qdrant/provider/embedding transient outage: bounded retry/backoff in AI queue; Core stays healthy.
- Two activation attempts serialize on document/version; one current pointer wins.
- Config/model changes during task do not affect it; the task remains pinned to submitted versions.
- AI broker loss is isolated from Horizon/Core. Laravel shows delayed/failed AI task and can reconcile/resubmit same idempotency key.

### Rebuild

Select approved ACTIVE/retained versions from Laravel metadata, create typed re-ingestion commands referencing original clean S3 files, rebuild into empty/new Qdrant collections, validate manifests/evaluation, then atomically switch retrieval configuration. Loss of Qdrant never loses the medical record or original KB.

## API, event, and job contracts

### Laravel KB API

    POST /api/v1/knowledge/documents
    POST /api/v1/knowledge/documents/{document_id}/versions
    GET  /api/v1/knowledge/documents/{document_id}
    GET  /api/v1/knowledge/ingestions/{ingestion_id}
    POST /api/v1/knowledge/versions/{version_id}/activate
    POST /api/v1/knowledge/ingestions/{ingestion_id}/retry

Public clients never call FastAPI/Qdrant. Scope fields are selected from routes/server authorization, not accepted as arbitrary IDs.

Stable errors include KNOWLEDGE_SCOPE_DENIED, SOURCE_NOT_CLEAN, SOURCE_HASH_MISMATCH, INGESTION_ALREADY_EXISTS, INGESTION_LIMIT_EXCEEDED, PARSER_UNSUPPORTED, OCR_FAILED, EMBEDDING_UNAVAILABLE, QDRANT_UNAVAILABLE, INGESTION_MANIFEST_INVALID, VERSION_NOT_READY, ACTIVATION_APPROVAL_REQUIRED, CALLBACK_STALE, and AI_PLATFORM_CAPACITY_BUSY.

### Internal Laravel-to-FastAPI command

    POST /internal/v1/tasks/knowledge-ingestions
      schema_version
      task_id / ingestion_id / document_id / document_version_id
      idempotency_key / command_hash
      signed_object_reference
      expected_source_hash / byte_limit
      collection
      server_derived_scope_claims
      parser/chunk/embedding/reranker config versions
      deadline / correlation_id / callback_reference

The schema rejects unknown privilege, URL, path, credential, tool, SQL, code, model, and filter fields. FastAPI accepts only allowlisted signed-object hosts/prefixes and never follows redirects to arbitrary networks.

### Callback/status contract

    POST /internal/v1/ai-callbacks/knowledge-ingestions/{ingestion_id}
      task_id / callback_sequence / status
      manifest_hash / counts / config versions nullable
      safe_error_code nullable
      occurred_at / correlation_id

Requests use mTLS/workload identity plus signed body, timestamp/nonce replay protection, and strict task/version binding. Callback contains no content.

### Retrieval contract

    POST /internal/v1/retrieval/query
      purpose / collection / query
      signed_allowed_scopes
      active_document_version_ids
      language / retrieval_config_version
      limit / deadline / correlation_id

Response returns source/chunk IDs, bounded text, ranks/scores, version/language/verification metadata, and no broader payload. Filter construction occurs after signature validation.

### Events and jobs

- knowledge.ingestion_requested/ready/failed.v1, document_version_activated.v1, document_version_deactivated.v1, and ai.configuration_activated.v1 carry IDs/versions/counts/safe status only.
- Laravel jobs: DispatchAiTask, ReconcileAiTask, HandleAiCallback, RetentionCleanup, and RebuildRequestService. Horizon owns only these PHP jobs.
- Python jobs: ParseAndIndexKnowledge and evaluation/inference subtasks in the AI-owned queue. Their Pydantic envelope is versioned and never PHP serialized.

## Client work

### Doctor Electron desktop (React + TypeScript)

- Private KB upload/version/status/retry/activate UI using the quarantined file flow.
- Scope is shown but server-derived; no shared-publish control.
- Clear processing/ready/failed/active states, safe reason, retry-as-same-intent, and no claim that upload is searchable before activation.
- React renderer uses generated TypeScript DTOs and a narrow typed preload facade. Main owns authenticated upload/status transport and authorizes native file work; file selection returns an opaque handle, and blocking work prefers a utility process where the target-OS/ABI spike supports it without exposing arbitrary paths, object credentials, or raw IPC to the renderer.

### Admin React

- Specialty/patient-safe/pharmacy-shared document upload, provenance/version review, processing manifest summary, explicit activation, rollback to a READY prior version, and evaluation evidence.
- Admin knowledge capability is separate from generic admin and clinical access. It cannot read patient clinical documents.

### Pharmacy Electron desktop (React + TypeScript)

- No AI answer yet. Only future Phase 18 depends on pharmacy KB. Optional authorized shared-document status remains disabled unless product assigns it.
- No provider, Qdrant, object-store, filesystem, or embedding capability is exposed through preload; any future status call is a purpose-specific validated main-process capability.

All clients use Arabic/English strings, accessible progress/status/error presentation, bounded upload UX, and no local raw chunk/embedding storage. Admin remains the browser React application; patient remains Flutter mobile.

## Security and privacy controls

- Default-deny at upload, task dispatch, object fetch, callback, activation, retrieval, and every Qdrant query.
- Server derives collection/scope/tenant/active versions. Tests fail if a VectorIndex query can be issued without mandatory filter.
- Separate least-privilege identities for Laravel-to-FastAPI, FastAPI callback, AI broker, S3 object fetch, Qdrant, model registry, and telemetry; rotate/revoke/audit each.
- S3 signed references are one object, read-only, short-lived, checksum/size bound, non-redirecting, and unavailable to model/provider.
- Parser/OCR sandbox prevents SSRF, path traversal, code/macro execution, decompression/PDF/image bombs, and unbounded resource use.
- Treat all document text as hostile data; preserve boundaries/provenance and prevent it from modifying system/developer policy, tool allowlists, retrieval filters, schemas, or secrets.
- Deterministic ToolPolicy validates tool name/typed arguments/actor context at execution time. Phase 16 registers no side-effecting tools.
- Minimize data sent to external providers; no name, phone, national ID, address, raw access token, credential, or unrelated clinical facts.
- Encrypt storage/snapshots/transit, disable public Qdrant, use egress allowlists, and redact prompts/chunks/embeddings from logs/traces/errors/metrics/Sentry.
- Pin/checksum model artifacts and dependencies, generate SBOM/license reports, scan images, and prohibit trust_remote_code by default.
- Apply document/point/task/evaluation retention, deletion, legal hold, and provider terms approved before production.

## Test plan

### Unit tests

- Scope/collection mapping, clean-source eligibility, version/activation state, approval, callback monotonicity, idempotency, manifest validation, filter fail-closed, config pinning, retry classification, budgets/deadlines, and no-tool default.
- Chunk structure/overlap/token limits, deterministic point IDs, payload minimization, and provider error normalization.
- Doctor Electron renderer upload/status state, opaque file-handle flow, typed preload surface, and main sender/session/scope/schema/size/deadline validators are unit-tested without filesystem or credential access.

### Property/fuzz tests

- Random scope/version/filter combinations always stay within signed claims or deny.
- Random duplicate/reordered callbacks/tasks/points result in one valid state.
- PDF/text/Unicode/Arabic/table/parser metadata fuzzing respects byte/page/token/time limits and never constructs paths/URLs/code.
- Sensitive canaries never appear in logs/events/metrics/errors/provider payloads.

### Integration tests

- Real PostgreSQL verifies metadata/outbox/activation atomicity, one active version, callback races, source-hash binding, idempotency, and rollback.
- Real dedicated Redis/Dramatiq verifies retry, worker crash, duplicate delivery, queue isolation from Horizon, cancellation, dead-letter, and recovery.
- Real Qdrant verifies deterministic staging, dense/sparse hybrid query, payload indexes, active-version and scope filters, partial-write retry, deletion/rebuild, snapshot restore, and unavailable behavior.
- S3/ClamAV/parser/OCR fixtures verify quarantine, signed-fetch expiry/hash, redirects, malware, unsupported formats, bombs, scanned/text paths, and no imaging diagnosis.
- Electron main/preload/file-adapter integration proves cancellation/retry, opaque-handle expiry, session revocation, optional-utility crash cleanup, and zero renderer access to arbitrary paths/object credentials/raw chunks.

### Contract tests

- Laravel internal command/callback/retrieval JSON Schema and Pydantic models reject unknown privilege/filter/tool/provider/path/URL fields and incompatible versions.
- Every parser/embedder/reranker/vector/provider adapter passes typed error, deadline, cancellation, determinism/version, resource-limit, and redaction contracts.
- Current and previous compatible event schemas replay without document content.
- Prove Python cannot consume Horizon queues and FastAPI has no Core database credential/network route.
- Generated TypeScript desktop upload/status DTOs and versioned preload/IPC schemas reject raw paths, object credentials, arbitrary URLs, scope/publish flags, unregistered channels, and invalid sender frames.

### End-to-end tests

- Clean shared document processes, stages, validates, requires approval, activates, retrieves only active chunks, and rolls back to a prior version.
- Doctor-private document retrieves only for that doctor; Doctor A, Doctor B, another specialty, patient-safe, pharmacy, and clinical scopes remain isolated.
- Failed/malicious/partial document never becomes READY/ACTIVE.
- Repeated task/callback produces one version/manifest/point set.
- Qdrant/embedding/broker/FastAPI outage leaves all Core endpoints and pharmacy/clinical workflows healthy.
- Packaged doctor Electron E2E uploads an approved file through an opaque capability, observes processing/activation state, handles retry/revocation, and proves a hostile renderer cannot read the path, token, raw document, chunk, or embedding.

### System, performance, recovery, and AI evaluation tests

- Hybrid retrieval p95 is at most 700 ms under the approved production-shaped corpus/filter/concurrency profile; first-token testing belongs to later feature phases.
- Evaluate BGE-M3/reranker against versioned Arabic/English medical/pharmacy datasets using Recall@K, MRR, relevant-chunk rate, latency, and resource/cost.
- Adversarial corpus covers direct/indirect prompt injection, poisoning, scope canaries, conflicting instructions, exfiltration bait, malformed tables, and denial-of-resource.
- Rebuild Qdrant from S3/PostgreSQL metadata and demonstrate manifest parity plus approved retrieval-metric tolerance.
- Stress ingestion/model GPU/broker/Qdrant saturation; apply bounded queues/load shedding without Core SLO impact.

### Security tests

- Cross-tenant filter omission/tampering, forged service identity/callback, replay, SSRF/redirect, path traversal, parser RCE payloads, decompression bombs, malicious model artifact, secret/log leakage, Qdrant public access, broker confusion, and denial-of-wallet all fail safely.
- SAST, dependency audit, container/model SBOM, license, secret, IaC, and image vulnerability gates contain no unresolved critical/high finding.

## Observability, migration, and rollout

### Observability

Metrics include task/ingestion count/status/error, queue depth/age/retry/dead-letter, bytes/pages/chunks buckets, parse/OCR/embed/index/retrieval/rerank latency, GPU/CPU/memory saturation, Qdrant requests/errors/storage, filter denials, active-version/config counts, evaluation regressions, and estimated provider cost. Never label by user/doctor/patient/document/scope ID, title, query, chunk, prompt, or clinical content.

Traces carry correlation/task/ingestion/config/model/version IDs and safe status only. Readiness distinguishes FastAPI process, task acceptance, broker, Qdrant, model workers, and optional providers; AI degradation never makes Laravel Core unready.

Alerts cover task age/backlog, repeated permanent parse failures, callback authentication/replay, filter-denial anomaly, Qdrant/broker/model outage, storage growth, GPU saturation, evaluation regression, unexpected provider spend, and Core latency correlation.

### Migration and rollout

1. Expand metadata/config/evaluation schemas and deploy Laravel APIs disabled.
2. Deploy isolated FastAPI/broker/Qdrant/model workers with synthetic documents and no external provider/production data.
3. Prove queue separation, service network policy, filter default deny, quarantine, manifest, rebuild, and security suites.
4. Enable private/shared ingestion for internal reviewers; keep activation manual.
5. Benchmark/evaluate Arabic/English retrieval and approve pinned models/configs.
6. Enable one knowledge class/cohort at a time. Clinical documents require the strictest privacy/authorization gate.
7. Rollback disables dispatch/retrieval, leaves Core healthy, retains active metadata/original S3, and rebuilds or removes Qdrant points by version. No destructive Core migration is needed.

## Acceptance and exit gate

- FastAPI/Python workers have no Core PostgreSQL credential/route and consume no Horizon/PHP job.
- Every ingestion begins with a clean, hash-bound source and ends in a validated manifest before explicit activation.
- Active-version switching is atomic; failed/partial/staging/old versions are not retrieved.
- All Qdrant requests are default-deny and server-filtered; cross-doctor/specialty/patient/knowledge-class tests disclose zero unauthorized chunk.
- Prompt-injection/poisoning/tool-proposal/model output remains untrusted and deterministic ToolPolicy permits no Phase 16 side effect.
- Qdrant/broker/FastAPI/model/provider loss leaves Core fully functional and rebuild from S3/PostgreSQL metadata passes.
- Hybrid retrieval p95 and approved Arabic/English retrieval metrics meet thresholds under the defined load/corpus.
- Unit, property/fuzz, integration, contract, E2E, system/load/recovery, AI evaluation, security/privacy, SBOM/license, migration/rollback, dashboards/alerts/runbooks, and clinical review evidence passes. Missing legal sign-off never blocks completion.
- No user-facing AI, autonomous tool/write, image diagnosis, browsing, semantic chat memory, per-tenant collections, or other future feature is enabled.
