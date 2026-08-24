# Phase 18 — Pharmacy AI

## Objective

Deliver a branch-aware assistant for approved pharmacy users that combines two strictly separated capabilities:

1. grounded educational answers from the versioned `pharmacy_knowledge` collection; and
2. least-privilege, read-only tools that query current catalog and inventory facts from Laravel/PostgreSQL.

The model may propose a tool call, but it never owns authorization, tenant/branch selection, medication identity, SQL, inventory calculation, or side effects. Stock remains a strongly consistent operational fact, not vector content. An unavailable AI subsystem must not impair catalog, inventory, purchasing, POS, invoice, return/refund, alert, or patient medicine-search workflows.

## Plan traceability

- Sections 48-55, lines 1607-1824: pharmacy/branch tenancy, medication master, packaging, batches, FEFO, ledger, and alerts.
- Section 61, lines 1966-1988: owner visibility across authorized branches and explicit exclusion of branch transfers.
- Section 70, lines 2195-2213: PostgreSQL medication search remains the catalog lookup authority.
- Sections 76-77, lines 2336-2386: pharmacy knowledge content and the mandatory split between RAG knowledge and live inventory tools.
- Sections 79-83, lines 2403-2503: hybrid retrieval, reranking, chunking, and active document versions.
- Sections 94-98, lines 2765-2878: AI isolation, provider port, traceability, prompt-injection defense, and PostgreSQL conversation storage.
- Section 107, lines 3081-3108: replay protection for accepted AI runs and any future side-effecting tools.
- Sections 117, 120, 122-125, lines 3346-3535: private services, auditing/redaction/privacy, and Qdrant security/scaling.
- Sections 132-133 and 140-143, lines 3640-3693 and 3835-3915: AI latency/concurrency, separated workers, health, and monitoring.
- Sections 159 and 163-164, lines 4254-4267 and 4331-4366: pharmacy correctness, AI evaluation, and qualified clinical/pharmacy validation.
- Sections 170-174, lines 4484-4622: future flags/exclusions, authoritative data, consistency, and background-work rule.

## Entry criteria and dependencies

- Phases 10-13 provide approved pharmacy organizations, branch memberships, medication identities/packaging, ledger-derived balances, batches, FEFO, purchasing, and POS invariants.
- Phase 14 provides normalized medication resolution and search aliases.
- Phase 15 defines native versus integrated pharmacy sources and freshness semantics.
- Phase 16 provides the isolated AI runtime, pharmacy collection, ingestion/versioning, hybrid retrieval, provider ports, trace records, and evaluation harness.
- Pharmacy and clinical reviewers approve the V1 knowledge taxonomy, response limitations, and evaluation governance.

## Non-goals

- No stock, batch, price, payment, invoice, purchase, or sale fact stored or answered from Qdrant.
- No inventory adjustment, purchase, sale, cancellation, refund, return, transfer, reservation, or supplier action through AI.
- No medication alternatives/substitution feature, medicine reservation, branch transfer, supplier API integration, or automated ordering.
- No patient, prescription, medical-record, doctor-private-KB, or patient-KB access.
- No autonomous dosing decision or definitive patient-specific medical advice.
- No arbitrary SQL, URLs, file access, shell/code execution, or open-ended plugin/tool registry.
- No silent answer when required live inventory data is stale or unavailable.

## Architecture, ownership, and SOLID boundaries

### Ownership

```text
MedicationCatalog module
  owns medication identity, aliases, barcode and packaging conversion

Inventory module
  owns batches, expiry eligibility, immutable movements and balances

Pharmacies module
  owns organization/branch membership and selected branch context

KnowledgeBase module
  owns active pharmacy documents and ingestion state

Laravel AI module
  owns public API, deterministic tool registry, tool authorization,
  budgets, conversation/run/tool trace and FastAPI adapter

FastAPI pharmacy_assistant
  owns bounded intent proposal, retrieval orchestration and answer composition
```

FastAPI cannot connect to core PostgreSQL. It asks Laravel to execute a named, versioned tool with typed arguments and a short-lived opaque execution grant. Laravel reloads current membership and state and authorizes again immediately before every tool execution.

### Ports and capability registry

```text
PharmacyAiAccessPolicy
  authorizeConversation(actor_id, selected_branch_id)
  authorizeTool(actor_id, branch_id, capability)

MedicationResolver
  resolveExactOrCandidates(normalized_query, barcode?)

BranchInventoryReader
  availableStock(branch_id, medication_id, as_of)
  batchExpirySummary(branch_id, medication_id, as_of, window_days)

PharmacyKnowledgeRetriever
  retrieve(question, allowed_scope, active_only, limit)

PharmacyLlmProvider
AiOutputPolicy
AiRunRepository
AiToolInvocationRepository
```

V1 registry capabilities are intentionally small:

| Tool | Side effect | Scope | Output |
| --- | --- | --- | --- |
| `resolve_medication.v1` | none | Egyptian master catalog | exact ID or bounded candidates |
| `get_branch_stock.v1` | none | one currently authorized branch | smallest-unit available quantity, display conversions, as-of time, freshness |
| `get_batch_expiry_summary.v1` | none | one currently authorized branch | aggregate non-sensitive batch counts by expiry window |

The registry defines typed schema, side-effect class, maximum rows/output bytes, deadline, rate/cost class, allowed roles, audit fields, and safe errors. Unknown tools fail closed. Tool results are data; they cannot add tools or permissions.

SOLID application:

- **Single responsibility:** intent proposal, authorization, medication resolution, inventory read, retrieval, answer validation, and persistence are separate components.
- **Open/closed:** a new read capability requires a new reviewed registry entry/adapter rather than changes to a god tool.
- **Liskov substitution:** native and integrated inventory readers pass identical availability/freshness contracts.
- **Interface segregation:** AI receives read interfaces only; POS and inventory command ports are absent from its dependency graph.
- **Dependency inversion:** domain/application layers own the interfaces; Eloquent, Qdrant, provider SDK, and HTTP adapters implement them.

## Packages and runtime components

Versions are pinned through Phase 00.

### Laravel/PHP

- Existing Laravel/PostgreSQL/PostGIS, Sanctum, audit, outbox, Horizon, OpenTelemetry, and AI internal-client packages.
- Existing medication normalization, exact decimal quantity/unit value objects, and UUIDv7 infrastructure.
- JSON Schema/OpenAPI validation for capability inputs and outputs; avoid runtime evaluation or reflection-based execution of model content.

### Python/FastAPI

- Phase 16 FastAPI, Pydantic, HTTPX, Qdrant, provider, telemetry, and test baseline.
- Pydantic discriminated unions for `KnowledgeAnswer`, `ToolProposal`, `Clarification`, and `SafeRefusal`.
- No autonomous-agent framework, dynamic code loader, browser, SQL toolkit, or provider-hosted tool that bypasses Laravel.

### Pharmacy Flutter desktop

- Existing Riverpod/Dio/Freezed/localization/error handling and generated OpenAPI client.
- Barcode input is handled by the catalog feature; the AI UI receives only the resolved server result.

## Persistent schemas, invariants, and indexes

Phase 16/17 conversation/run tables are reused with `purpose=PHARMACY`. Add only capability-specific trace state:

```text
ai_tool_invocations
  id UUIDv7 PK
  run_id UUID FK ai_runs
  ordinal smallint
  tool_name string
  tool_schema_version integer
  actor_profile_id UUID FK
  organization_id UUID FK
  branch_id UUID FK
  authorization_snapshot_id UUID
  argument_hash bytea
  result_reference_hash bytea nullable
  status enum PROPOSED | AUTHORIZED | SUCCEEDED | DENIED |
              FAILED_RETRYABLE | FAILED_PERMANENT | CANCELLED
  safe_error_code string nullable
  started_at / completed_at UTC nullable
  correlation_id UUID
```

No raw question, model rationale, SQL, tool arguments containing free text, inventory payload, or result body is stored in the invocation audit. Full encrypted conversation content follows the approved AI retention policy.

Indexes:

- unique `ai_tool_invocations(run_id, ordinal)`.
- `ai_tool_invocations(branch_id, started_at desc)` for authorized operational audit.
- `ai_tool_invocations(tool_name, status, started_at)` for bounded operational analysis.
- `ai_tool_invocations(correlation_id)` for incident trace.
- Existing inventory indexes on `(branch_id, medication_id)`, `(branch_id, expiry_date)`, and ledger/balance reconciliation remain authoritative.

### Hard invariants

1. The authenticated pharmacy membership, organization, and active selected branch are loaded by Laravel; model/client values never establish them.
2. Pharmacy owners may select only their own branches. Branch users remain restricted to assigned branches.
3. Tool execution re-authorizes current membership even when a proposal was previously authorized.
4. `available_quantity` is computed from authoritative balance/batches, excludes expired or otherwise unsellable quantities, and includes an `as_of` timestamp.
5. Quantity uses the smallest tracked unit and exact integer/decimal-safe conversion; generated text cannot perform authoritative arithmetic.
6. Integrated mirror results carry source and freshness. `STALE` data is never phrased as confidently available.
7. Maximum tool calls, model turns, rows, output bytes, tokens, cost, and wall-clock duration are deterministic configuration.
8. The model cannot invoke the same tool repeatedly to evade output limits; duplicate semantic calls are coalesced or rejected.
9. Knowledge answers use only active pharmacy-knowledge versions. Inventory results never enter Qdrant or model memory beyond the current bounded run.
10. Every answer labels whether facts came from knowledge, live native stock, or a possibly stale integration mirror.

## Detailed control and data flows

### 1. Grounded pharmacy-knowledge answer

1. Pharmacy desktop submits a question with selected branch context already established by the normal app session.
2. Laravel authenticates, reloads approved pharmacy membership, applies AI rate/cost policy, and creates a run under an actor-scoped idempotency key.
3. A deterministic pre-router chooses `KNOWLEDGE`, `INVENTORY`, `MIXED`, `CLARIFY`, or `DENY`; an LLM classification is only an untrusted proposal.
4. For `KNOWLEDGE`, Laravel sends only the question, locale, allowed `pharmacy_knowledge` scope, versions, budgets, and correlation ID to FastAPI.
5. FastAPI performs hybrid retrieval/reranking with `status=active`, marks chunks as untrusted references, and requests a constrained answer.
6. Output policy checks sources, limits, unsafe certainty, substitution language, and schema before storing/returning it.
7. Trace records document source IDs/model/prompt/latency without copying knowledge text into events or logs.

### 2. Successful stock question

Example: “How many boxes of Panadol 500 are available in this branch?”

1. The router recognizes that live state is required and prohibits a RAG-only answer.
2. Medication resolution uses the canonical PostgreSQL catalog. Zero matches asks for clarification; multiple matches returns a bounded candidate selection and executes no stock tool.
3. Once exact medication ID is established, FastAPI may propose `get_branch_stock.v1` without selecting a branch.
4. Laravel inserts `PROPOSED`, loads current session branch/membership, replaces any proposed scope with server-owned scope, validates tool schema and budget, and records `AUTHORIZED`.
5. The Inventory query executes in a read-only transaction against current balances/batches. It returns exact smallest-unit quantity, approved display conversions, source mode, `as_of`, and freshness.
6. Result schema/output size is validated before returning to FastAPI. No raw rows or unrelated batches are exposed.
7. FastAPI verbalizes the validated result without recalculating quantity. Laravel validates that the structured numbers remain identical before presentation.
8. Invocation becomes `SUCCEEDED`; the answer visibly shows branch and as-of time.

### 3. Knowledge plus live data

For a mixed question, knowledge retrieval and one authorized inventory read may execute in parallel only after medication identity is exact. Both have individual deadlines and a shared run deadline. The answer keeps provenance distinct. If knowledge succeeds but live stock fails, it may return the knowledge portion with an explicit “live stock unavailable” state only when that partial-result behavior is declared in the response schema; it may not invent or cache a quantity.

### 4. Denied or ambiguous request

- Patient/prescription/medical-record request: deny without probing resource existence.
- Another organization/branch request: ignore model/client branch argument, deny, audit the scope attempt, and increase throttling on repetition.
- Sell, adjust, refund, reorder, transfer, reserve, or substitute request: return a stable capability refusal and route the user to the normal audited UI if an allowed manual workflow exists.
- Ambiguous brand/strength/form/package: present catalog candidates; never guess medication identity.
- Out-of-domain or patient-specific clinical advice: safe refusal/escalation wording approved by pharmacy/clinical reviewers.

### 5. Failure, concurrency, and replay

- Same idempotency key/request hash returns the original run; different hash returns `409`.
- Two tool proposals for the same run/ordinal cannot both execute because of the unique key and state transition lock.
- Membership revoked between proposal and execution causes `DENIED`; no cached authorization is accepted.
- Native DB timeout returns tool-unavailable and no quantity. Integrated mirror marked stale returns explicit uncertainty according to Phase 15 policy.
- Qdrant/provider failure affects knowledge composition only; normal stock UI/API remains available.
- Client disconnect propagates cancellation; completed read traces may remain, but no additional calls start.
- Saturation rejects new AI runs before tool/database amplification and preserves POS/inventory DB pools.

## API, tool, event, and job contracts

### Public Laravel API

```text
POST   /api/v1/pharmacy-ai/conversations
POST   /api/v1/pharmacy-ai/conversations/{conversation_id}/runs
GET    /api/v1/pharmacy-ai/runs/{run_id}
GET    /api/v1/pharmacy-ai/runs/{run_id}/stream
DELETE /api/v1/pharmacy-ai/runs/{run_id}
```

The client may send question, locale, optional exact medication selection from a prior server response, and idempotency key. It cannot send organization scope, tool name, SQL/filter, provider/model, Qdrant scope, or result override.

Stable errors include `PHARMACY_NOT_APPROVED`, `BRANCH_SCOPE_DENIED`, `MEDICATION_NOT_RESOLVED`, `AI_TOOL_NOT_ALLOWED`, `AI_TOOL_BUDGET_EXCEEDED`, `INVENTORY_UNAVAILABLE`, `INVENTORY_DATA_STALE`, `AI_PROVIDER_UNAVAILABLE`, and `AI_OUTPUT_INVALID`.

### Internal tool execution contract

```text
POST /internal/v1/ai-tools/execute
  run_id
  proposed_tool {name, schema_version, arguments}
  opaque_execution_grant
  deadline_at
  correlation_id
```

This endpoint is private workload-to-workload only. The tool registry resolves the implementation by a constant allowlist, validates typed arguments, discards client/model scope, reloads authorization, applies a read-only transaction and statement timeout, validates bounded output, and records a terminal invocation. There is no generic URL, class name, method name, SQL, or code field.

### Events and jobs

- `ai.pharmacy_run_succeeded.v1`, `ai.pharmacy_run_failed.v1`, and `ai.tool_invocation_denied.v1` contain safe IDs, tool/version, status, latency, and error class only.
- Membership/branch revocation closes or disables affected active conversations.
- Retention, stale-run reconciliation, and evaluation-regression jobs are bounded/idempotent.
- No AI event mutates inventory. Inventory changes continue exclusively through existing domain commands/events.

## Pharmacy desktop work

- Add an AI panel that distinguishes “knowledge answer” from “live branch data” using accessible labels, source type, selected branch, freshness, and as-of time.
- Force explicit medication selection on ambiguity; show brand, strength, dosage form, manufacturer/package, and barcode where appropriate.
- Never show a generated quantity until the structured server response validates against the tool result.
- Display degraded/stale/permission-denied states without replacing the normal inventory/POS UI.
- Provide stop/retry controls; do not persist hidden tool payloads or unrestricted conversation context locally.
- Localized Arabic/English messages must retain medication/unit precision; quantities and packaging use domain formatting rather than model-generated conversions.

## Security and privacy controls

- Authenticate and authorize conversation, run, stream, history, cancellation, medication resolution, and every tool execution.
- Use deny-by-default capability registry, service identities, mTLS/signed short-lived grants, network isolation, request/output limits, and statement timeouts.
- Keep model/provider credentials outside Laravel clients and pharmacy devices; keep database credentials outside FastAPI.
- Defend against direct/indirect prompt injection, cross-tenant requests, tool-name/argument injection, excessive tool loops, denial-of-wallet, malformed Unicode, unsafe Markdown/links, and inventory inference across branches.
- Do not send patient, prescription, medical-record, national-ID, owner phone, or employee personal data to Qdrant/provider.
- Audit access/denials without logging questions, answers, stock payloads, tokens, or knowledge text. Metrics use bounded tool/version/status labels only.
- Provider contracts and data retention/residency require privacy/legal review. Pharmacy/clinical content requires qualified local review; the engineering team does not infer regulatory approval.

## Test plan

### Unit tests

- Router distinguishes knowledge/live/mixed/clarify/deny and defaults safely on uncertainty.
- Registry rejects unknown tools, versions, extra arguments, writable capabilities, invalid medication IDs, excessive rows, and expired grants.
- Medication ambiguity, package conversion, expired exclusion, freshness wording, partial-result schema, and structured-number preservation.
- Tool budget, loop detection, cancellation, retry classifier, state machine, and redaction/property tests.

### Integration tests

- Real PostgreSQL verifies current native stock, expired batches, packaging, actor/branch membership, statement timeouts, revoked membership, and invocation races.
- Integrated mirror fixtures verify fresh/stale/unavailable semantics without Qdrant fallback.
- Real Qdrant proves pharmacy-only active-version filters and isolation from doctor/patient collections.
- Provider and internal HTTP adapters test timeout, cancellation, malformed proposal/output, duplicate delivery, and trace propagation.

### Contract tests

- OpenAPI-generated Flutter client covers conversation/run/ambiguity/tool-source/error schemas.
- Every inventory reader (native and integrated mirror) passes identical quantity/source/freshness contracts.
- Capability schemas reject scope/SQL/URL/code fields and bound result size.
- LLM/retrieval adapters pass deadline, cancellation, error taxonomy, token accounting, and no-silent-fallback requirements.

### End-to-end tests

- Branch user gets a grounded contraindications answer and current stock for only the assigned branch.
- Pharmacy owner switches among owned branches; each answer names the correct branch/freshness and cannot aggregate an unauthorized branch.
- Ambiguous product asks for selection; expired stock is not reported as available; stale integrated data is not presented confidently.
- Requests to sell, adjust, transfer, reserve, refund, substitute, or inspect patient data are denied and cause no state change.
- AI outage leaves catalog, inventory, purchasing, POS, refunds, alerts, and patient medicine search operational.

### System, load, and security tests

- AI concurrency and repeated questions cannot exhaust the POS/inventory connection pool; load shedding activates before core SLO breach.
- Retrieval and first-token latency meet Phase 21 targets under the approved pharmacy scenario.
- Prompt-injection corpora attempt cross-tenant extraction, tool override, loop amplification, fake inventory, unsafe links, and secret disclosure; deterministic controls prevent them.
- Direct tool endpoint, forged grant, branch-ID tampering, role revocation, replay, response substitution, and Qdrant-public-access tests deny.
- Pharmacy domain regression suite still proves FEFO, expired-sale prevention, cancellation reversal, partial receive, refund uniqueness, and connector idempotency.
- Versioned evaluation measures retrieval Recall@K/MRR/relevance, groundedness, hallucination, tool selection/argument correctness, refusal, latency, and cost; pharmacy/clinical reviewers approve promotion thresholds.

## Observability, migration, and rollout

### Observability

- Metrics: runs by safe mode/status, knowledge retrieval/rerank latency, tool proposals/authorization/denials, tool latency/error, ambiguity rate, stale data, invalid output, token/cost, saturation, first-token/total latency.
- Alerts: cross-branch denial spike, tool-loop attempts, stock-tool mismatch, stale integration surge, provider/Qdrant outage, DB pool pressure attributable to AI, and evaluation regression.
- Traces correlate run/tool/core query using safe IDs and versions; no question, answer, medication free text, branch name, quantity payload, or patient data leaves the approved store.

### Migration and rollout

1. Expand shared AI tables with pharmacy purpose and invocation traces; deploy registry with all capabilities disabled.
2. Validate native/integrated contract suites using synthetic catalog, branches, batches, and mirror data.
3. Enable knowledge-only mode for internal pharmacy/clinical reviewers.
4. Enable read-only stock tool for a small allowlisted native branch cohort, then a separate integrated cohort after freshness tests.
5. Compare tool result to normal inventory API and alert on any mismatch; no generated result is treated as source of truth.
6. Rollback disables new AI runs/tools instantly while normal pharmacy flows continue; retain auditable traces according to policy.

## Acceptance and exit gate

- Pharmacy knowledge answers use only active pharmacy scopes and pass approved retrieval/grounding/refusal thresholds.
- Live quantity is sourced only from authorized Laravel inventory queries, preserves exact structured values, identifies branch/as-of/freshness, and never comes from Qdrant.
- Cross-organization/branch, patient-data, writable-tool, forged-grant, prompt-injection, replay, and loop tests yield zero unauthorized read or mutation.
- AI saturation/outage does not breach POS, inventory, purchasing, refund, or medicine-search SLO/error budgets.
- Native and integrated readers pass the same adapter contracts; stale mirrors never produce confident availability.
- All unit, integration, contract, E2E, system, performance, security, and pharmacy/clinical evaluation evidence is reproducible.
- Dashboards, alerts, redaction, retention, privacy review, migration, feature-flag rollout, and rollback runbooks are approved.
- Alternatives, reservation, transfers, supplier automation, autonomous ordering/sale/refund, and all other V1-excluded capabilities remain disabled and absent from the tool registry.

