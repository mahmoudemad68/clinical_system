---
name: clinic-laravel-development
description: Implement or refactor Laravel 13 Core modules, application coordinators, policies, HTTP/Inertia contracts, Eloquent adapters, and Pest tests for this clinic platform. Use for Core PHP work, Inertia controllers, Firebase/kreait adapters, and Telescope wiring; domain and delivery specialists retain their invariants.
---

# Clinic Laravel Development

Build Laravel Core changes without weakening the modular-monolith, authorization, transaction, or compatibility contracts. Preserve the user's requested phase and do not infer permission to implement later capabilities.

## Project delivery stack

- Keep backend and **Inertia.js frontend in the same Laravel codebase**. Do not create a standalone frontend project.
- PHP tests use **Pest** (not new PHPUnit class hierarchies).
- Application monitoring/debugging uses **Laravel Telescope** (local/non-production unless a locked-down production install is already documented).
- Push notifications use **Firebase** via **`kreait/laravel-firebase`**. Persist in-app notifications with **Laravel Database Notifications**. Put the Firebase SDK behind a narrow adapter.

## Ownership and routing

This skill owns Laravel implementation mechanics across the Core Laravel app: module layout, commands/queries/handlers, policies, coordinators, Eloquent repositories, HTTP resources, Inertia controllers that return authorized props, framework configuration, service-provider wiring, Laravel-side Pest contract tests, and safe provider adapters.

The active business module owns its state machine and public ports. Co-invoke the clinical, pharmacy, pharmacy-integration, secure-files, realtime/jobs, AI, PostgreSQL, security, or test skill when its authority is involved. This skill must not replace a specialist invariant with a Laravel convenience.

Use the standard module direction:

```text
Http / Infrastructure / Jobs -> Application -> Domain
Domain -> PHP standard library and domain-owned interfaces only
```

Controllers handle transport input, authenticate, invoke one command/query handler, and map the stable JSON or Inertia response. They do not perform state transitions. Application handlers coordinate policies, transactions, domain objects, and ports. Infrastructure implements domain/application-owned ports; provider and Eloquent types never escape their adapter.

## Required phase sources

Always read [Phase 00](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md) and the exact phase being implemented.

Core Laravel feature sources are:

- [Phase 01](../../../docs/phases/01_auth_identity_and_access.md), [Phase 02](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md), and [Phase 03](../../../docs/phases/03_scheduling_availability_and_booking.md);
- [Phase 04](../../../docs/phases/04_realtime_queue_and_consultation_control.md), [Phase 05](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md), and [Phase 06](../../../docs/phases/06_prescriptions_reminders_and_printing.md);
- [Phase 07](../../../docs/phases/07_labs_files_reports_and_referrals.md), [Phase 08](../../../docs/phases/08_patient_experience_discovery_reviews_and_localization.md), and [Phase 09](../../../docs/phases/09_notifications_and_post_visit_chat.md);
- [Phase 20](../../../docs/phases/20_admin_analytics_and_system_health.md).

For Core-side pharmacy work, use the relevant specialist source: [Phase 10](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), [Phase 11](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md), [Phase 12](../../../docs/phases/12_purchasing_and_goods_receipt.md), [Phase 13](../../../docs/phases/13_pos_invoices_returns_and_refunds.md), [Phase 14](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), or [Phase 15](../../../docs/phases/15_external_pharmacy_integrations.md). For Core-to-AI context or tools, read [Phase 16](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md) and the active [Doctor AI](../../../docs/phases/17_doctor_ai.md), [Pharmacy AI](../../../docs/phases/18_pharmacy_ai.md), or [Patient AI](../../../docs/phases/19_patient_ai_triage_and_booking_tools.md) phase.

## Non-obvious implementation invariants

- Policies derive actor, patient, doctor, organization, branch, membership, capability, and current resource state from server-owned records. Never authorize a client-supplied role, scope, status, or tenant identifier.
- One module cannot import another module's Eloquent model or update its tables. Use a narrow command/query port returning typed DTOs.
- A workflow that must be atomic across modules uses an explicit application coordinator and shared transaction context. Do not replace booking, consultation transition, prescription exposure, purchase receipt, sale/cancellation, or refund consistency with event handlers.
- Write the state change, audit reference, idempotency state, and outbox rows in the same bounded PostgreSQL transaction. External providers, Reverb, notifications, analytics, OCR, embeddings, and sync calls occur after commit.
- Idempotency keys are scoped to actor/device, operation, tenant when applicable, and a canonical request hash. Same key plus a different hash returns `409`; an unknown outcome is queried/reconciled before a new intent.
- Published prescription versions, posted goods receipts, immutable inventory movements, and paid invoice lines are not edited in place. Corrections append governed records.
- Jobs carry stable IDs, schema version, deadline, and idempotency key; they reload state and recheck authorization/eligibility where relevant. Never serialize secrets, Eloquent graphs, or protected content into Redis.
- Octane workers must not retain actor, tenant, request, locale, or clinical state between requests. Avoid mutable singleton/request state and test two sequential synthetic identities.
- Laravel owns all AI tool authorization. The FastAPI service receives minimized context and cannot access Core tables or write Core state.

## HTTP and package conventions

- Keep public programmatic endpoints under `/api/v1` and OpenAPI authoritative. First-party UI uses Inertia web routes and authorized props; do not hand-maintain a conflicting DTO contract.
- Use the shared `data`, `meta`, `errors`, and `request_id` envelope; safe machine codes; `401/403/404/409/422/429/5xx` semantics from Phase 00; UTC RFC 3339 timestamps; UUIDv7; opaque cursors; integer-minor-unit money and explicit quantity units.
- Validate strict field sets, semantic limits, Unicode/control characters, pagination, upload size, and mass-assignment boundaries before invoking the application handler.
- Use `brick/money` for financial value objects, framework/Symfony UUIDv7 support, `deptrac/deptrac`, Larastan/PHPStan, Pint, and **Pest**. Add provider libraries only behind a narrow adapter and lock the resolved version. Firebase integration uses `kreait/laravel-firebase`.
- Do not use a repository/service abstraction that exposes arbitrary Eloquent queries across modules. Prefer intent-named repositories and small ports.

## Implementation workflow

1. Read Phase 00 and the active phase's objective, dependencies, ownership, invariants, flows, contracts, tests, rollout, and exit gate. Inspect current module code, migrations, OpenAPI, and tests before editing.
2. Identify the aggregate owner and whether the request is a command, query, coordinator, event consumer, or provider adapter. Name the authorization policy and transaction boundary before writing code.
3. Implement or refine domain value objects/rules first, then the application handler/coordinator, then Eloquent/provider adapters, and finally HTTP/job wiring. Keep framework details outside Domain.
4. On every mutation, re-read authoritative state inside the transaction, lock or compare-and-set as the active phase requires, apply the transition, write audit/idempotency/outbox records, and commit before dispatch.
5. Map denials, conflicts, permanent validation failures, transient dependencies, timeouts, and unknown outcomes to stable errors. Never expose stack traces, SQL, provider payloads, object keys, or hidden resource existence.
6. Add the smallest complete test slice: pure rule tests, real PostgreSQL/Redis integration where semantics depend on them, OpenAPI/adapter contract tests, and authorization/replay/concurrency regressions.
7. Run the repository's format, static-analysis, architecture, focused test, contract-generation, and security checks. Do not silently update snapshots or generated clients to conceal a breaking contract.

## Observable verification

The handoff must show evidence appropriate to the change:

- Pint and Larastan/PHPStan pass, and `deptrac/deptrac` reports no forbidden dependency;
- focused Pest tests cover success, denial, stale version, same/different idempotency hash, duplicate delivery, rollback, and safe error mapping;
- real PostgreSQL integration proves transaction and locking behavior; Redis/provider failure leaves authoritative state correct;
- policy tests attempt another patient, doctor, organization, branch, role, revoked device, and unauthenticated actor where applicable;
- OpenAPI validation and generated-client compatibility pass;
- an outbox effect is absent before commit, replay-safe after commit, and visible in dead-letter/repair state when exhausted;
- structured logs/traces contain correlation data but no clinical text, credentials, tokens, national IDs, chat bodies, or unbounded identifiers;
- the active phase exit gate passes and its non-goals remain disabled.

Report files changed, module owner, coordinator/transaction boundary, public contract impact, migrations, checks executed, and any deferred risk.
