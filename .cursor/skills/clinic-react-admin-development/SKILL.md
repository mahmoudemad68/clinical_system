---
name: clinic-react-admin-development
description: Implement or refactor this clinic project's browser admin UI as Inertia.js pages inside the Laravel app. Use for verification, catalog/knowledge administration, safe analytics/health projections, forms, charts, localization, accessibility, and admin tests; not for doctor/pharmacy/patient persona pages, clinical access, infrastructure control, or backend policy ownership.
---

# Clinic Inertia Admin Development

Build browser admin features as least-privilege Inertia projections over explicit Laravel policies. “Admin” is a UI persona, not permission to view clinical data or operate infrastructure.

Admin UI lives **inside the Laravel codebase** (`resources/js` Inertia pages). Do **not** create `apps/admin-web` or any standalone frontend project.

## Read the required sources

Read completely:

- [Roadmap, invariants, open decisions, and evidence policy](../../../docs/phases/README.md)
- [Cross-cutting architecture](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The phase that owns the requested capability.

Route additional reading by feature:

- Verification/onboarding: [Phase 02](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md)
- Medication catalog/tenancy: [Phase 10](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md)
- Shared knowledge administration: [Phase 16](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md)
- Analytics, unresolved appointments, and safe health: [Phase 20](../../../docs/phases/20_admin_analytics_and_system_health.md)
- Client and admin assurance: [Phase 22](../../../docs/phases/22_security_privacy_and_compliance_validation.md)
- Artifact/release behavior: [Phase 23](../../../docs/phases/23_disaster_recovery_release_and_production.md)

Inspect current Inertia routes/controllers, policies, page folders, form requests, Pest Inertia assertions, and local changes before implementation.

## Ownership

Own only the admin Inertia surface in the Laravel app:

- Inertia pages/components under an admin namespace (typically `resources/js/Pages/Admin` and shared admin components);
- Laravel controllers that `Inertia::render` authorized admin projections; form requests; Ziggy/Inertia navigation;
- bounded forms, tables/charts, filters, error and freshness states;
- Arabic/English localization, RTL, accessibility, tabular chart alternatives, and exact numeric/date formatting;
- Pest Inertia/HTTP tests plus browser E2E for critical admin journeys.

Doctor and pharmacy pages belong to `clinic-electron-desktop-development`. Patient pages belong to `clinic-flutter-development`. Laravel policies and domain writes belong to the backend/domain skills.

Do not scaffold a separate React SPA, Next.js app, or Vite-only admin package.

## Hard boundaries

- Laravel owns authentication, capability authorization, record projections, state transitions, filtering, small-cell suppression, audit, and feature flags. UI route guards and hidden buttons are not enforcement.
- Use Laravel session cookies with CSRF (Inertia’s default). Never place an admin bearer token or session material in local storage.
- Admin cannot read medical records, diagnoses, allergies, medications, prescriptions, labs, files, notes, reports, symptoms, AI conversation content, or pharmacy stock availability.
- Health is a reviewed safe snapshot, not direct access to Telescope, Prometheus, Grafana, Loki, Sentry, Horizon, database, Redis, Qdrant, S3, backup systems, secrets, or internal topology.
- Telescope is for local/non-production debugging. Do not expose Telescope to admin users as an operations console.
- The browser cannot restart services, trigger restores/deployments, rotate secrets, run arbitrary queries, select raw metric dimensions, or build SQL/PromQL/Loki expressions.
- Before Phase 23 supplies its optional projection, backup status is `UNKNOWN / not configured`; never infer healthy or restored.
- Do not introduce raw exports, dynamic BI, complex admin roles, or another future-only capability unless a later approved phase owns it.

## UI and data rules

- Prefer authorized Inertia props from Laravel controllers. Do not create a second SPA API client as the primary admin data path. Keep `/api/v1` OpenAPI for programmatic/integration contracts, not a parallel admin SPA.
- Render loading, empty, zero, suppressed, stale, unknown, degraded, denied, validation, conflict, rate-limit, and failure states distinctly.
- Display `as_of`, watermark, freshness, suppression, source type, and safe status when the server contract provides them.
- Never derive security-sensitive totals, permissions, health, or workflow eligibility from hidden raw browser data.
- Metric/filter/sort keys are explicit enums from the server. Treat chart labels/localized content as untrusted output and prevent HTML/CSV formula injection.
- Keep PHI, credentials, raw search text, prompts/responses, internal addresses, object keys, and verbose provider errors out of browser logs, telemetry, URLs, caches, test snapshots, and exports.
- Use charts only as a presentation adapter; provide an accessible table/summary conveying the same approved data.

## Implementation workflow

1. Identify the owning phase, exact capability/policy, approved projection, actor state, freshness semantics, and acceptance gate.
2. Inspect the Laravel controller/policy and existing Inertia page architecture. If required data is not in an authorized projection, request a backend contract change instead of fetching a broader resource.
3. Define permitted and denied user journeys plus loading/stale/unknown/conflict/session-expiry behavior.
4. Implement the Inertia page, Laravel form request, and controller mapping with one clear owner per concern.
5. Preserve session/CSRF/request-ID/error behavior; do not bypass Inertia/Laravel middleware in a feature.
6. Add localization/accessibility and redacted telemetry using the project abstractions.
7. Run focused Pest Inertia tests, affected admin suites, and a production Vite/Inertia build.

## Verification

Verify at least:

- TypeScript/ESLint/format where used, Pest Inertia assertions, and production frontend build;
- Pest tests for mapping, bounded filters, freshness/suppression/health state, error handling, and sanitization;
- permitted, denied, empty, stale, unknown, degraded, and session-expired states;
- `401/403/404/409/422/429/5xx`, CSRF, pagination, and compatible contract changes;
- browser E2E for the critical admin journey and direct-URL attempts to prohibited clinical/raw-infrastructure data;
- axe, keyboard, focus, screen-reader, RTL, large-text, non-color-only health, and chart table alternatives;
- XSS/DOM/CSV injection, clickjacking/header/CSP integration, session fixation/revocation, cache/history/logout, and no-token-in-local-storage checks;
- no clinical data, stock dashboard, arbitrary query/export, infrastructure control, secret, Telescope console, or future-only capability entered the admin bundle or props.

The observability skill owns engineering telemetry/SLOs, security assurance independently validates the assembled surface, and production/DR owns operational actions. This skill consumes their safe contracts but does not replace them.
