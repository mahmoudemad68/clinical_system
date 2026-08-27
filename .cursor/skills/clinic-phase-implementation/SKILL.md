---
name: clinic-phase-implementation
description: Coordinate implementation of a numbered clinic roadmap phase (00-23) or an explicit multi-phase roadmap request, composing the applicable specialist skills. Use only when the user asks to start, continue, implement, or close phases or the roadmap; a phase number used merely as context for one bounded feature, test, or review does not trigger this coordinator.
---

# Clinic Phase Implementation

Orchestrate one evidence-gated roadmap phase at a time. Route implementation decisions to the mapped lead and companion skills; do not become the owner of architecture, business rules, database consistency, clients, AI, tests, security, observability, or release operations.

## Project delivery stack

When composing skills, keep these project rules:

- First-party UI is **Inertia.js inside the Laravel app**. Do not create standalone frontend projects.
- Laravel business features use **`nwidart/laravel-modules`** under top-level `Modules/`, with module-owned controllers, services, models, policies, jobs/events/listeners, and optional backed enums. Do not create DDD layer trees, command/query handlers, aggregates, generic repositories, or `*Port` classes.
- PHP tests use **Pest**.
- Debugging/monitoring UI is **Laravel Telescope**.
- Push uses **Firebase** (`kreait/laravel-firebase`); stored notifications use **Laravel Database Notifications**.

## Required sources

Before planning or changing the repository, read completely:

- [Roadmap, dependencies, invariants, and evidence policy](../../../docs/phases/README.md)
- [Cross-cutting architecture and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- [Phase-to-skill routing](references/phase-routing.md)
- The active phase file linked by the routing reference
- Every dependency phase named by the active phase when its contract or completion evidence affects the requested work

Use [plan coverage](../../../docs/phases/PLAN_COVERAGE.md) only to resolve source-plan traceability or detect scope drift. Inspect current ADRs, diagrams, schemas, OpenAPI/event/tool contracts, evidence manifests, implementation, tests, and local changes before deciding what remains.

## Invocation boundary

Activate this orchestrator for requests such as “implement Phase 04,” “continue phases 05-07,” “start the next roadmap phase,” or “close Phase 22.” Do not activate it merely because a clinic task happens to be described in a phase file. Route ordinary work directly to the applicable domain or stack skill.

If the user asks for the “next” phase, determine the highest phase whose mandatory gate has actual evidence. A Markdown file, merged code, passing unit tests, or a previous claim of completion alone does not establish phase completion. If evidence supports more than one plausible active phase, state the candidates and obtain the choice before making divergent changes.

## Ownership and coordination

- The mapped **lead** owns phase-wide decomposition, domain integration, and the acceptance narrative.
- Each **companion** owns only its named boundary and supplies review or implementation evidence to the lead.
- `clinic-test-engineering` owns test architecture and independent test evidence. A domain lead supplies expected behavior but cannot weaken the test oracle.
- `clinic-security-privacy-assurance` owns threat/privacy assurance and finding closure evidence. An implementer cannot self-approve a security exception or claim statutory compliance.
- `clinic-architecture-contracts` resolves cross-module services, ADRs, module ownership, and deviations. The orchestrator records decisions; it does not invent them.
- A skill that is unavailable is a routing gap. Declare the gap and use the authoritative phase contract without silently assigning that ownership to this orchestrator.

One skill may implement several repository changes, but ownership remains separated in the plan and evidence. A lead cannot overrule a clinical, pharmacy, security/privacy, release, or data-consistency gate merely to finish the phase. Legal review is advisory and never blocks implementation, local verification, or phase completion.

## Phase invariants

1. Work only on the active phase and dependency repairs required to make that phase valid. Do not pre-enable later or V1-excluded features.
2. Preserve the roadmap’s sources of truth, deny-by-default authorization, server-authoritative state, immutable history, transactional outbox, idempotency, private files/channels, AI isolation, exact money/quantity, UTC storage, and redacted telemetry rules.
3. Treat package names in phase files as capabilities. Select and lock exact compatible versions through the Phase 00 dependency process; do not add floating or deprecated packages.
4. Any deviation from the source plan or phase invariant requires an ADR with risks and migration/rollback impact; legal approval is not required.
5. Resolve ambiguous safety behavior conservatively, keep it configurable, record the assumption, and continue. Do not present a reversible planning default as a statutory or regulatory conclusion.
6. A later phase may validate earlier work, but validation never excuses missing security, tests, observability, migrations, or evidence in the active phase.

## Workflow

### 1. Establish the active phase

Record:

- phase number, file, objective, plan traceability, dependencies, entry criteria, non-goals, and mandatory exit gate;
- user-authorized scope and excluded repositories, environments, data, providers, or external systems;
- current implementation/evidence state and unrelated local changes to preserve;
- unresolved ADRs, product decisions, or clinical/pharmacy/security evidence. Record compliance uncertainties as non-blocking assumptions rather than waiting for legal approval.

For a multi-phase request, create an ordered queue but activate only the first dependency-ready phase. Re-evaluate evidence before advancing to the next phase.

### 2. Prove dependency readiness

For every dependency, verify the artifacts the active phase consumes: migrations and invariants, stable API/event/tool contracts, generated-client compatibility, required services/test fixtures, threat controls, and acceptance evidence. Classify each dependency as `PROVEN`, `PARTIAL`, `MISSING`, or `CONTRADICTED`, with concrete references.

Repair only an in-scope dependency defect that blocks the active phase. Otherwise stop at a precise blocker and required owner/action; do not mask it with a phase-local workaround.

### 3. Route ownership

Use the routing table to select one lead and the required companions. Add a conditional companion whenever the change crosses its boundary—for example PostgreSQL for a constraint/lock/index, realtime delivery for an outbox consumer, secure files for object bytes, or AI evaluation for a model/prompt/retrieval change.

Give every work item:

- owning skill/module and source section;
- input/output contract and invariant;
- dependencies and transaction/idempotency/concurrency behavior;
- failure, denial, privacy, migration, rollback, observability, and test obligations;
- evidence path and measurable acceptance result.

### 4. Implement in dependency order

Prefer thin vertical slices that end in an observable, denied-by-default behavior. Update code, migrations, generated contracts, clients, jobs/events, documentation, tests, telemetry, and runbooks together when the slice requires them. Use the conventional Nwidart controller/service/model structure and preserve existing user changes.

Do not infer authority to run production migrations, deploy, rotate keys/secrets, contact users/providers, scan an external target, or process real patient/clinic/pharmacy data. Those actions require explicit scope and the safeguards in their owning phase.

### 5. Build the evidence ledger

Maintain a ledger with one row per mandatory gate:

```text
gate_id | requirement/source | owner | artifact_or_command
result | evidence_reference | reviewer | residual_gap
```

Evidence must identify the exact code/artifact/config version, environment, tool version, command or procedure, result, and safe retained artifact. Never place credentials, National IDs, medical content, raw prompts, object keys, or exploit payloads in the ledger.

### 6. Verify and close

Run focused checks first, then every affected module/client suite and the phase-mandated integration, contract, E2E, system, security, accessibility/localization, migration, resilience, and reconciliation checks. Force at least one negative/denied case to prove the test oracle can fail.

Declare a phase complete when every engineering gate is evidenced, required security/clinical checks are recorded, no release-blocking technical finding remains, and all V1 exclusions remain disabled or absent. Missing legal review or sign-off never keeps a phase open; record the assumption and follow-up instead.

## Scope and authorization limits

- A phase request authorizes ordinary project-local implementation and proportionate local/test-environment verification, not external or production mutation.
- Use synthetic, isolated data. Do not copy, inspect, or disclose real patient, staff, pharmacy, credential, or payment data.
- Never claim statutory compliance, certification, clinical approval, pharmacy-regulatory approval, or legal sufficiency from engineering evidence.
- Do not invent clinical thresholds or medication policy. For unresolved retention, consent, residency, or legal-hold details, implement a conservative configurable default, document it, and continue without legal approval.
- Stop active testing on target ambiguity, unexpected production routing, sensitive-data exposure, instability, or breached rules of engagement.

## Completion handoff

Return the phase number and outcome first, followed by implemented artifacts, exact verification results, evidence-gate status, unresolved decisions/findings, and whether the next phase is dependency-ready. Link repository files directly. Never describe a partially evidenced phase as complete.
