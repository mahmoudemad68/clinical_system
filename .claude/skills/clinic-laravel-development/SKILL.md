---
name: clinic-laravel-development
description: Implement or refactor conventional Laravel 13 Core modules managed by nwidart/laravel-modules, including controllers, services, models, policies, HTTP/Inertia contracts, provider integrations, enums, and Pest tests. Use for Core PHP work; business and delivery specialists retain their invariants.
---

# Clinic Laravel Development

Build Laravel Core changes without weakening the modular-monolith, authorization, transaction, or compatibility contracts. Preserve the user's requested phase and do not infer permission to implement later capabilities.

## Project delivery stack

- Keep backend and **Inertia.js frontend in the same Laravel codebase**. Do not create a standalone frontend project.
- Use **`nwidart/laravel-modules`** with top-level `Modules/<Name>/` modules. Do not extend the legacy `app/Modules/<Name>/Domain|Application|Infrastructure` layout.
- PHP tests use **Pest** (not new PHPUnit class hierarchies).
- Application monitoring/debugging uses **Laravel Telescope** (local/non-production unless a locked-down production install is already documented).
- Push notifications use **Firebase** via **`kreait/laravel-firebase`**. Persist in-app notifications with **Laravel Database Notifications**. Put the Firebase SDK behind a narrow adapter.

## Ownership and routing

This skill owns Laravel implementation mechanics across the Core Laravel app: Nwidart module layout, controllers, Form Requests, API Resources, services, Eloquent models/scopes/casts, policies, enums, jobs/events/listeners, module service providers, Inertia controllers that return authorized props, Laravel-side Pest tests, and provider integrations.

The active business module owns its state machine, tables, and public services. Co-invoke the clinical, pharmacy, pharmacy-integration, secure-files, realtime/jobs, AI, PostgreSQL, security, or test skill when its authority is involved. This skill must not replace a specialist invariant with a Laravel convenience.

Use the conventional module shape:

```text
Modules/<Name>/
  app/Http/Controllers,Requests,Resources
  app/Models,Services,Policies,Enums
  app/Jobs,Events,Listeners,Providers
  routes/ database/ resources/ config/ tests/
```

Controllers authenticate/authorize, accept validated Form Request data, call one descriptive module service method, and return an API Resource, redirect, or Inertia response. Services own business workflows, transactions, idempotency, cross-module coordination, and post-commit dispatch. Models own relationships, casts, scopes, and small model-local behavior. Use backed enums for stable finite states/types/reasons when useful.

Do not add `Domain`, `Application`, or `Infrastructure` directories, command/query buses, handler-per-action trees, aggregates, repositories that merely wrap Eloquent, DDD value-object trees, or `*Port` interfaces. Put external SDK code in a descriptive module service or integration class; add an `app/Contracts` interface only when multiple implementations or a test seam genuinely require it.

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
- A module never writes another module's tables directly. Call the owning module's public service. Cross-module model relationships are allowed when they are explicit and read-only from the caller's perspective.
- A workflow that must be atomic across modules uses an explicit coordinating service and `DB::transaction()`. Do not replace booking, consultation transition, prescription exposure, purchase receipt, sale/cancellation, or refund consistency with event handlers.
- Write the state change, audit reference, idempotency state, and outbox rows in the same bounded PostgreSQL transaction. External providers, Reverb, notifications, analytics, OCR, embeddings, and sync calls occur after commit.
- Idempotency keys are scoped to actor/device, operation, tenant when applicable, and a canonical request hash. Same key plus a different hash returns `409`; an unknown outcome is queried/reconciled before a new intent.
- Published prescription versions, posted goods receipts, immutable inventory movements, and paid invoice lines are not edited in place. Corrections append governed records.
- Jobs carry stable IDs, schema version, deadline, and idempotency key; they reload state and recheck authorization/eligibility where relevant. Never serialize secrets, Eloquent graphs, or protected content into Redis.
- Octane workers must not retain actor, tenant, request, locale, or clinical state between requests. Avoid mutable singleton/request state and test two sequential synthetic identities.
- Laravel owns all AI tool authorization. The FastAPI service receives minimized context and cannot access Core tables or write Core state.

## HTTP and package conventions

- Keep public programmatic endpoints under `/api/v1` and OpenAPI authoritative. First-party UI uses Inertia web routes and authorized props; do not hand-maintain a conflicting DTO contract.
- Use the shared `data`, `meta`, `errors`, and `request_id` envelope; safe machine codes; `401/403/404/409/422/429/5xx` semantics from Phase 00; UTC RFC 3339 timestamps; UUIDv7; opaque cursors; integer-minor-unit money and explicit quantity units.
- Validate strict field sets, semantic limits, Unicode/control characters, pagination, upload size, and mass-assignment boundaries in Form Requests before invoking the service.
- Use `brick/money` for financial calculations, framework/Symfony UUIDv7 support, Larastan/PHPStan, Pint, and **Pest**. Lock provider libraries; Firebase integration uses `kreait/laravel-firebase` through a notification service.
- Prefer Eloquent directly inside the owning service. Do not add generic repositories, base services, service locators, or a command/query bus.

## Implementation workflow

1. Read Phase 00 and the active phase's objective, dependencies, ownership, invariants, flows, contracts, tests, rollout, and exit gate. Inspect current module code, migrations, OpenAPI, and tests before editing.
2. Identify the owning module, controller endpoint, service method, models, authorization policy, enums, and transaction boundary before writing code.
3. Implement or refine migrations/models/enums and the module service, then Form Request/policy/controller/resource wiring, then jobs/events/listeners or external integrations. Keep controllers thin and services cohesive.
4. On every mutation, re-read authoritative state inside the transaction, lock or compare-and-set as the active phase requires, apply the transition, write audit/idempotency/outbox records, and commit before dispatch.
5. Map denials, conflicts, permanent validation failures, transient dependencies, timeouts, and unknown outcomes to stable errors. Never expose stack traces, SQL, provider payloads, object keys, or hidden resource existence.
6. Add the smallest complete test slice: pure rule tests, real PostgreSQL/Redis integration where semantics depend on them, OpenAPI/adapter contract tests, and authorization/replay/concurrency regressions.
7. Run the repository's format, static-analysis, architecture, focused test, contract-generation, and security checks. Do not silently update snapshots or generated clients to conceal a breaking contract.

## Observable verification

The handoff must show evidence appropriate to the change:

- Pint and Larastan/PHPStan pass, `php artisan module:list` succeeds, and architecture checks find no legacy DDD folders or direct cross-module writes;
- focused Pest tests cover success, denial, stale version, same/different idempotency hash, duplicate delivery, rollback, and safe error mapping;
- real PostgreSQL integration proves transaction and locking behavior; Redis/provider failure leaves authoritative state correct;
- policy tests attempt another patient, doctor, organization, branch, role, revoked device, and unauthenticated actor where applicable;
- OpenAPI validation and generated-client compatibility pass;
- an outbox effect is absent before commit, replay-safe after commit, and visible in dead-letter/repair state when exhausted;
- structured logs/traces contain correlation data but no clinical text, credentials, tokens, national IDs, chat bodies, or unbounded identifiers;
- the active phase exit gate passes and its non-goals remain disabled.

Report files changed, module owner, service/transaction boundary, public contract impact, migrations, checks executed, and any deferred risk.
