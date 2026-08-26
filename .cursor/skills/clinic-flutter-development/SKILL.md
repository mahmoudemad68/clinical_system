---
name: clinic-flutter-development
description: Implement or refactor this clinic project's patient UI as Inertia.js pages inside the Laravel app. Use for patient presentation, generated/Inertia props, localization, accessibility, notifications, and tests; not for a standalone Flutter app, doctor/pharmacy UI, admin UI, Laravel domain rules, or AI service orchestration unless the user explicitly requires a native mobile client.
---

# Clinic Patient UI Development

Build the patient interface while preserving the server-authoritative clinical, booking, pharmacy, and AI boundaries defined by the roadmap.

Patient UI lives **inside the Laravel codebase** as **Inertia.js** pages. Do **not** create `apps/patient-app` or a standalone Flutter/React Native project unless the user explicitly requires a native mobile client.

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

For non-trivial patient UI work also read the applicable client sections of [performance](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), [security/privacy](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and [production release](../../../docs/phases/23_disaster_recovery_release_and_production.md).

Inspect current ADRs, Inertia patient pages, Laravel controllers/policies, tests, and local changes. The phase contract overrides inferred UI behavior.

## Ownership

Own the patient Inertia surface in the Laravel app:

- presentation, navigation, pages/components (typically `resources/js/Pages/Patient`);
- Laravel controllers that `Inertia::render` authorized patient projections; form requests;
- generated-contract mapping only at `/api/v1` edges; first-party UI prefers Inertia props;
- Arabic/English localization, RTL, accessibility, and exact money/quantity/time presentation;
- inbox UI over Laravel Database Notifications; Firebase push via the server adapter;
- Pest Inertia/HTTP tests and browser E2E for critical patient journeys.

Doctor and pharmacy pages belong to `clinic-electron-desktop-development`. Browser admin belongs to `clinic-react-admin-development`.

If the user **explicitly** requires Android/iOS, keep Laravel as the authority and do not infer a new mobile repo for ordinary patient UI work.

## Boundaries that cannot move into the patient UI

- Laravel owns authentication and authorization decisions, actor/tenant/context resolution, state transitions, booking locks, queue truth, prescriptions, inventory, money, idempotency outcomes, and AI tool authorization.
- Hiding or disabling a control improves usability only; it is never permission enforcement.
- The patient UI never connects directly to PostgreSQL, Redis, Reverb internals, S3, FastAPI, Qdrant, model/provider SDKs, or external pharmacy systems.
- Do not hand-code a second API truth. Prefer Inertia props from Laravel; keep OpenAPI for programmatic clients.
- Preserve one idempotency key for one user intent. Do not silently retry an ambiguous write or claim success before server confirmation.
- AI output is typed untrusted server data. Do not execute proposed URLs, HTML, code, SQL, paths, or tools.
- Do not promote a future-only feature or use a client flag to bypass a disabled server capability.

## State, notifications, and local-data rules

- Model loading, refreshing, empty, success, validation, conflict, denied/not-found, rate-limited, offline, degraded, pending, retryable failure, permanent failure, and cancelled states where applicable.
- Server state wins after reconnect. Realtime events are hints with sequence/version; detect gaps and fetch the authoritative snapshot.
- Patient booking, payment, and clinical changes never appear successful before server confirmation.
- Use Laravel session cookies with CSRF. Do not persist tokens, precise location, clinical history, prescriptions, lab files, AI content, or signed URLs in browser storage for convenience.
- Use `Africa/Cairo` business intent and server UTC instants correctly; never hard-code a fixed UTC offset.
- Persist notifications with **Laravel Database Notifications**. Send push with **Firebase** (`kreait/laravel-firebase` on the server). Push copy stays generic.

## Implementation workflow

1. Identify the owning phase, patient journey, server contract, authorization assumptions, and acceptance gate.
2. Inspect existing Inertia page/controller patterns. Preserve valid conventions.
3. Define success plus denied, stale, duplicate-tap, timeout, cancellation, reconnect, localization, and accessibility behavior.
4. Keep presentation dependent on authorized Laravel props/controllers. Domain transitions stay in application handlers.
5. Validate server data before presentation, preserve exact structured values, sanitize restricted markup, and map stable errors deliberately.
6. Add redacted telemetry through the approved client abstraction with bounded attributes and no personal, clinical, location, or free-text content.
7. Run focused Pest tests, affected patient suites, and a production Vite/Inertia build.

If implementation requires an unauthorized backend contract or domain-rule change, stop at a concrete proposal and hand it to the owning module rather than embedding a UI workaround.

## Verification

Verify at minimum:

- Pest Inertia/HTTP tests for mappings, time/money/quantity, error/retry, and sync state;
- tests for API/realtime precedence, cancellation, idempotency-key reuse, stale versions, and session cleanup;
- success plus denied/offline/degraded/conflict states in Arabic and English;
- browser E2E for the critical patient journey, authoritative reconnect, ambiguous-write recovery, deep links, notifications, and file/permission flows;
- keyboard/switch access, screen reader, focus, contrast, large text, RTL, and non-color-only state cues;
- no standalone Flutter/mobile repo, direct-service/provider access, hard-coded clinical rule, hidden automatic write, or enabled V1 exclusion entered the patient UI.

Observability owns global SLO/load conclusions, production owns promotion, and AI product/evaluation skills own AI behavior and approval. This skill implements their patient surface only.
