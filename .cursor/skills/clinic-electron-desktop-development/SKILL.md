---
name: clinic-electron-desktop-development
description: Build this clinic project's doctor and pharmacy UI as Inertia.js pages inside the Laravel app. Use for doctor/pharmacy presentation, localization, accessibility, printing/barcode web adapters, and Inertia tests; not for a standalone Electron app, patient UI, admin UI, backend rules, or release approval unless the user explicitly requires a native desktop client.
---

# Clinic Doctor and Pharmacy UI Development

Build the doctor and pharmacy interfaces without moving clinical, pharmacy, financial, identity, or AI authority out of Laravel.

These UIs live **inside the Laravel codebase** as **Inertia.js** pages. Do **not** create `apps/doctor-desktop`, `apps/pharmacy-desktop`, or a standalone Electron project unless the user explicitly requires a native desktop client.

## Read the required sources

Read completely before changing doctor/pharmacy UI code:

- [Roadmap and invariants](../../../docs/phases/README.md)
- [Cross-cutting architecture and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The phase that owns the requested feature and every dependency whose contract the client consumes

Route additional reading by application:

- Doctor UI: [identity](../../../docs/phases/01_auth_identity_and_access.md), [onboarding](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md), [scheduling](../../../docs/phases/03_scheduling_availability_and_booking.md), [queue](../../../docs/phases/04_realtime_queue_and_consultation_control.md), [clinical drafts](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md), [prescriptions](../../../docs/phases/06_prescriptions_reminders_and_printing.md), [labs/files/reports](../../../docs/phases/07_labs_files_reports_and_referrals.md), [notifications/chat](../../../docs/phases/09_notifications_and_post_visit_chat.md), and [Doctor AI](../../../docs/phases/17_doctor_ai.md) when applicable.
- Pharmacy UI: [onboarding](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md), [catalog/tenancy](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), [inventory](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md), [purchasing](../../../docs/phases/12_purchasing_and_goods_receipt.md), [POS/refunds](../../../docs/phases/13_pos_invoices_returns_and_refunds.md), [fulfillment](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), [integrations](../../../docs/phases/15_external_pharmacy_integrations.md), and [Pharmacy AI](../../../docs/phases/18_pharmacy_ai.md) when applicable.

For non-trivial UI work also read the applicable client sections of [performance](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), [security/privacy](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and [production release](../../../docs/phases/23_disaster_recovery_release_and_production.md).

Inspect current ADRs, Inertia page trees, Laravel controllers/policies, OpenAPI where still used for integrations, tests, and local changes. The phase contract overrides inferred UI behavior.

## Ownership

Own doctor and pharmacy Inertia surfaces in the Laravel app:

- Inertia pages/components (typically `resources/js/Pages/Doctor` and `resources/js/Pages/Pharmacy`);
- Laravel controllers that `Inertia::render` authorized projections; form requests; client-side presentation controllers;
- localization, RTL, and accessibility;
- web printing of canonical server artifacts and barcode/camera adapters behind narrow browser APIs;
- inbox UI over Laravel Database Notifications; Firebase is the push channel (`kreait/laravel-firebase` on the server);
- Pest Inertia/HTTP tests and browser E2E for critical doctor/pharmacy journeys.

Admin Inertia pages belong to `clinic-react-admin-development`. Patient pages belong to `clinic-flutter-development`.

If the user **explicitly** requires a native desktop app, keep Laravel as the authority and isolate Electron main/preload/renderer behind typed IPC; do not infer that requirement.

## Process and dependency model

Use this flow:

```text
Inertia page -> Laravel controller / form request -> module service
             -> policy + Eloquent models + PostgreSQL
```

- Pages receive only authorized props. They have no direct access to tokens, database keys, arbitrary paths, shell, processes, or provider SDKs.
- Do not put business state transitions in React hooks or page components.
- Realtime events (Reverb) are versioned hints. Detect gaps, reload authoritative server state, and never perform a clinical or financial transition from a broadcast.
- Prefer Inertia visits/partial reloads over a parallel SPA fetch client. Keep `/api/v1` for programmatic contracts.

## Security and local-data invariants

- Use Laravel session cookies with CSRF. Never place tokens, PHI, clinical drafts, financial data, signed URLs, or AI conversations in localStorage, sessionStorage, IndexedDB, URLs, logs, or analytics.
- Doctor drafts persist on the server with explicit local-editing, pending, acknowledged, conflicted, failed, and purged presentation. Do not add an encrypted desktop SQLite cache unless a native client is explicitly required.
- Pharmacy catalog UI may keep ephemeral display cache, but stock, receipt, sale, return, refund, mapping, and connector mutations require online authoritative confirmation.
- File selection uses the existing quarantine upload flow. Printing consumes the canonical server artifact/version/hash (browser print or server-rendered PDF).
- Treat all displayed API, Markdown, file, connector, and AI content as untrusted data.

## Notifications

- Show the in-app inbox from **Laravel Database Notifications**.
- Push delivery uses **Firebase** via the Laravel `kreait/laravel-firebase` adapter. Lock-screen/push text stays generic (notification ID, safe type, opaque resource reference).

## Implementation workflow

1. Identify the application, owning phase, user intent, server contract, data classification, and acceptance gate.
2. Define the Inertia page, controller, Form Request, owning module service, models, and policy before coding.
3. Write allowed, denied, stale, duplicate, timeout, cancellation, reconnect, accessibility, and session-expiry cases.
4. Implement the smallest typed capability end to end. Re-validate authorization in Laravel even when the page already constrained the UI.
5. Preserve one idempotency key for one user intent and reconcile ambiguous outcomes through the server. Never silently retry an unknown write with a new key.
6. Add redacted telemetry through the approved client abstraction with bounded attributes and no user-entered or classified content.
7. Run focused Pest tests, affected doctor/pharmacy suites, and a production Vite/Inertia build.

If a needed backend contract, business rule, security exception, or release action is not already authorized, stop at a concrete proposal and hand it to its owner rather than embedding a UI workaround.

## Verification

Verify at minimum:

- Pest Inertia/HTTP tests for mappings, time/money/quantity, retry/idempotency, and sync state;
- integration tests for session/CSRF, API/realtime precedence, file/print adapters, revocation, and ambiguous outcomes;
- browser E2E for the critical doctor/pharmacy journey plus direct denied actions, reconnect, session expiry, printing/upload, Arabic/English, RTL, keyboard, screen reader, and large text;
- security tests proving XSS cannot widen privileges, arbitrary navigation is denied, and secrets/classified data are absent from browser stores and artifacts;
- no standalone Electron/Vite app, hidden automatic write, or enabled V1 exclusion entered the UI.

The architecture skill owns boundary decisions, business-area skills own behavior, test engineering owns shared harness/evidence mechanics, security assurance independently validates controls, observability owns SLO conclusions, and production/DR owns release mutation and approval.
