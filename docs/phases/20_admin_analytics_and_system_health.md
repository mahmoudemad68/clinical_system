# Phase 20 — Admin Analytics and System Health

## Objective

Deliver an admin dashboard for de-identified operational analytics and a safe, freshness-aware summary of platform health. Analytics are derived, eventually consistent, rebuildable data. Health information is diagnostic enough for an authorized administrator to recognize degradation and follow a runbook, but never exposes infrastructure secrets, credentials, internal addresses, raw logs/traces, patient clinical content, prescriptions, lab contents, AI prompts/responses, or branch stock availability.

The phase also surfaces unresolved old appointments for operational follow-up without granting admin clinical-record access.

## Plan traceability

- Sections 12 and 14, lines 492-509 and 571-598: admin is a role but full admin does not imply clinical access; non-clinical projections remain explicit.
- Sections 99-104, lines 2879-3028: private realtime, durable notification/queue processing, Horizon, and transactional outbox as event sources.
- Sections 109 and 112, lines 3117-3213 and 3257-3274: `daily_analytics`/`backup_runs` data and measured partitioning only where justified.
- Sections 120 and 122-123, lines 3403-3496: audit events, sensitive-log exclusions, and privacy/minimization.
- Sections 132-143, lines 3640-3915: latency/capacity/availability targets, scaling topology, health checks, and platform metrics.
- Sections 144-146, lines 3916-3985: safe admin health, permitted analytics, prohibited clinical/stock content, and unresolved/no-show handling.
- Sections 156 and 160-162, lines 4182-4215 and 4268-4330: test pyramid and performance/load/stress verification.
- Sections 165, 169-170, lines 4367-4391 and 4465-4502: environment isolation, secret handling, and feature flags.
- Sections 171-174, lines 4503-4622: V1 exclusions, derived-data ownership, eventual consistency, and asynchronous processing.

## Entry criteria and dependencies

- Phase 00 provides outbox/event envelopes, audit, scheduler/queues, telemetry, data classification, health conventions, and admin session security.
- Phases 02-19 publish stable domain events or safe query projections for onboarding, appointments, pharmacy, search, notifications, and AI.
- Phase 03 provides canonical appointment states and scheduling timestamps. This phase owns the derived daily unresolved-detection job/projection; it does not rewrite the Phase 03 state machine.
- Phase 21 will prove scale/SLO behavior; this phase must expose the metrics and freshness needed for that proof.
- This phase defines an optional `BackupStatusProjection` port and ships a null adapter that reports `UNKNOWN / not configured`. Phase 23 later supplies the real safe projection after backup and restore evidence exists; Phase 20 has no forward runtime or delivery dependency on Phase 23.
- Product, privacy, security, operations, and clinical owners approve the allowed metric/dimension catalog before production data flows.

## Non-goals

- No medical-record, diagnosis, allergy, medication, prescription, lab, file, note, report, symptom, or AI conversation-content analytics.
- No pharmacy stock-availability dashboard, branch-transfer view, individual sales surveillance, dynamic BI query builder, or arbitrary SQL/report export.
- No complex admin-role redesign in V1; every endpoint still has a specific policy despite one admin role.
- No analytics database as an operational source of truth and no business transition driven from an aggregate.
- No external analytics SaaS receiving production sensitive/personal payloads without a separate approved privacy/security decision.
- No guarantee that health is current when a collector is stale; the UI must show `UNKNOWN/STALE` rather than infer healthy.
- No infrastructure control plane: admins cannot restart services, run queries, rotate secrets, or trigger deployments from this dashboard.

## Architecture, ownership, and SOLID boundaries

### Ownership

```text
Source domain modules
  own operational truth and publish minimal versioned events

Analytics module
  owns event-to-fact mapping, deduplication, aggregates, watermarks,
  backfill/rebuild commands, freshness and admin analytics queries

Operations/Health module
  owns safe component-state vocabulary, collectors, snapshots and runbook links

Admin module
  owns authorization and response projections; it does not query clinical tables

React admin
  owns presentation/filter URL state; it is never an authorization or calculation authority
```

Analytics consumers may use published events or reviewed read-model ports. They must not import source-domain Eloquent models or scan raw clinical tables. Health collectors query internal health/metrics adapters with least-privilege credentials; an admin HTTP request does not synchronously fan out to every dependency.

### Ports

```text
AnalyticsEventConsumer
AnalyticsFactMapper
AnalyticsAggregateRepository
AnalyticsWatermarkRepository
AnalyticsRebuilder

OperationalCountReader
UnresolvedAppointmentReader
BackupStatusProjection
  latestSafeStatus() -> configured status or UNKNOWN_NOT_CONFIGURED
AiUsageSummaryReader

ComponentHealthProbe
MetricsBackendReader
SystemHealthSnapshotRepository
RunbookCatalog
```

- **Single responsibility:** fact mapping, aggregation, health collection, authorization, and presentation are separate.
- **Open/closed:** a new approved metric or component is a versioned mapper/probe, not a change to a universal query switch.
- **Liskov substitution:** Prometheus/managed-metrics/local adapters return the same typed value/freshness/error contract.
- **Interface segregation:** admin analytics receives aggregate/read-model ports only, never medical-record or stock repository interfaces.
- **Dependency inversion:** application code owns metric/health contracts; SQL, Prometheus, Grafana, Redis, and HTTP are adapters.

## Packages and runtime components

Versions are pinned under Phase 00.

### Laravel/PHP

- Existing Laravel scheduler, Horizon, PostgreSQL, Redis, outbox, audit, OpenTelemetry, and Sentry infrastructure.
- PostgreSQL aggregate tables and ordinary SQL/query builder; do not add an OLAP platform before measured need.
- Prometheus/OpenTelemetry metrics reader adapter only if the deployment exposes an authenticated internal query API; never use browser credentials for it.
- Pest/PHPUnit with real PostgreSQL/Redis for event replay, late data, concurrency, and rebuild tests.

### React/TypeScript

- Existing React, TypeScript, Vite, TanStack Query, React Router, MUI, i18next, and Apache ECharts.
- React Hook Form/Zod for bounded filters, generated OpenAPI types/client, Vitest, React Testing Library, MSW, Playwright, and axe-core.
- Charts receive server-provided numeric series; they do not calculate security-sensitive totals from hidden raw rows.

### Operations

- Existing Prometheus, Grafana, Loki-compatible logs, OpenTelemetry Collector, and Sentry.
- Grafana remains the engineering operations console; the admin dashboard exposes only a simplified reviewed projection.

## Persistent schemas, invariants, and indexes

```text
analytics_consumed_events
  consumer_name string
  event_id UUID
  schema_version integer
  consumed_at UTC
  primary key (consumer_name, event_id)

analytics_daily_metrics
  metric_date date                 # Africa/Cairo business date where defined
  metric_key string
  dimension_set_id UUID
  value_numeric numeric
  source_max_occurred_at UTC
  updated_at UTC
  version integer
  primary key (metric_date, metric_key, dimension_set_id)

analytics_dimension_sets
  id UUID PK
  schema_version integer
  dimension_hash bytea unique
  dimensions jsonb                 # strict bounded allowlist only

analytics_watermarks
  pipeline_key string PK
  last_event_occurred_at UTC nullable
  last_event_consumed_at UTC nullable
  last_successful_rebuild_at UTC nullable
  backlog_count bigint
  status enum CURRENT | LAGGING | FAILED | REBUILDING
  updated_at UTC

system_health_snapshots
  id UUIDv7 PK
  captured_at UTC
  expires_at UTC
  overall_state enum HEALTHY | DEGRADED | UNAVAILABLE | UNKNOWN
  source_version string

system_health_components
  snapshot_id UUID FK
  component_key string
  state enum HEALTHY | DEGRADED | UNAVAILABLE | UNKNOWN
  checked_at UTC
  stale_after UTC
  safe_reason_code string nullable
  safe_summary_key string
  runbook_key string nullable
  primary key (snapshot_id, component_key)
```

Allowed metric keys include counts of doctors, pharmacies, branches, patients, appointments by non-clinical status, most-requested specialty, most-searched canonical medication, safely defined active users, aggregate AI usage/storage, and service health. Raw search text is never an analytics dimension; resolve to canonical medication ID or record only a safe unmatched-count bucket.

Indexes:

- `analytics_daily_metrics(metric_key, metric_date desc)` and `(dimension_set_id, metric_date desc)`.
- GIN on `analytics_dimension_sets.dimensions` only if approved filter/query evidence needs it; dimension keys/values remain allowlisted.
- `analytics_watermarks(status, updated_at)`.
- `system_health_snapshots(captured_at desc)` and components `(component_key, checked_at desc)`.
- Unresolved appointment source index follows Phase 03, e.g. `(status, scheduled_end_at)` for eligible old states.
- Partitioning/retention begins only after volume evidence and uses expand/backfill/switch/contract migrations.

### Hard invariants

1. Analytics is derived and rebuildable. Source domain records/events remain authoritative.
2. Aggregation consumers are idempotent by `(consumer_name,event_id)` and apply correction/reversal events rather than editing source history.
3. Metric/dimension keys come from a reviewed compile-time/server registry. Requests cannot choose tables, columns, SQL, arbitrary groupings, or free-text dimensions.
4. No metric, dimension, export, cache, log, trace, or chart payload contains clinical content, national ID, phone, address, raw search text, AI content, credential, or object key.
5. Small-cell suppression/minimum cohort rules apply to dimensions where identity or sensitive behavior could be inferred. Suppressed cells do not become zero.
6. Admin health shows safe states, captured/check times, freshness, and runbook key; missing/stale evidence becomes `UNKNOWN`, never `HEALTHY`.
7. AI degradation does not set core unavailable. Core and AI availability are reported separately.
8. Health/analytics requests never block on live calls to every service and never expose private topology/secrets.
9. Unresolved appointment operations include only booking/identity fields required for follow-up; no encounter/diagnosis/note/lab/prescription fields are joinable through this projection.
10. Cached dashboards may be stale but must disclose watermark/as-of time and cannot authorize or drive a business mutation.
11. Until Phase 23 binds a verified `BackupStatusProjection`, backup health is `UNKNOWN / not configured`; absence can never be rendered as healthy or as a successful restore.

## Detailed data and control flows

### 1. Consume an operational event

1. A source module commits its state and minimal outbox event atomically.
2. The analytics worker claims the event and validates namespace/schema/signature/envelope.
3. A versioned mapper accepts only expected safe fields and maps them to one or more allowlisted fact deltas.
4. In one transaction, the consumer inserts its deduplication key, applies aggregate deltas/upserts with compare-and-set/versioning, updates the watermark, and commits.
5. Duplicate delivery finds the dedupe key and returns success without changing totals.
6. Unknown incompatible schema moves to an operator-visible failed state; it never guesses a mapping.
7. Late or correction events apply to their correct business date and update `source_max_occurred_at` while preserving auditability.

### 2. Rebuild analytics

1. An operator starts a scoped, audited rebuild for a metric/date range using a runbook command, not the admin browser.
2. The job pins mapper/schema versions, creates a shadow aggregate namespace/table, and reads only approved source projections or archived safe events in bounded cursor batches.
3. It checkpoints progress, rate-limits DB load, and can cancel/resume without duplicating deltas.
4. Reconciliation compares rebuilt totals to source control counts and records discrepancies.
5. After validation, a transaction/metadata switch activates the new version. Failure leaves the old aggregates readable and marks freshness degraded.

### 3. Query an analytics dashboard

1. Admin browser uses secure cookie session/CSRF and sends bounded date range plus allowlisted filters.
2. Laravel authenticates, applies the specific analytics policy, validates maximum range/granularity, and resolves the metric registry entry.
3. Query reads aggregate tables only, applies small-cell suppression and result-size/cursor limits, and returns value, unit, as-of/watermark, suppression/freshness metadata.
4. TanStack Query caches by exact safe filter key/TTL and shows loading, stale, empty, suppressed, and unavailable states distinctly.
5. Export, if enabled, uses the same aggregate API/policy/limits and asynchronous signed private delivery; arbitrary raw export is out of scope.

### 4. Collect system health

1. A scheduled health collector runs under a least-privilege service identity, independently from admin page requests.
2. It obtains bounded component signals: API, database, realtime, queue, storage, AI, optional backup status, and approved usage/saturation summaries. The null backup adapter returns `UNKNOWN_NOT_CONFIGURED` without a network call.
3. Each probe has deadline, typed state, checked time, stale threshold, safe reason code, and no secret payload.
4. The aggregator computes core and AI states separately using a reviewed dependency graph; optional AI failure degrades AI only.
5. It stores an immutable short-retention snapshot and publishes safe metrics. If a probe times out, state is `UNKNOWN/DEGRADED` according to policy.
6. Admin API returns the newest non-expired snapshot. An expired snapshot is labeled stale and cannot claim current health.

### 5. Unresolved appointment flow

1. A Phase 20 daily derived-projection job identifies past appointments in non-terminal states using Cairo business rules and exposes them as `UNRESOLVED` operationally without rewriting the Phase 03 appointment state or clinical history.
2. Admin query returns appointment ID, scheduled time, clinic/location, doctor operational identifier/display, current appointment status, age, and approved contact workflow fields only.
3. No encounter/clinical relation is loaded. Admin action, if any, invokes a normal appointment operations command with audit and state validation; analytics rows are never mutated to “fix” source state.

### 6. Failure and concurrency behavior

- Consumer crash before commit: event is retried; no dedupe row/partial aggregate survives.
- Crash after commit before acknowledgement: replay sees dedupe and has no second effect.
- Two workers receive one event: unique dedupe key allows one aggregate change.
- Metrics backend unavailable: health collector records `UNKNOWN`; admin API serves last snapshot with explicit staleness or a safe unavailable response.
- Redis/cache unavailable: query may use PostgreSQL aggregates within rate/query limits; no data is lost.
- Source event backlog: dashboard remains available but prominently shows lag watermark; alerts trigger before silent staleness.
- Bad mapper release: pause affected consumer, keep prior aggregates, deploy fixed version, and rebuild/reconcile.

## API, event, and job contracts

### Admin API

```text
GET /api/v1/admin/analytics/summary
GET /api/v1/admin/analytics/timeseries
GET /api/v1/admin/analytics/rankings/specialties
GET /api/v1/admin/analytics/rankings/medications
GET /api/v1/admin/analytics/usage
GET /api/v1/admin/system-health
GET /api/v1/admin/appointments/unresolved
```

Filters are explicit OpenAPI enums/IDs with maximum date span, granularity, page size, and sort choices. Responses contain `as_of`, `watermark`, `freshness_state`, `suppressed`, and `request_id`. No endpoint accepts metric SQL, field names, expression language, arbitrary JSON dimensions, PromQL, Loki query, trace ID search, or internal host/service names.

Stable errors include `ANALYTICS_FILTER_INVALID`, `ANALYTICS_RANGE_TOO_LARGE`, `ANALYTICS_NOT_READY`, `ANALYTICS_STALE`, `HEALTH_SNAPSHOT_STALE`, and generic denied/not-found responses.

### Source-event contract

Approved events include identifiers and non-sensitive state changes such as profile approved, appointment booked/completed/cancelled/no-show, branch approved, canonical medication searched, AI run terminal safe usage, and service/backup run terminal state. Each metric mapper documents source event/version, counting identity, business-time rule, correction semantics, dimensions, classification, and reconciliation query.

### Jobs

- `ConsumeAnalyticsEvent`, `AggregateDailyMetric`, `CollectSystemHealth`, `DetectUnresolvedAppointments`, `RebuildAnalyticsRange`, `ReconcileAnalytics`, and retention jobs.
- Queue `analytics` is isolated from critical/notification/AI work. Jobs are idempotent, bounded by cursor/time/rows, have capped retries/jitter, expose progress/checkpoint, and dead-letter safely.
- Monthly/heavy aggregation never runs in a user HTTP request.

## React admin work

- Summary cards and ECharts time series/rankings show units, range, Cairo dates, as-of/watermark, freshness, suppression, and data-source notes.
- System health uses accessible text/icons for `HEALTHY`, `DEGRADED`, `UNAVAILABLE`, and `UNKNOWN`; never rely on color alone.
- AI health is separate from core health. Last-backup time is not equivalent to a successful restore test and must be labeled accordingly.
- Empty, zero, suppressed, stale, permission-denied, and failed states are visually and semantically distinct.
- Filters are URL-shareable only when they contain non-sensitive allowlisted IDs/values; no tokens or raw query text.
- Unresolved appointment table exposes only the approved operational projection and uses server pagination.
- Test Arabic/English, RTL, locale-aware but exact numeric/date formatting, keyboard navigation, screen readers, large text, and chart tabular alternatives.

## Security and privacy controls

- Secure HttpOnly/Secure/SameSite admin session, CSRF, MFA, short idle/absolute timeouts, device/session revocation, and endpoint-specific authorization.
- Deny direct admin access to clinical repositories, raw events, logs/traces, Prometheus/Grafana/Loki/Sentry, DB, Redis, Qdrant, S3, or backup storage.
- Use a reviewed metric/dimension registry, parameterized queries, range/result/time limits, rate limits, and asynchronous bounded export if introduced.
- Apply small-cell suppression, minimum cohort, safe ranking limits, and canonical IDs to reduce re-identification/inference risk.
- Separate analytics and health read credentials from application/migration/backup credentials; rotate/audit access.
- Sanitize chart labels and server-controlled localization; prevent XSS/CSV injection and formula execution in any approved export.
- Audit admin view/filter/export/unresolved-action/config access with IDs and safe metadata, never returned data payloads.
- External analytics/monitoring integrations require explicit data-flow inventory, processor terms, retention/residency, and security/privacy approval.

## Test plan

### Unit tests

- Event-to-delta mappings, business-date conversion, reversal/correction/late-event behavior, safe active-user definition, and canonical medication counting.
- Metric/dimension registry rejects unknown/free-text/clinical/stock dimensions and enforces date/granularity/result limits.
- Small-cell suppression, ranking ties, zero/empty/suppressed distinction, freshness/watermark, and health-state dependency logic.
- Health probe timeout/error/stale mapping and AI-degraded/core-healthy calculation.
- Redaction and CSV/chart sanitization including Arabic/Unicode/formula payloads.

### Integration tests

- Real PostgreSQL/Redis/outbox verifies atomic dedupe/aggregate update, duplicate workers, crash/replay, late events, correction events, rebuild swap, and reconciliation.
- Source-domain fixtures prove no analytics query joins clinical tables or stock balances.
- Health adapters test dependency timeout, stale snapshot, Redis loss, DB/read replica lag, queue backlog, AI/Qdrant outage, the null/real backup-status adapters, and metrics-backend failure.
- Admin cookie/CSRF/MFA/session expiration and audit records use real policy/database integration.

### Contract tests

- OpenAPI-generated TypeScript client covers every metric/time-series/ranking/health/unresolved schema and stable error.
- Each event mapper accepts supported schemas, rejects incompatible versions, and maintains compatibility fixtures.
- Every health probe adapter returns the same state/freshness/safe-reason contract and respects deadlines.
- Dashboard MSW fixtures prove unknown fields/content cannot become chart HTML or a query expression.

### End-to-end tests

- Authorized admin sees permitted counts/status/rankings/health with freshness and can inspect unresolved operational appointments without clinical data.
- Admin cannot access medical records, prescriptions, lab content, AI conversations, stock availability, raw logs/traces, or another internal endpoint by URL manipulation.
- Delayed analytics shows stale watermark; stopped health collector shows `UNKNOWN`, not healthy.
- AI outage displays AI degraded while core remains healthy; core DB outage is classified separately; before Phase 23, backup status is visibly `UNKNOWN / not configured` rather than healthy.
- Arabic/English dashboards and accessible table alternatives render correct values.

### System, load, and security tests

- Replay a large synthetic event range with duplicates/out-of-order corrections while normal API SLOs remain within budget and final aggregates reconcile.
- Concurrent dashboard users, rebuild, source traffic, and collector load remain within query/connection pool limits; expensive ranges are rejected.
- Injection tests cover filter IDs, JSON, sort, cursor, chart label, CSV formula, XSS, PromQL/SQL/Loki strings, path traversal, and oversized ranges.
- Broken-object/function authorization, CSRF, session fixation, stale privilege, health topology disclosure, raw-event access, and small-cell inference tests deny.
- Chaos tests stop analytics workers/Redis/metrics backend/AI/one core component and verify honest state/freshness plus recovery.

## Observability, migration, and rollout

### Observability

- Pipeline metrics: event rate, dedupe count, lag/oldest age, mapper failure by bounded code/version, aggregate latency, rebuild progress/error, reconciliation mismatch, query latency/error, suppression, and cache hit.
- Health metrics: collector duration/failure, snapshot age, component state, probe timeout, state transitions/flapping, and runbook acknowledgement where supported.
- Alerts have owner/sustain/runbook for backlog, stale dashboard, mapper incompatibility, reconciliation mismatch, collector outage, component degradation, repeated admin denial, or query saturation.
- Telemetry contains safe IDs/metric keys/component keys only; never metric result payloads when sensitive inference is possible.

### Migration and rollout

1. Register the allowed metric catalog/data classification and deploy empty aggregate/watermark/health schemas.
2. Start consumers in shadow mode with synthetic/staging data; reconcile against approved source queries.
3. Backfill bounded date ranges into shadow aggregates, validate, then enable read-only admin endpoints behind `admin_analytics` and `admin_system_health` flags.
4. Release internal operations/admin cohort first; monitor lag, query load, suppression, and disclosure tests.
5. Add metrics one at a time through reviewed registry/mapping/version changes. Never silently repurpose a metric key.
6. Rollback disables endpoints/consumers while source events remain replayable. Do not delete authoritative source or force operational workflows from aggregates.

## Acceptance and exit gate

- Every permitted §145 metric has a documented definition, event/query source, dimensions, correction semantics, privacy classification, freshness SLO, reconciliation query, and owner.
- Aggregate replay is idempotent and rebuilt totals reconcile with source controls under duplicate, late, correction, and worker-crash tests.
- Admin endpoints/UI contain zero clinical content, raw AI/search text, secrets/topology, or pharmacy stock availability; direct URL/query/export attempts cannot bypass that boundary.
- Health snapshots honestly distinguish core, AI, queue, storage, realtime, database, and backup states with captured/fresh times and safe runbooks.
- AI failure does not report core unavailable; stale/missing telemetry never reports healthy.
- Dashboard/query/rebuild/collector load remains inside Phase 21 DB/API budgets, with bounded filters and graceful degradation.
- Security/privacy, Arabic/English/accessibility, unit/integration/contract/E2E/system/load/chaos evidence, dashboards/alerts/runbooks, migration/rebuild/rollback, and admin approval are complete.
- Analytics remains derived and rebuildable, and no V1-excluded dashboard/control/export capability is enabled.
