---
name: clinic-electron-desktop-development
description: Build this clinic project's Electron + React + TypeScript doctor and pharmacy desktop apps. Use for main/preload/renderer boundaries, typed IPC, encrypted local state, native files/printing/barcodes, API/realtime adapters, packaging, and desktop tests; not for Flutter mobile, admin web, backend rules, or release approval.
---

# Clinic Electron Desktop Development

Build the doctor and pharmacy desktop applications without moving clinical, pharmacy, financial, identity, or AI authority out of Laravel.

## Read the required sources

Read completely before changing desktop code:

- [Roadmap and invariants](../../../docs/phases/README.md)
- [Cross-cutting architecture and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The phase that owns the requested feature and every dependency whose contract the client consumes

Route additional reading by application:

- Doctor desktop: [identity](../../../docs/phases/01_auth_identity_and_access.md), [onboarding](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md), [scheduling](../../../docs/phases/03_scheduling_availability_and_booking.md), [queue](../../../docs/phases/04_realtime_queue_and_consultation_control.md), [clinical drafts](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md), [prescriptions](../../../docs/phases/06_prescriptions_reminders_and_printing.md), [labs/files/reports](../../../docs/phases/07_labs_files_reports_and_referrals.md), [notifications/chat](../../../docs/phases/09_notifications_and_post_visit_chat.md), and [Doctor AI](../../../docs/phases/17_doctor_ai.md) when applicable.
- Pharmacy desktop: [onboarding](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md), [catalog/tenancy](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), [inventory](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md), [purchasing](../../../docs/phases/12_purchasing_and_goods_receipt.md), [POS/refunds](../../../docs/phases/13_pos_invoices_returns_and_refunds.md), [fulfillment](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), [integrations](../../../docs/phases/15_external_pharmacy_integrations.md), and [Pharmacy AI](../../../docs/phases/18_pharmacy_ai.md) when applicable.

For non-trivial desktop work also read the applicable client sections of [performance](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), [security/privacy](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and [production release](../../../docs/phases/23_disaster_recovery_release_and_production.md).

Inspect current ADRs, Electron/Node support policy, Forge configuration, OpenAPI-generated TypeScript types, IPC schemas, target code, tests, packaging configuration, and local changes. The phase contract overrides inferred UI behavior.

## Ownership

Own `apps/doctor-desktop`, `apps/pharmacy-desktop`, and capability-focused shared desktop packages:

- React renderer presentation, controllers, client-domain models, repositories, localization, RTL, and accessibility;
- Electron main-process lifecycle and narrow API, realtime, encrypted-database, file, print, barcode, notification, protocol, and update adapters;
- preload scripts and versioned typed IPC contracts;
- generated OpenAPI mapping at the transport edge and explicit client failure/sync states;
- unit, component, IPC, integration, Electron E2E, security-regression, native-module, and packaging compatibility tests.

The browser admin app belongs to `clinic-react-admin-development`; shared React technology does not make its cookie/session or data-exposure rules reusable here. Flutter belongs only to the patient Android/iOS app.

## Process and dependency model

Use this inward flow:

```text
React renderer -> controller/use case -> typed preload capability
               -> validated IPC contract -> main-process application port
               -> API / realtime / authorized utility process / native adapter
```

- Renderer code has no Node or Electron imports and no direct access to tokens, database keys, arbitrary paths, shell, processes, or provider SDKs.
- Expose one intent-revealing preload method per capability. Never expose raw `ipcRenderer`, generic channel names, arbitrary HTTP, filesystem, SQL, URL, or command execution.
- Validate sender frame, schema, size, rate, current session/capability, and cancellation at every privileged IPC handler. Return serializable typed results and safe errors only.
- Main-process adapters depend on narrow application-owned ports. Do not create a desktop god bridge or place domain state transitions in IPC handlers, React hooks, preload, or native callbacks.
- Realtime events are versioned hints. Detect gaps, reload authoritative server state, and never perform a clinical or financial transition from a broadcast.

## Security and local-data invariants

- Load packaged local renderer content only. Keep `contextIsolation` and renderer sandboxing enabled, `nodeIntegration` disabled, `webSecurity` enabled, insecure content disabled, and CSP restrictive.
- Deny permissions by default. Block unexpected navigation, child windows, webviews, downloads, custom protocols, and external URLs. Allow an external URL only after strict parsing, allowlisting, and explicit user intent.
- Prefer a privileged custom application protocol over permissive `file://`. Treat all displayed API, Markdown, file, connector, and AI content as untrusted data.
- Keep device tokens in the main process and wrap them with Electron `safeStorage`; prefer its asynchronous API where supported. On Linux, do not persist secrets when the selected backend is an unprotected fallback such as `basic_text`.
- Never place tokens, database keys, PHI, clinical drafts, financial data, raw paths, signed URLs, or AI conversations in localStorage, sessionStorage, IndexedDB, URLs, logs, crash reports, analytics, or unencrypted preferences.
- Doctor desktop may keep only the approved encrypted transient draft and idempotent outbox. Show local, pending, acknowledged, conflicted, failed, revoked, and purged states honestly.
- Use an ADR-approved maintained SQLite binding built with SQLCipher or SQLite3MultipleCiphers for sensitive desktop persistence. The main process owns authorization and key release; prefer an Electron utility process for synchronous native database work when the target-OS/ABI spike proves the isolation and packaging contract. Test key rotation, recovery, backup exclusion, native ABI, utility-process crash behavior, and every supported OS/architecture.
- Pharmacy catalog UI may cache bounded data, but stock, receipt, sale, return, refund, mapping, and connector mutations require online authoritative confirmation.
- File selection returns an opaque bounded handle through a narrow adapter; main-process validation and the server quarantine flow remain mandatory. Printing consumes the canonical server artifact/version/hash.
- Flip reviewed Electron fuses at package time to remove unused Node/inspection entry points and enforce packaged-code integrity. Signing, notarization, update publication, rollout, rollback, and release approval remain with `clinic-production-dr-release`.

## Packages and tooling

Resolve versions against the repository-supported Electron, Node, React, and TypeScript matrix, then pin them in the committed lockfile.

- Runtime/build: Electron, React/React DOM, TypeScript, Electron Forge and approved makers, `@electron/fuses`, and native-module rebuild tooling.
- Renderer/application: TanStack Query, React Router, React Hook Form, Zod and Hook Form resolvers, MUI, and i18next/react-i18next.
- Contracts/transports: `openapi-typescript` with `openapi-fetch` or the repository generator, plus one approved Reverb/SSE adapter. The main process owns authenticated transport.
- Local/native: built-in `safeStorage`, `dialog`, `webContents.print`, and an ADR-approved encrypted SQLite binding. Barcode and device integrations stay behind narrow adapters.
- Verification: Vitest, React Testing Library, MSW in Node mode, WebdriverIO with `@wdio/electron-service` for packaged Electron E2E, axe-core, type-aware ESLint, Prettier, dependency-boundary rules, dependency audit, and SBOM tooling.

Use the Phase 00 Electron Forge Webpack/TypeScript baseline. Playwright's Electron launcher or another experimental build/launcher integration requires a pinned target-platform compatibility spike and ADR before it replaces the packaged WebdriverIO path.

## Implementation workflow

1. Identify the application, owning phase, user intent, server contract, local-data classification, native capabilities, offline semantics, and acceptance gate.
2. Define renderer, preload, IPC, main application port, and adapter ownership before coding. Record an ADR if the process or trust boundary changes.
3. Write allowed, denied, stale, duplicate, timeout, cancellation, reconnect, crash/restart, update, accessibility, and renderer-compromise cases.
4. Implement the smallest typed capability end to end. Validate again in the main process even when the renderer already used Zod.
5. Preserve one idempotency key for one user intent and reconcile ambiguous outcomes through the server. Never silently retry an unknown write with a new key.
6. Add redacted telemetry through the approved client abstraction with bounded attributes and no user-entered or classified content.
7. Run focused tests, affected desktop/shared suites, generated-contract checks, native compatibility tests, and packaged Electron E2E.

If a needed backend contract, domain rule, IPC architecture, security exception, or release action is not already authorized, stop at a concrete proposal and hand it to its owner rather than embedding a desktop workaround.

## Verification

Verify at minimum:

- TypeScript strict checks, lint/format, dependency boundaries, generated-client consistency, and production packaging for affected platforms;
- unit tests for mappings, reducers/controllers, IPC schemas, time/money/quantity, retry/idempotency, and sync state;
- integration tests for preload/main contracts, sender validation, secure storage, encrypted database, API/realtime precedence, file/print/barcode adapters, revocation, and ambiguous outcomes;
- packaged WebdriverIO Electron E2E for the critical doctor/pharmacy journey plus direct denied actions, offline/reconnect, session expiry, printing/upload, Arabic/English, RTL, keyboard, screen reader, and large text;
- system tests for crash/restart, second instance, network partition, sequence gaps, wrong/corrupt database keys, interrupted migration/rotation, update failure, upgrade/rollback compatibility, and long-running memory/handle leakage;
- security tests proving renderer XSS cannot reach Node/native capabilities, arbitrary IPC/navigation/window/external URL is denied, CSP/permissions/fuses hold, and secrets/classified data are absent from renderer stores and artifacts;
- install, uninstall, native ABI, package integrity, and signing/notarization/update-provenance handoff evidence for every supported OS/architecture.

The architecture skill owns boundary decisions, domain skills own behavior, test engineering owns shared harness/evidence mechanics, security assurance independently validates controls, observability owns SLO conclusions, and production/DR owns release mutation and approval.
