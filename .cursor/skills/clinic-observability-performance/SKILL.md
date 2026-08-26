---
name: clinic-observability-performance
description: Instrument this clinic platform and execute or analyze k6 performance tests, OpenTelemetry, Prometheus/Grafana, Laravel Telescope, health/readiness, query measurements, and capacity evidence. Use for SLOs, scaling, saturation, runtime tuning, and safe degradation; PostgreSQL index, query, schema, and migration changes belong to clinic-postgresql-consistency. Not for backup/failover execution or release control.
---

# Clinic Observability and Performance

Turn performance and resilience claims into reproducible evidence without weakening authorization, consistency, privacy, audit, or clinical/pharmacy correctness.

## Read the required sources

Read completely:

- [Roadmap, invariants, evidence policy, and open topology decision](../../../docs/phases/README.md)
- [Cross-cutting observability, health, outbox, API, cache, and environment contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- [Safe admin analytics and health projection](../../../docs/phases/20_admin_analytics_and_system_health.md)
- [Performance, scaling, observability, and resilience](../../../docs/phases/21_performance_scaling_observability_and_resilience.md)
- [Security/privacy observability constraints](../../../docs/phases/22_security_privacy_and_compliance_validation.md)
- The metrics and release-consumption sections of [Phase 23](../../../docs/phases/23_disaster_recovery_release_and_production.md)

Also read the owning feature phase's observability, failure, concurrency, indexes, and acceptance sections. Inspect current instrumentation abstractions, dashboards/alerts, collector config, k6 scenarios, query plans/indexes, deployment topology, test-data generator, runbooks, and prior baselines.

## Ownership

Own measurement and performance engineering:

- OpenTelemetry context propagation and approved HTTP/DB/Redis/queue/Reverb/S3/Qdrant/AI/provider/client instrumentation, including Inertia/browser patient, doctor, pharmacy, and admin boundaries;
- **Laravel Telescope** for local/non-production request, query, job, and exception debugging; do not treat Telescope as the production SLO console;
- bounded metric/log/trace/error schemas, central redaction, sampling, dashboards, alerts, SLI/SLO calculations, and health/readiness behavior;
- k6 HTTP/WebSocket workload models, synthetic datasets, threshold evaluation, load/soak/stress/reconnect/fault experiments, and immutable result artifacts;
- query/index/payload/cache/pool/backpressure analysis, capacity models, scale triggers, and before/after evidence;
- system failure-isolation and recovery-time characterization that does not perform a disaster restore.

Feature owners still implement business logic and local instrumentation calls. This skill defines/reviews shared signals and proves system behavior.

## Boundaries

- Do not execute backup restore, primary promotion/failover, DNS production cutover, deployment promotion, secret rotation, or destructive cleanup. Hand those actions to `clinic-production-dr-release`.
- Engineering telemetry is not the admin dashboard. Phase 20 receives a reviewed safe projection and never browser credentials, PromQL/Loki expressions, raw traces/logs, topology, Telescope, or an operations control plane.
- Phase 20's optional backup status remains `UNKNOWN / not configured` until Phase 23 supplies the real projection. This skill cannot infer backup or restore health from missing telemetry.
- Redis, caches, metrics, and analytics are not sources of truth. Never improve latency by moving clinical/financial authority out of PostgreSQL/S3.
- Do not cache PHI without an approved classification exception, and never put patient/doctor/pharmacy/appointment/file/prescription/conversation IDs or free text into metric labels.
- Do not disable TLS, authorization, validation, encryption, audit, malware scanning, idempotency, consistency, AI safety, or redaction for a passing benchmark.
- Load/fault work targets explicitly authorized owned staging with synthetic data, controlled notifications/egress/provider cost, kill switch, and bounded rate/concurrency/duration. Production testing requires separate approval.
- Do not introduce sharding, broad partitioning, Kubernetes, Elasticsearch/OpenSearch, or microservice extraction without measured need and an approved ADR.

## Measurement contract

- Use the exact p95, capacity, WebSocket, AI concurrency, burst, stress, recovery, and 99.9% Core targets in Phase 21. Do not paraphrase them into weaker averages.
- Pin build/image, schema/config/flag/model versions, topology, dataset/cardinality, cache state, traffic mix, client/network profile, dependency mode, test duration, and threshold definition in every run.
- Report p50/p95/p99/max, throughput, expected conflicts/validation/rate limits, unexpected errors, saturation, queue/outbox age, pool waits, resource use, cost, correctness reconciliation, breaking point, and recovery.
- Expected `409/422/429` outcomes are not successful business operations and are not unexpected `5xx`; report them separately.
- One aggregate cannot hide a slow/unsafe critical operation or cohort. Slice by bounded operation/service/version and approved workload dimensions.
- Every optimization retains the authorization/tenant/context predicates in the query/adapter path and has correctness regression evidence.

## Health and telemetry rules

- `/live` proves the process/event loop is alive without deep fan-out. `/ready` proves the workload can safely accept traffic under its declared critical dependencies/config/schema/capacity.
- Core readiness does not require AI/Qdrant. AI readiness/degradation is reported separately.
- Realtime delivery is measured commit/outbox-to-authorized-client; reconnect tests reload authoritative state and detect event gaps.
- Inertia/browser client measurements distinguish navigation, first paint, form submit, reconnect, and user-flow time without recording screen/input content. Do not add Electron main/renderer IPC telemetry unless a native desktop client is explicitly required.
- Logs/traces/Sentry exclude national ID, phone, medical history, prescriptions, lab/file content, prompts/responses/chunks, tokens, credentials, object keys, and raw provider payloads.
- Labels use a compile-time/reviewed bounded registry. Correlation/request/trace IDs may be searchable fields where policy permits, not high-cardinality metric labels.
- Alerts name numeric threshold/sustain, owner, severity, escalation, runbook, expected action, and false-positive review. Prefer user-impact/error-budget symptoms over noisy single events.

## Workflow

1. Define the user/system question, owning SLO/invariant, baseline, workload/failure model, authorization, safety limits, and pass/fail criteria before instrumentation or load.
2. Inspect existing traces, metrics, query plans, indexes, pools, queues, caches, and topology. Confirm missing evidence rather than guessing a bottleneck.
3. Add the smallest standardized redacted instrumentation needed across boundary adapters; verify label cardinality and telemetry failure/backpressure behavior.
4. Create a functional low-load scenario and domain reconciliation first, then steady, connection, burst, AI, soak, stress, reconnect, and fault variants as applicable.
5. Run in controlled staging, analyze per-layer latency/saturation/error/backlog/cost, identify the first bottleneck, and preserve immutable evidence.
6. Optimize in the Phase 21 order: correctness/query/index/N+1/payload, pools/PgBouncer, reviewed caching/coalescing, horizontal scale, safe replicas, measured partitioning, then only consider sharding.
7. Rerun focused and mixed tests. Reject an optimization that regresses correctness, security, privacy, failure behavior, or another critical SLO.
8. Update dashboards, alerts, capacity/scale triggers, runbooks, and the release evidence consumed by Phase 23.

## Verification

Verify at minimum:

- instrumentation unit tests for timing, status/error classification, context propagation, redaction, bounded labels, and telemetry exporter failure;
- integration tests for DB/PgBouncer pools, Redis separation/loss, outbox/worker replay, Reverb multi-node/backpressure/reconnect, S3/provider timeouts, Qdrant/AI saturation, and collector backpressure;
- query-plan/index/cardinality assertions on representative data while retaining security predicates;
- contract tests for `/live`/`/ready`, safe detailed health, realtime sequence, cache freshness, adapter timeouts, and versioned k6 result schema;
- E2E critical journeys under load, including denied cases and ambiguous idempotent writes;
- all Phase 21 sustained/burst/WebSocket/AI/stress/recovery thresholds with exact candidate/topology artifacts and zero domain invariant violation;
- soak checks for server and browser memory, connection, file-descriptor/handle, cache, and queue drift plus Octane cross-request state leakage;
- load-abuse tests for body/pagination/search/geo/WebSocket/retry/queue/telemetry/AI cost amplification without uncontrolled denial of service;
- canary sensitive values absent from metrics/logs/traces/errors/profiles/results and internal observability endpoints inaccessible publicly;
- failure of AI/Qdrant/Redis/worker/node/network produces the documented degradation and authoritative truth remains intact.

Deliver measurement and recovery-characterization evidence. Production/DR decides deployment, restore, failover, and operational acceptance; security assurance independently verifies abuse/privacy controls.
