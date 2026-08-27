# Phase 21 — Performance, Scaling, Observability, and Resilience

## Objective

Prove with repeatable measurements that the assembled platform meets its V1 latency, throughput, realtime, concurrency, and availability targets; degrades safely when optional or temporary infrastructure fails; and exposes enough redacted telemetry to detect, diagnose, scale, and recover it.

This phase converts sizing hypotheses into evidence. Adding Laravel, Redis, Octane, PgBouncer, Reverb, Qdrant, or more machines does not itself satisfy a target. Tests must identify bottlenecks, data-integrity behavior, backpressure, recovery, and cost at each load level. Core service remains independent from AI/Qdrant and authoritative data remains independent from Redis/analytics.

## Plan traceability

- Section 3, lines 114-152: Octane/FrankenPHP, PostgreSQL/PostGIS, Redis/Horizon/Reverb, FastAPI/Qdrant, S3, telemetry, and load-balancer stack.
- Sections 22-23 and 26-29, lines 861-914 and 960-1077: availability/booking concurrency, realtime queue/delay, and local clinical resilience.
- Sections 65-70, lines 2067-2213: idempotent integration, freshness, geo/prescription coverage search, and PostgreSQL text search.
- Sections 94 and 99-104, lines 2765-2798 and 2879-3028: AI isolation, private realtime, queues/Horizon, and transactional outbox.
- Sections 107 and 111-115, lines 3081-3108 and 3229-3321: idempotency, indexing, conditional partitioning, Redis separation, and cache strategy.
- Sections 124-125 and 131-143, lines 3497-3535 and 3622-3915: Qdrant isolation/scaling/rebuild, exact SLO/capacity/scale/topology, 99.9% core availability, health, and monitoring.
- Sections 152-155, lines 4085-4181: safe retries, local outbox, explicit pharmacy online requirement, and transparent doctor transient-offline state.
- Sections 160-162, lines 4268-4330: k6 scenarios, load acceptance, and stepped stress test.
- Sections 165-168, lines 4367-4464: isolated environments, containers, CI/deployment, and safe migrations.
- Sections 172-176, lines 4522-4717: source ownership, consistency, asynchronous work, sequence, and production definition of done.

## Entry criteria and dependencies

- Phases 00-20 have stable contracts, representative feature implementations, idempotency/outbox behavior, production-like staging, synthetic seed generators, and baseline dashboards.
- Critical queries have trace/query plans and functional/concurrency tests before load optimization begins.
- Production-like infrastructure can be scaled and isolated without using raw production medical data.
- Product/operations define the numeric acceptable unexpected-error thresholds and business traffic mix; this document sets the minimum engineering thresholds below.
- Cost owners approve a bounded load-test budget and explicit target environment. Tests against production or third-party systems require separate authorization.

## Non-goals

- No claim that benchmark hardware is a permanent production requirement or guarantee.
- No day-one sharding, broad table partitioning, Kubernetes requirement, Elasticsearch/OpenSearch, or microservice split.
- No full offline pharmacy ERP or conflict resolution; central POS/stock remains online-only.
- No weakening of consistency, authorization, audit, idempotency, encryption, validation, or clinical correctness to improve latency.
- No caching of PHI without a reviewed data-classification exception.
- No destructive stress/chaos testing against production, real patients, real SMS/push recipients, real payments, or uncontrolled provider spend.
- No treating a successful average latency as evidence; p95, p99, error, saturation, and recovery are required.

## SLO and capacity contract

### V1 latency targets

Measure server-side and user-observed latency separately. The plan's acceptance targets are:

| Operation | p95 target |
| --- | ---: |
| Normal API read | ≤250 ms |
| Normal API write | ≤400 ms |
| Doctor/patient profile | ≤250 ms |
| Appointment availability | ≤300 ms |
| Medication text search | ≤300 ms |
| Medication plus pharmacy geo search | ≤500 ms |
| Prescription read | ≤300 ms |
| Queue realtime event commit-to-client | ≤1 second |
| Start consultation | ≤300 ms |
| POS sale | ≤400 ms |
| RAG retrieval | ≤700 ms |
| AI first token | target ≤2-3 seconds |

Each test specification pins dataset size, traffic mix, cache state, client region/network profile, payload size, test duration, dependency/provider configuration, build/image, schema version, and hardware. Expected `409/422/429` responses are reported separately and never counted as successful business operations.

### V1 capacity targets

```text
registered users                   250,000+
simultaneously connected clients   10,000
active concurrent users            approximately 2,000
sustained API traffic               500 RPS
burst API traffic                   1,000-1,500 RPS
WebSocket connection headroom      20,000
concurrent AI generations           50-100 initially
core monthly availability          99.9%
```

Minimum steady-state load gate: at 500 RPS and the approved traffic mix, unexpected `5xx`/transport failures are ≤0.1%, every p95 target passes, no critical mutation violates an invariant, and backlog/resource use reaches a stable plateau. At approved burst, the system may shed optional/AI work or return controlled `429/503`, but must not corrupt data, amplify retries, or exhaust core dependencies.

### Future scale direction

The architecture must permit, without a fundamental rewrite, 1M+ registered users, 50k connected clients, and thousands of RPS through stateless horizontal scaling and measured database evolution. It is not a V1 acceptance load unless separately funded and scheduled.

## Laravel module ownership and services

### Runtime topology and ownership

```text
managed/redundant load balancer
  |-- stateless Laravel Octane/FrankenPHP API pool
  |-- Reverb WebSocket pool -> cache/realtime Redis
  |-- internal FastAPI AI pool -> bounded inference workers -> Qdrant/provider

Laravel/API and queue workers
  -> PgBouncer -> PostgreSQL primary/standby/read replica as approved
  -> queue Redis HA
  -> private managed S3

all workloads -> Prometheus /metrics scrape; local Core request inspection via Telescope; Sentry for errors
```

Module services own latency budgets, retry eligibility, cache semantics, and degradation behavior. Focused integrations own pools, timeouts, circuit breakers, telemetry, and provider specifics; use small interfaces only where a provider is genuinely replaceable.

```text
Clock / Deadline / CancellationToken
CacheService
DistributedLockService
QueuePublisher / OutboxConsumer
RealtimePublisher
DatabaseConnectionHealth
ObjectStorageHealth
AiServiceHealth
MetricsRecorder / TraceRecorder
```

- **Single responsibility:** business services do not contain vendor metrics/scaling logic; integrations do not decide business consistency.
- **Open/closed:** additional API/Reverb/worker nodes or provider integrations require configuration, not business-service rewrites.
- **Liskov substitution:** cache miss/outage, replica, object-store, and provider adapters honor the same correctness/error contract.
- **Interface segregation:** optional AI/analytics/notification health never becomes a required core readiness dependency.
- **Conventional services:** owning module services use deterministic clocks/deadlines and focused provider interfaces where substitution matters; avoid hidden framework globals in business workflows.

### Initial production benchmark topology

Treat this as the plan's starting benchmark, then right-size from evidence:

```text
Load balancer             managed / redundant
Laravel API               3 x 8 vCPU / 16 GB RAM
Reverb                    2 x 4 vCPU / 8 GB RAM
Queue workers             2-3 x 4-8 vCPU / 8-16 GB RAM
PostgreSQL primary        16 vCPU / 64 GB / fast NVMe
PostgreSQL standby        same class
Read replica              8-16 vCPU / 32 GB
Redis cache/realtime      HA pair
Redis queue               separate HA pair
Qdrant                    3 x 8 vCPU / 32 GB / NVMe, RF >= 2
AI API                    2 x 4 vCPU / 8 GB
Embedding/reranking       separate GPU workers sized from measured load
S3                        managed private object storage
```

For a small launch cohort, use smaller resources while preserving deployment boundaries, configuration, backup, observability, and scaling paths.

## Packages, infrastructure, and test tooling

- Laravel Octane with FrankenPHP, Horizon, Reverb, Sanctum, PostgreSQL/PostGIS, and Redis clients already selected and locked.
- PgBouncer in transaction/session mode only after compatibility tests for migrations, prepared statements, advisory locks, LISTEN/NOTIFY, and connection state.
- Prometheus, Grafana, Loki-compatible logging, Laravel Telescope (local), and Sentry with central redaction.
- k6 for HTTP/WebSocket workload models, thresholds, scenarios, and machine-readable results.
- PostgreSQL `pg_stat_statements`, `EXPLAIN (ANALYZE, BUFFERS)` in safe staging, slow-query telemetry, and connection/pool metrics.
- Qdrant cluster/collection metrics, Redis/Horizon/Reverb metrics, object-store/provider metrics, and container/runtime resource metrics.
- Optional controlled network/CPU/dependency fault tooling in owned staging; no production chaos without separate approval.
- Flutter mobile performance tooling for patient startup/frame/memory/reconnect, Electron process/IPC/render performance tooling for doctor/pharmacy startup and main/preload/renderer/utility behavior, and browser React tooling for admin user-perceived flows where server tests cannot prove behavior.

## Data structures, indexes, cache, and capacity invariants

### Evidence artifact schema

Load evidence is immutable CI/object-store output, not operational truth:

```text
performance_test_run.json
  run_id / started_at / duration
  git_commit / image_digests / migration_version
  environment_fingerprint / topology / dataset_version
  scenario_mix / arrival_profile / thresholds
  dependency_versions / provider_mode
  p50 / p95 / p99 / max by operation
  success / expected_conflict / rate_limited / unexpected_error counts
  RPS / connections / messages / bytes
  DB/Redis/queue/Reverb/Qdrant/AI resource snapshots
  invariant_reconciliation_result
  recovery_time / breaking_point nullable
  result PASS | FAIL | CHARACTERIZATION
```

Run IDs and artifact hashes are referenced by release evidence. Results contain synthetic IDs only.

### Required index/query review

- Appointments: `(doctor_id, appointment_date, status)` and `(patient_id, created_at)` plus the database constraint/exclusion strategy that prevents overlapping bookable ranges.
- Stock: `(branch_id, medication_id)` and `(branch_id, expiry_date)`; ledger/balance reconciliation queries must remain index-supported.
- Medical record: `(patient_id, created_at)` on authorized projections.
- Availability: `(doctor_id, location_id, day)` plus exceptions/appointments range access.
- Audit: `(actor_id, created_at)` and `(entity_type, entity_id, created_at)`.
- PostGIS GiST indexes on clinic/pharmacy geography, with `ST_DWithin` radius filtering before distance sort.
- Medication search uses normalized columns, GIN/`pg_trgm`, and bounded ranked results.

Every performance change must preserve the query's security predicate. An index or read replica must not bypass row/tenant/encounter authorization or return data outside allowed consistency/freshness.

### Cache contract

Allowlisted candidates are medication catalog, specialties, doctor directory summaries, short-TTL availability, system settings, and public pharmacy summaries. Each entry documents owner, exact key/tenant scope, classification, TTL, maximum size, invalidation event, stampede protection, stale behavior, and empty-Redis behavior.

No cache is authoritative. Cache-aside reads revalidate security-sensitive state where needed. Booking, access, record, prescription, payment, invoice, stock, and refund writes always use PostgreSQL constraints/transactions even if a cache or distributed lock is present.

### Scaling order

1. Capture a reproducible trace/query plan and verify correctness.
2. Add/fix indexes and eliminate N+1/overfetching.
3. Tune queries/payloads/serialization and bounded pagination.
4. Configure PgBouncer and application pools with reserved operational capacity.
5. Add reviewed caching and request coalescing.
6. Add stateless API/worker/Reverb nodes.
7. Add PostgreSQL standby/read replica for explicitly safe/fresh-enough reads.
8. Partition only measured high-volume candidates such as audit events, deliveries, AI usage, stock movements, or appointment events.
9. Consider sharding only after prior measures fail and an ADR proves ownership/routing/migration/recovery.

## Detailed runtime and failure flows

### 1. Normal API request under load

1. Load balancer applies TLS, body/header/connection limits and distributes to a healthy ready Laravel node.
2. Octane resets request-scoped identity/locale/context; regression tests detect worker-state leakage.
3. Middleware sets deadline/correlation/authentication/rate policy; handlers validate and authorize before expensive work.
4. Read uses bounded indexed query or declared cache path. Write uses idempotency, transaction, constraints, audit, and outbox.
5. Response returns before notifications, embeddings, analytics, files, or external integration work.
6. Metrics/traces record safe operation/status/latency/saturation without unbounded or PHI labels.

### 2. Cache and Redis failure

- Cache/realtime Redis unavailable: circuit opens quickly, caches miss, public/directory traffic may be rate-shed, Reverb clients reconnect/resync, and PostgreSQL is protected by concurrency/rate caps.
- Queue Redis unavailable: committed outbox rows remain in PostgreSQL. Dispatch pauses/degrades visibly and resumes/replays idempotently after recovery.
- Redis flush/restart: cache warmup is bounded and staggered; no medical/business truth is lost.
- Realtime messages are hints, not truth. Clients fetch authoritative queue/chat/notification state after reconnect.
- Separate cache/realtime and queue HA pairs prevent queue pressure from evicting realtime/cache state.

### 3. Database overload/failure

1. PgBouncer/app pools cap connections and reserve capacity for health/migrations/operations.
2. Statement/transaction deadlines cancel expensive work; clients receive safe retry semantics based on idempotency.
3. Optional analytics/AI/background queues are paused or shed before critical booking/clinical/POS traffic.
4. Read replica is used only for approved eventual/freshness-tolerant queries with lag checks; access/booking/medical/prescription/invoice/stock/refund remain primary/strong.
5. Primary failover follows Phase 23 runbook. Ambiguous writes are recovered through idempotency status and reconciliation, never blind client retry.

### 4. Realtime scale and reconnect

1. Client obtains authorization for a private channel from Laravel; channel name is not permission.
2. Reverb nodes share events through Redis Pub/Sub behind the load balancer.
3. Event includes sequence/version and minimal payload. Client detects gap/reconnect and fetches current server state.
4. Backpressure bounds per-connection buffers; slow clients are disconnected with a retry/resync instruction rather than consuming unbounded memory.
5. Queue commit-to-client latency is measured from authoritative commit/outbox occurrence to receipt for connected clients.

### 5. AI saturation/failure isolation

1. Core authorizes/minimizes an AI request and applies per-actor/global budget before calling FastAPI.
2. AI API uses a bounded queue/semaphore and separate inference worker/GPU pools.
3. At saturation it returns controlled capacity errors or load-sheds; it never consumes the core DB/queue pools without bounds.
4. Qdrant/provider/GPU failure degrades AI health only. Core request routes and readiness stay healthy.
5. AI traces/usage record failure safely; no automatic provider fallback with different safety/quality semantics occurs without a versioned flag/evaluation.

### 6. Client offline/transient network behavior

- Doctor Electron desktop: encrypted clinical draft autosaves to main-owned and authorized SQLCipher-backed SQLite outside the renderer, preferring utility-process execution where the target-OS/ABI spike supports it; the local outbox uses stable operation ID/idempotency key, capped retries, clear pending/failed/ack state, and no claim that queue/final record updated while offline.
- Pharmacy Electron desktop: an approved main-owned encrypted catalog/UI cache may operate read-only, with optional utility-process execution after the OS/ABI spike, but POS and stock mutation require online authoritative confirmation. No renderer database access and no offline sale conflict logic in V1.
- Patient app: safe read cache may display freshness; booking/payment/clinical mutations never appear successful before server confirmation.
- Retry only idempotent reads and mutations with the same key; `401/422/409/429` are not generic retry candidates.

### 7. Outbox/worker overload

1. Critical transaction commits authoritative state plus outbox row.
2. Dispatcher/consumers use `FOR UPDATE SKIP LOCKED`/leases, stable event IDs, bounded concurrency, and idempotent effects.
3. Queue classes have reserved worker capacity: critical before notifications/files/AI/integrations/analytics/reports/backups.
4. Autoscaling reacts to oldest-job age and service time, not queue count alone.
5. Retry uses classified transient errors, capped exponential backoff/jitter, dead-letter state, and operator repair; poison jobs cannot loop forever.

### 8. Scale-out and rolling deployment

1. Add node with immutable image/config, run liveness then readiness warmup, and admit traffic only when compatible.
2. Stop new traffic/jobs, drain bounded active work, propagate cancellation, flush telemetry, then terminate.
3. Mixed old/new versions operate under backward-compatible API/event/schema contracts.
4. WebSocket deployments drain/reconnect clients with jitter and server-state resync.
5. Rollback uses the same image/migration compatibility; irreversible data contraction waits for later release.

## API, health, telemetry, and load contracts

### Health endpoints

Every deployment unit exposes:

```text
GET /live   # process/event loop alive; no deep dependency fan-out
GET /ready  # can safely accept its declared workload now
```

Public/gateway health returns only state/build-safe metadata. Detailed dependency health is authenticated/internal and projected safely by Phase 20. Core readiness includes critical DB/config/migration compatibility; it does not require AI/Qdrant. AI readiness may require its configuration/capacity/retrieval dependencies according to the AI route contract.

### Telemetry contract

- Common fields: service, environment, version, instance, operation, safe status/error class, request/correlation/trace IDs, duration, and bounded dependency name.
- Never label metrics with user/patient/doctor/pharmacy/appointment/prescription/file/conversation IDs, URLs containing IDs, search text, diagnosis, clinical content, object key, token, or prompt/response.
- Structured logs exclude national ID, phone, medical history, prescription/lab text, passwords, tokens, and provider payloads.
- Sampling must retain errors/security/audit correlation without retaining sensitive bodies.

### k6 scenarios

Maintain separate composable scenarios for login/session refresh, doctor search, availability, atomic booking races, queue WebSockets, profile/medical-record read under authorization, prescription read, medication text/geo search, POS sale, integration sync impact, and admin analytics. AI retrieval/generation has a separate cost-bounded scenario and is combined only for realistic mixed-load tests.

Workload models include cold/warm cache, typical/large tenant, slow client, reconnect storm, burst, soak, dependency latency, and background-job backlog. All fixtures are synthetic and route push/SMS/provider calls to controlled sinks.

## Client work

- All clients display honest offline/degraded/pending/retry states and never infer success from a timeout.
- Flutter Dio transport and TypeScript Electron/admin transports map `401`, `403/404`, `409`, `422`, `429`, safe `5xx`, timeout, and cancellation distinctly; retries obey method/idempotency/error classification and server `Retry-After`. Electron transport executes in main/utility and exposes only typed DTO operations/events through preload.
- Realtime clients resubscribe with jitter, detect sequence gaps, and reload authoritative state.
- Paginate/virtualize large lists, cancel superseded searches, debounce within product requirements, and cap parallel requests.
- Track startup, frame/render, memory, network bytes, reconnect, and user-flow latency without collecting sensitive screen/input content.
- Performance optimizations preserve Arabic/English, accessibility, exact money/quantity/time, and security behavior.

## Security and privacy controls

- Load/chaos targets are explicitly authorized owned staging resources with synthetic data, controlled egress, provider spending caps, kill switch, duration/concurrency/rate limits, and cleanup.
- Metrics, health, debug, profiler, PgBouncer, Redis, Qdrant, DB, Horizon, Grafana, Loki, Sentry, and collector endpoints remain private and authenticated.
- Protect against denial of service/wallet with request/body/output limits, rate and concurrency budgets, bounded queries/pagination, circuit breaking, load shedding, and cost alerts.
- Autoscaling does not bypass tenant limits or create unbounded provider/database connections; global pool budgets are calculated from downstream capacity.
- Never disable TLS, authorization, encryption, audit, malware scan, output validation, or consistency checks in performance tests. A separate explicitly labeled diagnostic run may isolate cost only after correctness evidence.
- Test-data generators cannot produce/send plausible real national IDs/phones to real SMS/FCM/provider destinations.
- Profiling/traces/query samples use synthetic data and central redaction. Production profiling requires separate time-bounded approval.

## Test and verification plan

### Unit tests

- Deadline propagation, retry classifier/backoff/jitter, circuit/load-shed state, pool/budget calculation, cache key/TTL/invalidation, readiness aggregation, and safe error mapping.
- Realtime sequence/gap/reconnect state, local outbox states, worker lease/retry/dead-letter, and Octane request-context reset.
- Metric/log redaction, bounded label registry, and test-result threshold evaluation.

### Integration tests

- Real PostgreSQL/Redis/PgBouncer verifies pool limits, transaction behavior, prepared statement/advisory-lock compatibility, cache loss, outbox replay, worker death, and replica-lag routing.
- Reverb multi-node Redis Pub/Sub/private-channel/reconnect/backpressure behavior.
- Real Electron main/preload/renderer integration measures bounded IPC latency/backpressure, stream cleanup, reconnect resync, SQLCipher/native-addon behavior, and main/utility failure recovery on every supported packaged OS/architecture.
- Qdrant multi-node/RF behavior and AI service concurrency/load shedding without core pool starvation.
- S3 slow/error/timeouts, provider timeouts/rate limits, and metrics scrape failure/backpressure.
- Index/query-plan assertions use representative dataset cardinality and fail on major scanned-row/query-count regression.

### Contract tests

- `/live`/`/ready`, safe detailed health, retry/error/idempotency, cache freshness, and realtime event sequence contracts.
- Old/new API/event/client versions across rolling deployment.
- Electron preload capability and IPC schemas remain bounded and compatible across rolling server/client versions; generic IPC, renderer-supplied authorization scope, and privileged URL/path/SQL fields are rejected.
- Every infrastructure adapter exposes typed timeout/unavailable/rate-limit/cancel states and honors deadlines.
- k6 scenario metadata/result schema and threshold evaluator are versioned and validated.

### End-to-end tests

- Critical journeys at load: login, search/book, check-in/queue/start/end consultation, medical record, prescription, lab/file metadata, medicine search, POS/refund, and normal notification/chat recovery.
- Doctor transient outage preserves draft and later syncs once; pharmacy cannot complete offline sale; patient booking timeout resolves to one known outcome.
- AI/Qdrant down while doctor/patient/pharmacy/admin core journeys remain usable.
- Rolling API/Reverb/worker deployment preserves compatible clients and resynchronizes connections.
- Packaged Electron doctor/pharmacy builds preserve renderer responsiveness during reconnect/backlog, restore drafts without duplicate mutation, and cannot turn renderer compromise or reload into Node/native capability access.

### System, load, soak, stress, and chaos tests

1. Functional baseline and reconciliation at low load.
2. Ramp to and hold 500 RPS with approximately 2,000 active/10,000 connected users long enough to reach steady state.
3. Test 20,000 WebSocket connection headroom and event/reconnect scenarios.
4. Burst 1,000 then 1,500 RPS and verify controlled load shedding/backpressure.
5. Step stress: 500, 750, 1,000, 1,500, then 2,000 RPS. Record breaking point, bottleneck, correctness, and degradation.
6. AI-only and mixed tests at 50 then 100 concurrent generations with provider-cost guardrails.
7. Soak long enough to expose connection/memory leaks, queue drift, cache growth, token/cost drift, and Octane state leaks.
8. Inject owned-staging worker/node/Redis/Qdrant/provider/network failures and reconnection storms.

After stress removal, critical endpoints must return to their p95/error thresholds and backlogs to pre-test steady range within 15 minutes, without manual data repair. If actual architecture needs a different recovery objective, an approved ADR/SLO revision is required before calling the run a pass.

### Security tests

- Authorization/tenant tests are run under load to catch cache/context/Octane leakage.
- Rate-limit bypass, connection exhaustion, oversized bodies, slow clients, pagination amplification, expensive geo/search patterns, websocket subscription abuse, retry storm, queue poison, high-cardinality telemetry, and AI denial-of-wallet.
- Public reachability checks deny internal metrics/health detail/admin consoles/data stores.
- Canary PHI/credentials never appear in logs/traces/profiles/test artifacts.
- Fault injection cannot skip audit/outbox/authorization or turn a timed-out mutation into duplicate state.

## Observability dashboards and alerts

Grafana must expose, by bounded operation/service/version:

- API RPS, p50/p95/p99, `4xx` classes, `5xx`, timeout/cancel, in-flight, saturation, and load-shed counts.
- DB pool/PgBouncer clients/servers/waits, connections, transaction/query latency, slow/scanned rows, locks/deadlocks, replica lag, WAL/storage.
- Redis memory/eviction/latency/connections/errors; separate cache/realtime and queue views.
- Queue/outbox throughput, oldest age, depth, runtime, retries, failed/dead-letter, worker saturation.
- Reverb connections, subscriptions, messages, commit-to-client latency, disconnects, buffer drops, reconnects.
- Qdrant retrieval latency/error, collection/vector size, shards/replicas health; AI queue/concurrency/first-token/total/token/cost/provider errors.
- S3 upload/download/signing/error/scan backlog; FCM/SMS delivery failure; integration freshness/sync error.
- Client version adoption, safe crash/error, offline/sync pending age, and contract deprecation.

Every alert has severity, numeric threshold/sustain, owner, escalation, runbook, expected action, and false-positive review. Alert on symptoms and error-budget burn, not every transient event.

## Migration, rollout, and capacity process

1. Establish baseline with production code/config and representative synthetic dataset before optimization.
2. Fix measured application/query/index/payload problems; record before/after evidence and correctness regression.
3. Introduce PgBouncer/cache/replicas/partition only through compatibility, migration, failure, and rollback tests.
4. Run component then mixed load; calculate per-node throughput and downstream connection/resource budgets.
5. Validate the plan topology, then right-size with at least operational headroom and documented scale triggers.
6. Deploy autoscaling/scale runbooks and rehearse add/drain/replace under traffic.
7. Store signed test artifacts, dashboards, query plans, reconciliation, topology, cost, bottleneck, and release verdict.
8. Repeat material scenarios on changes to query/schema/index, runtime, provider/model, cache, queue/realtime, topology, or traffic model.

## Acceptance and exit gate

- Every listed p95 target passes under the documented steady-state mixed workload; queue commit-to-client is ≤1 second and AI RAG/first-token targets pass under the separately approved profile.
- 500 RPS sustained, approximately 2,000 active/10,000 connected clients, 20,000 WebSocket headroom, 1,000-1,500 burst, and 50-100 AI concurrency are tested with machine-readable evidence.
- At steady 500 RPS, unexpected failures are ≤0.1%, resource/backlog trends stabilize, and zero authorization, booking, clinical, prescription, stock, invoice, refund, or idempotency invariant is violated.
- Stress steps through 2,000 RPS characterize a breaking point; degradation is controlled and the system recovers to SLO/backlog range within 15 minutes without data repair.
- Redis/cache/realtime/queue worker, AI/Qdrant/provider, node, and network failures recover or degrade according to contract; core remains usable when AI is down and authoritative truth survives Redis loss.
- PgBouncer/pools/indexes/cache/replicas/partition choices have measured evidence, compatibility tests, connection budgets, and rollback; no premature sharding is introduced.
- Health/readiness, dashboards, alerts, redaction, runbooks, rolling deployment, client reconnect/offline behavior, and all test layers pass in production-like staging.
- The 99.9% core availability/error-budget model, capacity triggers, topology/cost estimate, and release performance evidence are approved by engineering, operations, security, and product.
