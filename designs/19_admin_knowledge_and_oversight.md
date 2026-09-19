# Admin knowledge, analytics, health, and oversight screens

All views are approved, bounded projections. There is no browser control for deployments, restores, service restarts, secrets, arbitrary SQL/PromQL/Loki, raw events/logs/traces, or direct PostgreSQL/Redis/Qdrant/S3 access.

## Screen 1 — Shared knowledge library

**Maturity:** Planned, Phase 16. **Route concept:** `/knowledge`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a responsive shared-knowledge library with persistent sidebar, scope/status filters, version table, and upload action.

- **Purpose:** Manage specialty, patient-safe, or pharmacy-shared knowledge documents under separate capabilities.
- **Layout:** Scope/status filters, paginated document table with title, language, provenance, scope, active version, ingestion/evaluation status, and updated time; upload action.
- **Content/actions:** Create document, upload a new version through quarantined flow, open version detail, and filter failures/ready items.
- **States:** Empty, uploading, processing, ready, active, failed, inactive, scope denied, stale, and AI platform unavailable.
- **Security/accessibility:** Scope is selected from allowed enums then server-derived/validated. No patient clinical documents, raw chunks/embeddings, object keys, provider/Qdrant controls, or arbitrary HTML.

## Screen 2 — Knowledge version and ingestion

**Maturity:** Planned, Phase 16. **Route concept:** `/knowledge/:documentId/versions/:versionId`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a detailed ingestion screen with source/provenance header, processing stepper, bounded manifest summary, safe error, and version history.

- **Purpose:** Understand one source version from clean-file acceptance through parse/chunk/embed/stage validation.
- **Layout:** Source/provenance header, immutable hash/version/config metadata, status stepper, bounded manifest summary/counts, safe error, retry/action history, and evaluation link.
- **Content/actions:** Inspect, retry the same failed intent, compare versions, submit for approval, and open safe source preview when authorized.
- **States:** Quarantined, processing, ready, failed retryable/permanent, source hash mismatch, capacity busy, manifest invalid, and inactive/active.
- **Rules:** “Uploaded” never means searchable; `READY` never means active. No chunk text dump, filesystem path, signed URL, or direct FastAPI call.

## Screen 3 — Knowledge approval, activation, and rollback

**Maturity:** Planned, Phase 16. **Route concept:** approval panel within version detail.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate an approval/activation comparison screen with current-versus-candidate evidence, evaluation status, approver separation, and rollback timeline.

- **Purpose:** Require explicit reviewed activation of shared knowledge and allow controlled rollback to a retained ready version.
- **Layout:** Current versus candidate comparison, provenance/manifest/evaluation evidence, scope/language, approver separation/step-up, impact note, and version timeline.
- **Content/actions:** Approve, activate, reject with configured reason, or roll back pointer to an eligible prior ready version.
- **States:** Approval required, evaluation missing/failed, ready, activating, active, concurrent activation conflict, rollback pending/completed, and Qdrant unavailable.
- **Safety:** Activation never edits prior versions or bypasses human approval. Admin knowledge capability grants no clinical access. Every action is audited with version and reason.

## Screen 4 — Operational analytics

**Maturity:** Planned, Phase 20. **Route concept:** `/analytics`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a responsive analytics dashboard with bounded filters, summary cards, ECharts-style time series/rankings, visible freshness/suppression, and equivalent data tables.

- **Purpose:** Show de-identified operational summaries, time series, and bounded specialty/medication/usage rankings.
- **Layout:** Allowlisted date/granularity/filter bar; summary cards; ECharts time series/rankings; `as_of`, watermark, freshness, suppression, units, and data-source notes; equivalent accessible tables.
- **Content/actions:** Change bounded safe filters, inspect a metric, switch chart/table, refresh, and request bounded audited aggregate export only if enabled.
- **States:** Loading, empty, true zero, suppressed small cell, stale/backlogged, not ready, partial/degraded, denied, range too large, and failed.
- **Privacy/accessibility:** No raw search text, patient/clinical dimensions, arbitrary metric expression, or reidentifying drilldown. URL filters contain only allowlisted nonsensitive IDs/values.

## Screen 5 — System health

**Maturity:** Current as a minimal health panel; planned rich Phase 20 view. **Route concept:** `/system-health`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a safe system-health dashboard with separate Core and AI heroes, component cards, checked/stale times, explicit unknown state, and no infrastructure controls.

- **Purpose:** Present a reviewed safe snapshot for API, database, realtime, queues, storage, AI, and optional backup status without revealing topology.
- **Layout:** Separate Core and AI status heroes, component cards with state/checked time/staleness/safe reason, snapshot `as_of`, approved usage/saturation summary, and compact history where provided.
- **Content/actions:** Refresh snapshot, inspect safe component detail, copy request ID, and open runbook reference only through an approved support path.
- **States:** Healthy, degraded, unavailable, unknown, stale snapshot, not configured (especially backup), loading, and denied.
- **Rules/accessibility:** AI failure does not mark Core down. “Last backup” is not “restore tested.” Status uses text/icon/color and exposes no hostnames, secrets, logs, or restart/restore controls.

## Screen 6 — Unresolved appointments

**Maturity:** Planned, Phase 20. **Route concept:** `/operations/unresolved-appointments`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate an operational unresolved-appointments screen with Cairo-date filters, paginated safe-projection table, detail drawer, and no encounter/clinical content.

- **Purpose:** Triage past appointments left in non-terminal operational states without loading encounters or clinical content.
- **Layout:** Cairo-date filters, paginated table with appointment ID, scheduled time, clinic/location, doctor operational display, current status, age, and approved contact-workflow fields; safe detail drawer.
- **Content/actions:** Open approved operational reference, invoke a normal reasoned appointment operation if separately allowed, and refresh. Analytics rows are never edited to “fix” source state.
- **States:** Empty, unresolved, stale projection/watermark, source status changed, action conflict, denied, and failure.
- **Privacy/accessibility:** No encounter relation, symptoms, diagnosis, prescription, labs, notes, patient record, or unrestricted contact data. Row actions are keyboard accessible and safe direct URLs remain capability-protected.

## Sources

Phases [16](../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md) and [20](../docs/phases/20_admin_analytics_and_system_health.md), plus the current admin health implementation.
