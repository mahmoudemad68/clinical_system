---
name: clinic-ai-platform
description: Implement or refactor the clinic project's isolated FastAPI, AI workers, and CLEAN/hash-bound knowledge ingestion through parser/OCR orchestration, cleaning, chunking, embedding, Qdrant, hybrid retrieval, and provider adapters. Use for Phase 16 infrastructure and contracts; raw upload/quarantine/trusted-file release belongs to clinic-secure-files. Not for persona behavior or AI evaluation approval.
---

# Clinic AI Platform

Build the rebuildable AI substrate while preserving Laravel/PostgreSQL/S3 as the authority and keeping every core workflow independent from AI.

## Read the required sources

Read completely before changing platform code:

- [Roadmap and invariants](../../../docs/phases/README.md)
- [Cross-cutting service, queue, contract, and data-ownership rules](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- [Files, extraction, and quarantine boundary](../../../docs/phases/07_labs_files_reports_and_referrals.md)
- [AI platform, ingestion, and retrieval](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md)

For material runtime work also read the AI portions of [performance/resilience](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), [security/privacy validation](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and [rebuild/production](../../../docs/phases/23_disaster_recovery_release_and_production.md).

Inspect current internal schemas, Laravel KnowledgeBase/AI gateway ports, FastAPI feature layout, Python queue, model/config lockfiles, Qdrant collection definitions, test fixtures/evaluation harness, telemetry, ADRs, and local changes.

## Ownership

Own platform mechanics and their typed boundaries:

- FastAPI configuration, internal authentication, request/task validation, health/readiness, deadlines, cancellation, and graceful shutdown;
- the AI-owned `TaskQueue`, Python-native worker envelopes, retries, leases, checkpoints, and dead-letter/reconciliation behavior;
- bounded document fetch, parser/OCR sandbox, cleaning, structure-aware chunking, dense/sparse embedding, staging/index validation, activation support, and rebuild;
- Qdrant collection/payload/index adapters, mandatory filter construction, hybrid retrieval/fusion/reranking, and source provenance;
- LLM, embedder, reranker, parser, OCR, vector index, object-source, provider, and evaluation-runner ports/adapters;
- model/prompt/retrieval/provider/config artifact versioning and trace-compatible platform metadata;
- unit/property/integration/contract/system/security/performance tests for the platform.

The platform may expose evaluation execution primitives and raw metric artifacts. It does not set clinical thresholds or approve promotion.

## Boundaries

- FastAPI has no Core PostgreSQL credentials, Eloquent/shared ORM, broad S3 credential, Laravel Redis/Horizon consumer, or direct client endpoint.
- Laravel owns upload/scope/version metadata, active-version truth, authorization, context minimization, Core writes, audit, and public APIs. FastAPI changes Core metadata only through an authenticated typed callback handled by Laravel.
- PostgreSQL is operational/medical metadata truth; S3 is original-file truth; Qdrant/embeddings are rebuildable indexes.
- Python jobs use the AI-owned broker/namespace/credentials and typed Pydantic envelopes. Never serialize or consume Horizon/PHP jobs.
- Missing, empty, wildcard, client/model-created, expired, or incompatible scope/filter claims fail closed before Qdrant query.
- Phase 16 registers no product tools. Do not implement doctor/pharmacy/patient prompts, red flags, booking, inventory tools, clinical copy actions, or user-facing conversations here.
- Do not add a general autonomous-agent framework, dynamic code, arbitrary URL/file fetch, browser, shell, SQL, or `trust_remote_code` path.
- No silent model/config/provider fallback. A behavior change is versioned, evaluated by the evaluation-governance skill, and separately promoted.

## Platform invariants

- Ingestion starts only from a clean, scanned, hash-matching source and a server-derived scope.
- Every task, callback, batch, Qdrant point, activation, and rebuild action is idempotent and bound by bytes/pages/pixels/tokens/time/memory/concurrency/cost.
- Parser/OCR workers have no general network, execute no macros/scripts, use a read-only/ephemeral workspace, and defend against traversal, SSRF, decompression/PDF/image bombs, and unsafe metadata.
- Point IDs are deterministic. Incomplete/staging/failed versions are not production-retrievable.
- Activation is atomic: the prior active version remains usable until the new manifest-complete version commits.
- Retrieval uses pinned dense and sparse models, one mandatory scope/version filter, deterministic fusion, bounded candidates, reranking, and bounded provenance-preserving results.
- Retrieved text is hostile data and cannot alter policies, filters, schemas, tool permissions, budgets, credentials, or system instructions.
- Prompt/chunk/embedding/document content and regulated identifiers do not enter logs, traces, Sentry, events, metrics labels, or unsafe error responses.
- AI/Qdrant/broker/GPU/provider failure degrades AI only; Laravel core readiness remains healthy.

## Implementation workflow

1. Identify the owned platform capability, its Phase 16 acceptance rule, trust boundaries, data classification, failure semantics, and consuming product contract.
2. Inspect the relevant port and adapter. Add or narrow a port only when the capability has a distinct reason to change; keep provider SDK types inside infrastructure.
3. Define strict versioned command/callback/retrieval schemas, idempotency identity, deadlines/cancellation, resource budgets, typed errors, and safe telemetry before implementation.
4. Implement the explicit bounded workflow. Persist/checkpoint durable state before remote work and make duplicate/reordered/crash recovery deterministic.
5. Validate scope and source at every boundary, not only in prompts. Recheck hashes/manifests/counts/config versions before marking ready.
6. Add focused tests with real PostgreSQL/S3 emulator/AI broker/Qdrant where the behavior depends on them; use provider fixtures rather than live services in unit tests.
7. Run the platform evaluation harness to detect retrieval regressions, but hand the evidence to `clinic-ai-evaluation-governance` for thresholds and promotion.

If a task requires persona policy, a clinical/business tool, or a public client flow, define the platform contract and hand the behavior to `clinic-ai-products`.

## Verification

At minimum, verify:

- strict schema/config startup validation, format/lint/type/static/security checks, locked dependencies/models, SBOM/license, and reproducible worker/API images;
- unit/property tests for scope filtering, version/activation state, deterministic IDs, callback ordering, budgets/deadlines/retries/cancellation, and no-tool default;
- parser/OCR fuzz and sandbox tests for malformed/hostile PDF/image/text/Arabic/Unicode inputs and resource exhaustion;
- integration tests for clean-source fetch, hash mismatch, partial write/crash replay, AI-broker isolation, Qdrant staging/active filters, duplicate tasks, callbacks, and rebuild;
- contract tests for Laravel commands/callbacks/retrieval and every provider/embedder/reranker/parser/vector adapter, including typed timeout/rate-limit/invalid-output behavior;
- system tests proving Qdrant/provider/broker/GPU outage leaves Core healthy and Qdrant can be rebuilt from PostgreSQL/S3;
- retrieval latency/capacity evidence under the Phase 21 profile and no Core pool starvation;
- adversarial tests for cross-scope access, prompt/KB injection, SSRF/path/code attempts, credential leakage, unsigned callbacks, and public Qdrant/FastAPI access;
- no user-facing AI policy, autonomous side effect, evaluation self-approval, or source-of-truth migration entered the platform layer.

Observability/performance owns cross-system SLO/load design, security assurance independently validates the final boundary, production/DR owns operational restore/release, and AI evaluation governance owns promotion decisions.
