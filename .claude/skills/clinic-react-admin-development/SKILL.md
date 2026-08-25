---
name: clinic-react-admin-development
description: Implement or refactor this clinic project's browser-based React and TypeScript admin application. Use for verification, catalog/knowledge administration, safe analytics/health projections, forms, charts, localization, accessibility, generated API integration, and admin tests; not for Electron doctor/pharmacy desktops, clinical access, infrastructure control, or backend policy ownership.
---

# Clinic React Admin Development

Build browser admin features as least-privilege projections over explicit Laravel policies. “Admin” is a UI persona, not permission to view clinical data or operate infrastructure.

## Read the required sources

Read completely:

- [Roadmap, invariants, open decisions, and evidence policy](../../../docs/phases/README.md)
- [Cross-cutting React/API/security architecture](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The phase that owns the requested capability.

Route additional reading by feature:

- Verification/onboarding: [Phase 02](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md)
- Medication catalog/tenancy: [Phase 10](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md)
- Shared knowledge administration: [Phase 16](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md)
- Analytics, unresolved appointments, and safe health: [Phase 20](../../../docs/phases/20_admin_analytics_and_system_health.md)
- Client and admin assurance: [Phase 22](../../../docs/phases/22_security_privacy_and_compliance_validation.md)
- Artifact/release behavior: [Phase 23](../../../docs/phases/23_disaster_recovery_release_and_production.md)

Inspect current route/policy contracts, generated OpenAPI TypeScript types, feature folders, design-system usage, MSW fixtures, Playwright tests, and local changes before implementation.

## Ownership

Own only the browser application under `apps/admin-web` and its client integration:

- routes/pages, feature components, bounded forms/schemas, TanStack queries/mutations, tables/charts, filters, error and freshness states;
- generated API-client wrapper, server DTO mapping, CSRF/session handling, and request-ID/error presentation;
- Arabic/English localization, RTL, accessibility, tabular chart alternatives, and exact numeric/date formatting;
- React unit/component/API-integration/E2E/security-regression tests.

TanStack Query owns server state. Keep temporary UI/form state local. Add global client state only for a demonstrated cross-route need.

Electron doctor/pharmacy renderer, preload, main-process, desktop device-token, encrypted local-data, native adapter, and packaging work belongs to `clinic-electron-desktop-development`. Do not reuse the admin browser's cookie/CSRF assumptions in desktop clients merely because both use React and TypeScript.

## Hard boundaries

- Laravel owns authentication, capability authorization, record projections, state transitions, filtering, small-cell suppression, audit, and feature flags. UI route guards and hidden buttons are not enforcement.
- Use secure HttpOnly/Secure/SameSite cookie sessions with CSRF. Never place an admin bearer token or session material in local storage.
- Admin cannot read medical records, diagnoses, allergies, medications, prescriptions, labs, files, notes, reports, symptoms, AI conversation content, or pharmacy stock availability.
- Health is a reviewed safe snapshot, not direct access to Prometheus, Grafana, Loki, Sentry, Horizon, database, Redis, Qdrant, S3, backup systems, secrets, or internal topology.
- The browser cannot restart services, trigger restores/deployments, rotate secrets, run arbitrary queries, select raw metric dimensions, or build SQL/PromQL/Loki expressions.
- Before Phase 23 supplies its optional projection, backup status is `UNKNOWN / not configured`; never infer healthy or restored.
- Do not introduce raw exports, dynamic BI, complex admin roles, or another future-only capability unless a later approved phase owns it.

## UI and data rules

- Use generated OpenAPI types at the transport edge and Zod/React Hook Form for bounded user input. Do not create a second source of API truth.
- Render loading, empty, zero, suppressed, stale, unknown, degraded, denied, validation, conflict, rate-limit, and failure states distinctly.
- Display `as_of`, watermark, freshness, suppression, source type, and safe status when the server contract provides them.
- Never derive security-sensitive totals, permissions, health, or workflow eligibility from hidden raw browser data.
- Metric/filter/sort keys are explicit enums from the server. Treat chart labels/localized content as untrusted output and prevent HTML/CSV formula injection.
- Keep PHI, credentials, raw search text, prompts/responses, internal addresses, object keys, and verbose provider errors out of browser logs, telemetry, URLs, caches, test snapshots, and exports.
- Use ECharts only as a presentation adapter; provide an accessible table/summary conveying the same approved data.

## Implementation workflow

1. Identify the owning phase, exact capability/policy, approved projection, actor state, freshness semantics, and acceptance gate.
2. Inspect the OpenAPI contract and existing feature architecture. If required data is not in an authorized projection, request a backend contract change instead of fetching a broader resource.
3. Define permitted and denied user journeys plus loading/stale/unknown/conflict/session-expiry behavior.
4. Implement route/page, query/mutation hooks, Zod form schema, components, and generated-client mapping with one clear owner per concern.
5. Preserve CSRF/session/request-ID/error behavior in the central transport wrapper; do not bypass it in a feature.
6. Add localization/accessibility and redacted telemetry using the project abstractions.
7. Run focused tests, generated-contract checks, full affected admin suites, and a production build.

## Verification

Verify at least:

- TypeScript strict checks, ESLint/format, generated-client compatibility, and production build;
- unit tests for mapping, bounded filters, freshness/suppression/health state, error handling, and sanitization;
- React Testing Library tests for permitted, denied, empty, stale, unknown, degraded, and session-expired states;
- MSW API integration tests for `401/403/404/409/422/429/5xx`, CSRF, pagination, cancellation, and compatible contract changes;
- Playwright E2E for the critical admin journey and direct-URL attempts to prohibited clinical/raw-infrastructure data;
- axe, keyboard, focus, screen-reader, RTL, large-text, non-color-only health, and chart table alternatives;
- XSS/DOM/CSV injection, clickjacking/header/CSP integration, session fixation/revocation, cache/history/logout, and no-token-in-local-storage checks;
- no clinical data, stock dashboard, arbitrary query/export, infrastructure control, secret, or future-only capability entered the browser bundle or API calls.

The observability skill owns engineering telemetry/SLOs, security assurance independently validates the assembled surface, and production/DR owns operational actions. This skill consumes their safe contracts but does not replace them.
