---
name: clinic-flutter-development
description: Implement or refactor this clinic project's Flutter patient mobile application for Android and iOS. Use for Dart UI, generated API/realtime integration, secure mobile state, localization, accessibility, notifications, and Flutter tests; not for Electron doctor/pharmacy desktop apps, React admin web, Laravel rules, or AI service orchestration.
---

# Clinic Flutter Mobile Development

Build the patient Android/iOS application while preserving the server-authoritative clinical, booking, pharmacy, and AI boundaries defined by the roadmap.

## Read the required sources

Read completely before changing code:

- [Roadmap and invariants](../../../docs/phases/README.md)
- [Cross-cutting architecture and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The phase file that owns the requested patient feature and every dependency whose client contract it consumes

Route additional reading by feature:

- [Identity/access](../../../docs/phases/01_auth_identity_and_access.md)
- [Onboarding](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md)
- [Booking](../../../docs/phases/03_scheduling_availability_and_booking.md)
- [Queue status](../../../docs/phases/04_realtime_queue_and_consultation_control.md)
- [Patient clinical reads](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md), [prescriptions](../../../docs/phases/06_prescriptions_reminders_and_printing.md), and [labs/files/reports](../../../docs/phases/07_labs_files_reports_and_referrals.md)
- [Patient experience](../../../docs/phases/08_patient_experience_discovery_reviews_and_localization.md), [notifications/chat](../../../docs/phases/09_notifications_and_post_visit_chat.md), [medicine fulfillment](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), and [Patient AI](../../../docs/phases/19_patient_ai_triage_and_booking_tools.md) when applicable

For non-trivial mobile work also read the applicable client sections of [performance](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), [security/privacy](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and [production release](../../../docs/phases/23_disaster_recovery_release_and_production.md).

Inspect current ADRs, OpenAPI-generated Dart types, patient-focused Flutter packages, target feature code, tests, and local changes. The phase contract overrides inferred UI behavior.

## Ownership

Own `apps/patient-app` and patient-focused shared Flutter packages:

- presentation, navigation, controllers, client-domain models, repositories, API/realtime adapters, and dependency wiring;
- generated-contract mapping at the network edge and approved bounded mobile caches/drafts;
- Android/iOS secure storage, notifications, deep links, permissions, file selection, and platform integration;
- Arabic/English localization, RTL, accessibility, and exact money/quantity/time presentation;
- Flutter unit, widget, golden, repository, integration, and mobile-platform compatibility tests.

Doctor and pharmacy desktop code, main/preload/IPC, desktop encrypted drafts, printing, barcode devices, and Electron packaging belong to `clinic-electron-desktop-development`. Browser admin code belongs to `clinic-react-admin-development`.

## Boundaries that cannot move into Flutter

- Laravel owns authentication and authorization decisions, actor/tenant/context resolution, state transitions, booking locks, queue truth, prescriptions, inventory, money, idempotency outcomes, and AI tool authorization.
- Hiding or disabling a control improves usability only; it is never permission enforcement.
- The mobile app never connects directly to PostgreSQL, Redis, Reverb internals, S3, FastAPI, Qdrant, model/provider SDKs, or external pharmacy systems.
- Use OpenAPI-generated DTOs at the network edge and map them to client models. Do not hand-code a second API truth.
- Preserve one idempotency key for one user intent. Do not silently retry an ambiguous write or claim success before server confirmation.
- AI output is typed untrusted server data. Do not execute proposed URLs, HTML, code, SQL, paths, or tools.
- Do not promote a future-only feature or use a client flag to bypass a disabled server capability.

## Mobile state and local-data rules

- Model loading, refreshing, empty, success, validation, conflict, denied/not-found, rate-limited, offline, degraded, pending, retryable failure, permanent failure, and cancelled states where applicable.
- Server state wins after reconnect. Realtime events are hints with sequence/version; detect gaps and fetch the authoritative snapshot.
- Patient booking, payment, and clinical changes never appear successful before server confirmation.
- Store tokens only in platform secure storage. Keep sensitive data minimal and clear it on logout, revocation, expiry, or account change.
- Use approved encrypted Drift storage only where the owning phase permits a bounded mobile cache/draft. Use `sqlite3` v3 native hooks with the approved SQLCipher or SQLite3MultipleCiphers integration; never introduce EOL `sqlcipher_flutter_libs`.
- Do not persist precise location, clinical history, prescriptions, lab files, AI content, signed URLs, or credentials merely for convenience.
- Use `Africa/Cairo` business intent and server UTC instants correctly; never hard-code a fixed UTC offset.

## Implementation workflow

1. Identify the owning phase, patient journey, server contract, authorization assumptions, local-data classification, permission/offline semantics, and acceptance gate.
2. Inspect generated types and the current feature/repository/state pattern. Preserve valid conventions.
3. Define success plus denied, stale, duplicate-tap, timeout, cancellation, permission-denied, reconnect, localization, and accessibility behavior.
4. Keep presentation dependent on controllers/use cases, repositories dependent on narrow API/local/realtime adapters, and DTOs at the transport edge.
5. Validate server data before presentation, preserve exact structured values, sanitize restricted markup, and map stable errors deliberately.
6. Add redacted telemetry through the approved client abstraction with bounded attributes and no personal, clinical, location, or free-text content.
7. Run focused tests, affected patient/shared-package suites, generated-contract checks, and Android/iOS compatibility tests.

If implementation requires an unauthorized backend contract or domain-rule change, stop at a concrete proposal and hand it to the owning module rather than embedding a mobile workaround.

## Verification

Verify at minimum:

- formatter, analyzer/lints, code generation consistency, and Android/iOS compilation;
- unit tests for mappings, controllers, time/money/quantity, error/retry, permissions, and sync state;
- repository tests for API/local/realtime precedence, cancellation, idempotency-key reuse, stale versions, and revocation cleanup;
- widget/golden tests for success plus denied/offline/degraded/conflict states in Arabic and English;
- integration/E2E for the critical patient journey, authoritative reconnect, ambiguous-write recovery, deep links, notifications, and file/permission flows;
- keyboard/switch access, screen reader, focus, contrast, large text, RTL, and non-color-only state cues;
- secure-storage/database cleanup, wrong-user/session behavior, log/crash redaction, encryption compatibility, backup exclusion, and release permission declarations;
- no desktop, direct-service/provider, hard-coded clinical rule, hidden automatic write, or enabled V1 exclusion entered the mobile app.

Observability owns global SLO/load conclusions, production owns signing/store promotion, and AI product/evaluation skills own AI behavior and approval. This skill implements their patient-mobile surface only.
