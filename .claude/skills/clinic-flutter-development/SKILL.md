---
name: clinic-flutter-development
description: Implement or refactor the clinic project's Flutter patient, doctor, pharmacy, and shared-package code. Use for Dart UI, client architecture, generated API/realtime integration, secure local state, localization, accessibility, and Flutter tests; not for Laravel rules, React admin, or AI service orchestration.
---

# Clinic Flutter Development

Build Flutter changes that preserve the server-authoritative clinical, booking, pharmacy, and AI boundaries defined by the project roadmap.

## Read the required sources

Read these completely before changing code:

- [Roadmap and invariants](../../docs/phases/README.md)
- [Cross-cutting architecture and delivery contract](../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The phase file that owns the requested feature and any dependency it names.

Route by application and read only the relevant feature sources in addition to the common sources:

- Patient mobile: [identity/access](../../docs/phases/01_auth_identity_and_access.md), [booking](../../docs/phases/03_scheduling_availability_and_booking.md), [patient experience](../../docs/phases/08_patient_experience_discovery_reviews_and_localization.md), [notifications/chat](../../docs/phases/09_notifications_and_post_visit_chat.md), [medicine fulfillment](../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), and [patient AI](../../docs/phases/19_patient_ai_triage_and_booking_tools.md) when applicable.
- Doctor desktop: [queue/consultation control](../../docs/phases/04_realtime_queue_and_consultation_control.md), [clinical records/local resilience](../../docs/phases/05_clinical_records_encounters_and_local_resilience.md), [prescriptions](../../docs/phases/06_prescriptions_reminders_and_printing.md), [labs/files/reports](../../docs/phases/07_labs_files_reports_and_referrals.md), [notifications/chat](../../docs/phases/09_notifications_and_post_visit_chat.md), and [Doctor AI](../../docs/phases/17_doctor_ai.md) when applicable.
- Pharmacy desktop: [pharmacy tenancy/catalog](../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), [inventory](../../docs/phases/11_inventory_batches_fefo_and_alerts.md), [purchasing](../../docs/phases/12_purchasing_and_goods_receipt.md), [POS/refunds](../../docs/phases/13_pos_invoices_returns_and_refunds.md), [medicine fulfillment](../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), [integrations](../../docs/phases/15_external_pharmacy_integrations.md), and [Pharmacy AI](../../docs/phases/18_pharmacy_ai.md) when applicable.

For non-trivial client work also read the client sections of [performance/resilience](../../docs/phases/21_performance_scaling_observability_and_resilience.md), [security/privacy validation](../../docs/phases/22_security_privacy_and_compliance_validation.md), and [production release](../../docs/phases/23_disaster_recovery_release_and_production.md).

Inspect current ADRs, OpenAPI-generated Dart types, shared packages, target feature code, tests, and local changes. The phase contract overrides inferred UI behavior.

## Ownership

Own changes under the three Flutter apps and shared Flutter packages:

- presentation, navigation, view models/controllers, client domain models, repositories, API/realtime adapters, and dependency wiring;
- generated-contract mapping at the network edge;
- encrypted local drafts/caches/outbox state where the owning phase permits it;
- Arabic/English localization, RTL, accessibility, exact money/quantity/time presentation, and platform integration;
- Flutter unit, widget, golden, repository, integration, and platform compatibility tests.

Keep shared packages capability-focused. Do not put app-specific workflows into `common_models`, networking, or the design system.

## Boundaries that cannot move into Flutter

- Laravel owns authentication decisions, authorization, tenant/context resolution, clinical access, state transitions, availability, booking locks, queue truth, prescriptions, inventory, money, refunds, idempotency outcomes, and AI tool authorization.
- Hiding or disabling a control improves usability only; it is never permission enforcement.
- Clients never connect directly to PostgreSQL, Redis, Reverb internals, S3, FastAPI, Qdrant, or model/provider SDKs.
- Do not hand-code a divergent API DTO when an OpenAPI-generated type exists. Map DTOs into client domain/view models.
- Do not silently retry writes. Preserve one idempotency key for one user intent and resolve ambiguous outcomes through the server.
- AI text and tool results are typed, validated server output. Flutter does not execute model-proposed URLs, code, SQL, paths, or tools.
- Do not promote a future-only feature or use a client flag to bypass a disabled server capability.

## Client state and local-data rules

- Model loading, refreshing, empty, success, validation failure, conflict, denied/not-found, rate-limited, offline, degraded, pending-sync, retryable failure, permanent failure, and cancelled states explicitly where relevant.
- Server state wins after reconnect. Realtime events are hints with sequence/version; detect gaps and fetch the authoritative snapshot.
- Doctor desktop may keep an encrypted transient clinical draft and idempotent local outbox. It must show whether content is only local, pending, acknowledged, conflicted, or failed.
- Pharmacy POS and stock mutations are online-only in V1. A local catalog cache cannot make an offline sale appear committed.
- Patient booking, payment, and clinical changes never appear successful before server confirmation.
- Store tokens in platform secure storage. Store only the minimum sensitive local data, with logout/revocation/expiry cleanup and backup exclusion where supported.
- For encrypted Drift databases, use `sqlite3` v3 native hooks with the approved SQLCipher or SQLite3MultipleCiphers integration and platform compatibility tests. Do not introduce the EOL `sqlcipher_flutter_libs` package.
- Use `Africa/Cairo` business intent and server UTC instants correctly; never hard-code a fixed UTC offset.

## Implementation workflow

1. Identify the target app, owning phase, user journey, server contract, authorization assumptions, local-data classification, offline semantics, and acceptance gate.
2. Inspect the generated client and current repository/view-state pattern. Preserve existing conventions unless they violate the phase contract.
3. Write observable acceptance cases, including denied, stale, duplicate-tap, timeout, cancellation, reconnect, and accessibility behavior.
4. Implement inward layers: view-specific presentation depends on an application/controller abstraction; repositories depend on narrow API/local/realtime adapters. Keep provider/framework details outside client domain rules.
5. Validate all server data before presentation, preserve exact structured numbers/IDs/statuses, sanitize restricted Markdown, and map stable server error codes deliberately.
6. Add telemetry through the approved client instrumentation abstraction with bounded labels and no personal/clinical/free-text content.
7. Run focused tests, then the relevant app/shared-package suites and generated-contract compatibility checks.

If implementation requires a backend contract or domain-rule change not already authorized, stop at a concrete contract proposal and hand it to the owning phase/module rather than embedding a workaround in Flutter.

## Verification

At minimum, verify:

- formatter, analyzer/lints, code generation consistency, and compilation for every affected platform;
- unit tests for mapping, reducers/controllers, time/money/quantity, error/retry, and sync state;
- repository tests for API/local/realtime precedence, cancellation, idempotency-key reuse, and stale-version handling;
- widget/golden tests for success plus denied/offline/degraded/conflict states in Arabic and English;
- integration/E2E tests for the critical journey and server-authoritative reconnect or ambiguous-write recovery;
- keyboard, screen reader, focus, contrast, large text, RTL, and non-color-only status cues;
- secure-storage/local-database cleanup, wrong-user/device-session behavior, log/crash redaction, and platform encryption compatibility;
- no new direct service/provider dependency, hard-coded clinical rule, hidden automatic write, or enabled V1 exclusion.

The observability skill owns global SLO/load instrumentation design, the production skill owns signing/promotion/release, and the AI product/evaluation skills own AI behavior and approval. This skill implements their typed client surfaces and supplies client evidence without taking over those decisions.
