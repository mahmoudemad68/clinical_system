# Phase 17 — Doctor AI

## Objective

Deliver a specialty-scoped clinical assistant for verified doctors that can answer medical questions and summarize or reason over the minimum authorized context of an active consultation. The assistant is **read/recommend only**: deterministic Laravel policies own access, FastAPI owns AI orchestration, and the doctor remains the only actor who can write clinical data.

The completed slice supports two explicit modes:

1. `GENERAL_SPECIALTY`: uses only active shared knowledge for the doctor's verified specialty plus the doctor's own private knowledge.
2. `ACTIVE_CONSULTATION`: additionally uses the current encounter and the patient history that Laravel authorizes for that encounter.

AI, Qdrant, embedding, reranking, or model-provider failure must not impair consultation, record access, prescribing, labs, queue movement, or any other core workflow.

## Plan traceability

- Section 42, lines 1452-1481: text extraction/OCR for lab and medical reports; no radiology-pixel diagnosis.
- Sections 72-73, lines 2241-2296: doctor-knowledge payload and server-built specialty/private retrieval filters.
- Section 84, lines 2504-2521: doctor-private documents stay private and need no shared-KB approval.
- Sections 87-89, lines 2578-2649: authorized clinical context, allowed capabilities, specialty restriction, and read/recommend-only writes.
- Sections 94-98, lines 2765-2878: core isolation, provider abstraction, traceability, prompt-injection defense, and conversation storage.
- Sections 102 and 104, lines 2949-2976 and 2992-3028: bounded AI queue work and reliable post-commit events.
- Sections 117, 120, 122-124, lines 3346-3367 and 3403-3514: service isolation, audit, log redaction, AI privacy, and private Qdrant access.
- Sections 132-133 and 140-143, lines 3640-3693 and 3835-3915: retrieval/first-token targets, bounded AI concurrency, separate GPU workers, health, and monitoring.
- Sections 157, 163-164, lines 4216-4239 and 4331-4366: knowledge isolation, AI evaluation, and medical-expert validation.
- Sections 171-174, lines 4503-4622: V1 exclusions, sources of truth, consistency, and background-work rules.

## Entry criteria and dependencies

- Phase 05 supplies encounters, contextual doctor access, audit identities, and explicit doctor-authored note commands.
- Phase 06 supplies prescription boundaries; AI cannot call its finalization or mutation commands.
- Phase 07 supplies scanned/text document extraction and verified file metadata.
- Phase 16 supplies versioned knowledge ingestion, hybrid retrieval, reranking, Qdrant tenant filters, provider ports, prompt versions, evaluation fixtures, and the isolated FastAPI runtime.
- Phase 01/02 guarantees that only an approved doctor account with active specialty membership reaches this feature.
- A clinical owner has approved the initial specialty taxonomy, refusal policy, and evaluation dataset governance.

## Non-goals

- No autonomous diagnosis, prescription, lab request, referral, report, encounter completion, or medical-record mutation.
- No patient-facing answer reuse; patient-safe knowledge is a separate collection and policy.
- No cross-specialty answering when the doctor's configured scope does not allow it.
- No image diagnosis or interpretation of radiology pixels. OCR text may be treated as unverified extracted text.
- No web browsing, arbitrary URL fetching, shell/code execution, SQL generation, or unrestricted agent tools.
- No automatic conversion of a conversation into the medical record.
- No citations required in the doctor UI for V1, but internal source traceability is mandatory.
- No semantic long-term chat memory in Qdrant; conversations remain in PostgreSQL.

## Architecture, ownership, and SOLID boundaries

### Module ownership

```text
Laravel Clinical module
  owns encounters, medical record, access grants, notes, allergies, medications

Laravel KnowledgeBase module
  owns document/version visibility and doctor/specialty scope metadata

Laravel AI module
  owns public doctor-AI API, authorization, context minimization,
  conversation/run metadata, audit integration, budgets and cancellation

FastAPI doctor_assistant feature
  owns prompt assembly, retrieval orchestration, structured-output validation,
  provider invocation and safe answer composition

Qdrant
  owns rebuildable vectors only; it owns no authorization or medical truth
```

The Laravel AI module may query Clinical and KnowledgeBase only through public query ports. It must not import their Eloquent models or infer access from client-supplied IDs. FastAPI receives a short-lived, signed internal request containing an already minimized context envelope and opaque authorization snapshot ID; it receives no broad database credentials.

### Owned ports

Application/domain-owned interfaces are deliberately narrow:

```text
DoctorAiAccessPolicy
  authorizeGeneral(actor_id)
  authorizeEncounter(actor_id, encounter_id)
  revalidate(snapshot_id)

AuthorizedClinicalContextReader
  readForActiveEncounter(access_snapshot_id)

DoctorKnowledgeScopeReader
  scopesForVerifiedDoctor(doctor_id)

KnowledgeRetriever
  retrieve(query, allowed_scope_ids, document_status, limit)

Reranker
  rerank(query, candidates, limit)

ClinicalLlmProvider
  generateStructured(request, deadline, cancellation)
  streamStructured(request, deadline, cancellation)

AiRunRepository
AiConversationRepository
AiTraceRepository
AiBudgetPolicy
AiOutputPolicy
```

- **Single responsibility:** access, context assembly, retrieval, generation, output validation, persistence, and clinical copying are separate handlers.
- **Open/closed:** another LLM, embedding model, reranker, or parser is an adapter that passes the same contract suite.
- **Liskov substitution:** fake, external, and self-hosted providers return the same typed success/error categories and honor deadlines/cancellation.
- **Interface segregation:** the doctor assistant does not receive pharmacy inventory, booking, prescription-write, or admin capabilities.
- **Dependency inversion:** provider SDKs, Qdrant, HTTP, Eloquent, and telemetry remain outside domain/application logic.

## Packages and runtime components

Versions are selected and locked under Phase 00 policy.

### Laravel/PHP

- Existing Laravel, Sanctum, Horizon, Reverb, PostgreSQL, outbox, audit, OpenTelemetry, and Sentry foundations.
- Symfony UID/Laravel UUIDv7 support for run/conversation IDs.
- Laravel HTTP client or an ADR-approved PSR-18 client for the internal FastAPI adapter with connection/read deadlines and mTLS/service credentials.
- Pest/PHPUnit, Laravel database fakes only at unit boundaries, and real PostgreSQL/Redis in integration tests.

### Python/FastAPI

- `fastapi`, Pydantic v2, `pydantic-settings`, `httpx`, and the provider/Qdrant adapters established in Phase 16.
- `qdrant-client` only inside the retrieval infrastructure adapter.
- OpenTelemetry FastAPI/HTTPX instrumentation and Sentry with prompt/response capture disabled.
- `pytest`, `pytest-asyncio`, `respx`, and Hypothesis for schema, adversarial, timeout, and provider-contract tests.
- Do not introduce a general autonomous-agent framework. The workflow is a bounded, explicit state machine.

### Doctor Flutter desktop

- Existing Riverpod, Dio, Freezed/JSON serialization, secure storage, Drift, localization, and error-handling packages.
- Server streaming uses one repository-owned SSE/HTTP implementation or the already approved realtime transport; provider SDKs never enter Flutter.

## Persistent schemas, invariants, and indexes

### PostgreSQL records

```text
ai_conversations
  id UUIDv7 PK
  actor_profile_id UUID FK doctor_profiles
  purpose enum DOCTOR_GENERAL | DOCTOR_ENCOUNTER
  encounter_id UUID nullable FK encounters
  specialty_id UUID FK specialties
  status enum ACTIVE | READ_ONLY | CLOSED
  created_at / last_message_at / closed_at UTC
  retention_policy_id

ai_messages
  id UUIDv7 PK
  conversation_id UUID FK
  ordinal integer
  role enum USER | ASSISTANT | SYSTEM_NOTICE
  content_ciphertext text
  content_hash bytea
  source_run_id UUID nullable
  created_at UTC

ai_runs
  id UUIDv7 PK
  conversation_id UUID FK
  actor_profile_id UUID FK
  encounter_id UUID nullable
  access_snapshot_id UUID nullable
  prompt_version string
  retrieval_config_version string
  provider_key string
  model_key string
  status enum ACCEPTED | RETRIEVING | GENERATING | SUCCEEDED |
              DENIED | CANCELLED | FAILED_RETRYABLE | FAILED_PERMANENT
  started_at / first_token_at / completed_at UTC nullable
  input_tokens / output_tokens integer nullable
  safe_error_code string nullable
  correlation_id UUID

ai_run_sources
  run_id UUID FK
  document_id UUID
  document_version_id UUID
  chunk_id string
  scope_id string
  retrieval_rank / rerank_rank integer
  score numeric nullable
  primary key (run_id, chunk_id)
```

Content encryption keys are managed outside the database. Retention is policy-driven and may require cryptographic erasure or redaction; raw messages are never copied to analytics, logs, events, or metric labels.

Recommended indexes:

- `ai_conversations(actor_profile_id, last_message_at desc)`.
- `ai_conversations(encounter_id, created_at)` where `encounter_id is not null`.
- unique `ai_messages(conversation_id, ordinal)`.
- `ai_runs(actor_profile_id, started_at desc)` and `ai_runs(status, started_at)`.
- `ai_runs(correlation_id)` and `ai_run_sources(document_id, document_version_id)`.
- Retention/partitioning is introduced only after measured volume; `ai_runs`/usage logs are candidates, not automatic day-one partitions.

### Hard invariants

1. `DOCTOR_ENCOUNTER` requires an active encounter owned by the authenticated doctor and a current authorization snapshot.
2. Client input can identify an encounter but never a patient, specialty, tenant scope, document scope, model, prompt, or tool permission.
3. Allowed retrieval is exactly the verified doctor's specialty scope union the same doctor's private scope.
4. Clinical-document chunks require the encounter's patient ID and current clinical access in addition to knowledge scope.
5. Only active document versions and successfully verified/extracted clinical documents are retrievable.
6. Retrieved content is untrusted data and cannot alter system policy, tool permissions, scope filters, output schema, or budgets.
7. No AI endpoint can write to clinical, prescription, lab, appointment, queue, or file tables.
8. A doctor-copy action is a new authenticated Clinical command. It stores `inserted_by_doctor`, `source_ai=true`, and source run ID; it never impersonates the AI as author.
9. Conversation/run creation and AI availability are eventual/optional; encounter and medical state remain strongly consistent in PostgreSQL.

## Detailed control and data flows

### 1. Start a general-specialty conversation

1. Doctor desktop submits a localized question and an idempotency key; no specialty/model/scope is accepted from the client.
2. Laravel authenticates the device session, resolves an approved doctor profile, applies AI rate/cost limits, and loads the verified specialty.
3. `DoctorAiAccessPolicy` builds server-owned allowed scopes: `specialty:<id>` and `doctor:<actor-id>`.
4. Laravel creates the conversation, user message, run, audit reference, and outbox analytics marker in one transaction.
5. The minimized internal request contains pseudonymous actor ID, specialty code, question, allowed scope IDs, prompt/retrieval versions, deadline, and correlation ID.
6. FastAPI validates schema/service authentication, rejects unknown fields, and reconstitutes no wider authority.
7. Hybrid retrieval and reranking run with mandatory scope and `status=active` filters.
8. The prompt labels every chunk as untrusted reference data and asks for the versioned structured answer schema.
9. Provider output is parsed, bounded, policy-checked, and either returned or converted to a safe validation failure.
10. Laravel stores the encrypted assistant message and trace references, marks the run terminal, and streams/returns the result.

### 2. Ask during an active consultation

1. Client sends `encounter_id`; it never sends `patient_id`.
2. Laravel locks/reads current encounter state and verifies that `Start Consultation` created the encounter, the appointment is `IN_CONSULTATION`, the doctor/location match, and the current access grant exists. `BOOKED`, `CHECKED_IN`, and `WAITING` are insufficient.
3. A short-lived access snapshot records the exact authorization basis and data categories, not the clinical values.
4. `AuthorizedClinicalContextReader` builds the minimum context required: current visit, relevant history, allergies, current medications, verified labs/reports, and no national ID, phone, name, or address.
5. Context facts carry provenance IDs and verification status. OCR text is clearly marked extracted/unverified where applicable.
6. Retrieval adds allowed private/specialty KB and patient clinical-document filters. Structured record facts remain PostgreSQL-sourced; Qdrant never replaces them.
7. Before the first response chunk, Laravel revalidates the access snapshot. Revocation cancels the run and returns `AI_CONTEXT_ACCESS_REVOKED`.
8. While streaming, encounter completion/revocation signals cancel remaining provider work. No new chunks are delivered after revocation is observed.
9. The final answer includes safe limitations and never asserts that a suggestion has been applied.

### 3. Explicitly copy a suggestion to clinical notes

1. Doctor selects text and chooses `Copy to notes`.
2. The desktop displays the exact text in an editable confirmation form; no background copy occurs.
3. Client calls the Clinical module command with encounter ID, run ID, edited text, aggregate version, and a fresh idempotency key.
4. Clinical policy re-authorizes the doctor and active/owned encounter independently of the AI run.
5. The command validates size/content constraints, writes a normal doctor-authored note with provenance metadata, audit event, and outbox event in one transaction.
6. A stale encounter version, completed encounter, or revoked access returns `409/403`; the AI module cannot override it.

### 4. Prompt injection or unsafe/out-of-scope request

1. Retrieval preserves document boundaries and treats instructions in documents as quoted data.
2. Deterministic policies reject requests for non-medical tasks, another doctor's scope, unsupported specialty, autonomous prescribing, or prohibited tools.
3. Output validation rejects tool-like payloads, hidden instructions, unsupported citations, excessive length, and schema deviations.
4. The run records a safe denial/error class and policy version without logging the sensitive question or retrieved text.
5. Repeated abuse increases actor/device throttling and emits a security signal with pseudonymous identifiers.

### 5. Dependency failure, timeout, cancellation, and saturation

- Retrieval timeout: cancel generation, mark `FAILED_RETRYABLE`, return a degraded AI-only error; do not silently answer without the expected grounding.
- Provider rate limit/transient 5xx: apply one bounded adapter policy with capped backoff/jitter only inside the request deadline; otherwise return retry guidance.
- Invalid provider output or policy refusal: do not retry as a transient failure.
- Qdrant/provider unavailable: AI readiness is degraded; core readiness remains healthy.
- Concurrency pool full or budget exhausted: reject before expensive work with `429 AI_LIMIT_REACHED` or `503 AI_CAPACITY_BUSY` and `Retry-After` when truthful.
- Client cancellation/disconnect: propagate cancellation to retrieval/provider, finalize the run as cancelled, and charge/report actual usage if known.
- Duplicate request: the actor-scoped idempotency record returns the original run; it never starts two generations.

## API, internal contract, event, and job contracts

### Public Laravel API

```text
POST   /api/v1/doctor-ai/conversations
POST   /api/v1/doctor-ai/conversations/{conversation_id}/runs
GET    /api/v1/doctor-ai/runs/{run_id}
GET    /api/v1/doctor-ai/runs/{run_id}/stream
DELETE /api/v1/doctor-ai/runs/{run_id}       # cancellation, not data deletion
POST   /api/v1/encounters/{encounter_id}/notes/from-ai
```

Run request fields are limited to `mode`, optional `encounter_id`, `question`, locale, and conversation ID. Server configuration selects model, prompts, retrieval parameters, scopes, token/cost limits, and tools. Mutation and cancellation endpoints require CSRF/device protections and authorization; run creation uses `Idempotency-Key`.

Stable error codes include `DOCTOR_NOT_APPROVED`, `AI_SCOPE_DENIED`, `ENCOUNTER_NOT_ACTIVE`, `AI_CONTEXT_ACCESS_REVOKED`, `AI_LIMIT_REACHED`, `AI_CAPACITY_BUSY`, `AI_RETRIEVAL_UNAVAILABLE`, `AI_PROVIDER_UNAVAILABLE`, `AI_OUTPUT_INVALID`, and `AI_REQUEST_CANCELLED`.

### Internal Laravel-to-FastAPI contract

`POST /internal/v1/doctor-assistant/generate` accepts a versioned Pydantic envelope, authenticated with workload identity/mTLS and a short deadline. It contains minimized facts and explicit allowed scope filters, never raw access tokens or a reusable doctor credential. FastAPI rejects direct public calls, expired signatures, unknown versions, missing correlation/deadline fields, and filters not produced by the core.

### Events and jobs

- `ai.doctor_run_succeeded.v1`, `ai.doctor_run_failed.v1`, and `ai.doctor_run_cancelled.v1` contain IDs, versions, timings, token/cost counters, and safe error class only; no prompt, response, medical text, or retrieved chunk body.
- `clinical.encounter_completed.v1` closes encounter conversations to context-free/read-only rules and revokes access snapshots.
- `knowledge.document_activated.v1` invalidates retrieval metadata caches without exposing document content.
- Retention/redaction and stale-run reconciliation jobs are idempotent, lease-based, bounded, observable, and carry stable IDs rather than payload text.

## Doctor desktop work

- Add a clearly labeled AI panel with general vs current-consultation modes; encounter mode is available only when server state says active.
- Show retrieval/generation/cancelled/degraded states, reconnect-safe run status, and explicit “not saved to record” language.
- Provide stop generation and retry-as-new-intent controls; automatic write retries are prohibited.
- Require a visible editable confirmation before copying text into notes.
- Do not persist full AI context locally. If conversation caching is needed, store the minimum encrypted content with retention and logout/device-revocation cleanup.
- Arabic/English UI strings are translated; clinical content is not machine-translated unless that behavior has separate validation and provenance.
- Accessibility covers keyboard navigation, focus order, streaming announcements without excessive screen-reader noise, contrast, and error recovery.

## Security and privacy controls

- Authorize at conversation creation, every run, context read, pre-delivery, stream subscription, cancellation, history read, and copy-to-note action.
- Derive doctor, specialty, patient, encounter, and Qdrant filters exclusively from server state.
- Use separate least-privilege service identities for Laravel-to-FastAPI, FastAPI-to-Qdrant, and provider access; rotate and audit them.
- Minimize/pseudonymize context before it crosses the AI boundary. Provider data retention, residency, training-use opt-out, deletion, and breach terms require privacy/legal approval before production.
- Encrypt message content at rest and apply explicit retention. Do not index conversations or raw patient context into Qdrant.
- Enforce prompt, retrieved-chunk, output, token, time, concurrency, and cost limits. Treat output as untrusted Markdown/text and render with links/HTML disabled or sanitized.
- Prevent SSRF by having no arbitrary fetch tool; object retrieval uses validated internal IDs and private storage adapters only.
- Audit medical context use, file/lab references, denied scope attempts, copy actions, prompt/config promotion, and message access without copying sensitive contents into the audit payload.
- Security prompts reinforce policy but never implement authorization. Deterministic code remains the enforcement point.

## Test plan

### Unit tests

- Scope union includes exactly verified specialty plus the same doctor private scope.
- Context minimizer excludes name, phone, national ID, address, unrelated history, and unverified categories.
- Capability policy rejects non-medical, out-of-specialty, autonomous prescription/write, unsupported imaging, and unknown-mode requests.
- Output validator covers malformed JSON, oversized answers, unsafe markup, fabricated source IDs, provider refusal, and partial streams.
- Budget/deadline/retry/cancellation state machines and run transitions reject skipped, stale, and duplicate terminal changes.
- Redaction/property tests use Arabic/English/Unicode clinical and identity canaries.

### Integration tests

- Real PostgreSQL verifies conversation/run/message transactions, ordinal races, encryption metadata, idempotency, retention, and access-snapshot revocation.
- Real Qdrant fixtures prove shared/private/clinical filters and active-version restrictions under hybrid retrieval.
- Redis/queue loss and duplicate event delivery do not duplicate messages or copy actions.
- FastAPI/provider adapters exercise timeout, cancellation, rate limit, invalid output, unavailable Qdrant, and trace propagation.
- Reverb/SSE stream terminates on encounter-completed revocation.

### Contract tests

- OpenAPI-generated Dart client covers run create/status/stream/cancel and every stable error.
- Internal Pydantic schema rejects extra privilege/scope/tool fields and incompatible versions.
- Every LLM/retrieval adapter passes typed errors, deadline, cancellation, token-usage, and no-silent-fallback behavior.
- Current and previous compatible event schemas replay without exposing content.

### End-to-end tests

- Approved cardiologist asks a general cardiology question and retrieves shared plus own-private knowledge only.
- After `Start Consultation`, the same doctor receives minimized patient context, copies edited text explicitly, and the note records doctor authorship plus AI provenance; the booked/checked-in/waiting states remain denied.
- Completing the encounter during generation cancels sensitive delivery and revokes later history access.
- Doctor A cannot retrieve Doctor B private document, another specialty scope, or an arbitrary patient's clinical document.
- AI/Qdrant/provider outage shows degraded AI while the doctor completes consultation, prescription, and lab flows normally.

### System and performance tests

- At the Phase 21 target concurrency, RAG retrieval p95 is at most 700 ms and first token target is at most 2-3 seconds under the approved provider/load profile.
- Saturation sheds AI work without increasing core API SLO/error rate beyond its allowed budget.
- Worker/provider restart, duplicate delivery, network partition, and client reconnect preserve one terminal run and no duplicate clinical write.
- Rebuild Qdrant from S3/PostgreSQL and rerun the evaluation set with equivalent approved retrieval quality.

### Security and clinical-AI tests

- Direct-object, tenant-filter tampering, revoked doctor, expired snapshot, forged internal token, stream hijack, and copy-command authorization tests all deny.
- Adversarial documents/questions test direct/indirect prompt injection, data exfiltration, secret requests, denial-of-wallet, unsafe markup, and fabricated tool/source identifiers.
- Versioned specialty datasets measure Recall@K, MRR, relevant-chunk rate, groundedness, hallucination, refusal correctness, latency, and cost.
- Medical experts review sampled normal, edge, multilingual, contraindication, ambiguous, and failure cases. Promotion requires their signed thresholds; model-judge scores alone cannot approve release.

## Observability, migration, and rollout

### Observability

- Metrics: run totals by safe mode/status/error, retrieval and rerank latency, first-token/total latency, input/output tokens, cost, cancellation, saturation, invalid-output rate, policy denials, and provider/Qdrant health.
- Labels are bounded configuration values; never doctor/patient/encounter/conversation IDs, prompt text, diagnosis, specialty free text, or chunk bodies.
- Traces include correlation/run IDs, prompt/retrieval versions, model key, dependency spans, and redacted authorization outcome.
- Alerts cover provider/Qdrant outage, rising invalid output or hallucination regression, isolation-test failure, queue age, budget anomaly, and cross-scope policy attempts.

### Migration and rollout

1. Expand schemas and deploy disabled APIs/adapters.
2. Seed synthetic conversations and run contract/security/evaluation suites in staging.
3. Enable `doctor_ai` for internal clinical reviewers, then a small allowlisted specialty cohort.
4. Compare latency, denials, unsafe-output reports, costs, and evaluation regressions against the approved baseline.
5. Increase cohorts only after clinical/security/privacy approval; prompt/model/retrieval changes use separate versioned flags and the same promotion gate.
6. Rollback disables new runs immediately while preserving auditable history and core workflows. Schema contraction waits for retention/migration completion.

## Acceptance and exit gate

- All two modes work for approved doctors with server-derived specialty/encounter scopes.
- Cross-doctor, cross-specialty, arbitrary-patient, revoked-encounter, and forged-tool/scope tests produce zero unauthorized disclosures.
- AI performs zero autonomous core writes; explicit copy produces a normal doctor-authored, audited clinical command.
- Prompt injection, invalid output, provider/Qdrant outage, timeout, cancellation, retry, and saturation fail safely and leave core healthy.
- Retrieval p95 and first-token targets pass under the agreed Phase 21 load profile; budget and concurrency limits are enforced.
- Versioned retrieval/clinical evaluation meets medically approved promotion thresholds, including Arabic/English and adversarial cases.
- Conversation retention, provider-processing terms, pseudonymization, logging redaction, and audit evidence have privacy/security approval.
- Dashboards, alerts, runbooks, migrations, rollback, generated-client compatibility, and all test layers above have reproducible evidence.
- No autonomous prescribing, image diagnosis, broad tools, external browsing, or other V1-excluded feature is enabled.
