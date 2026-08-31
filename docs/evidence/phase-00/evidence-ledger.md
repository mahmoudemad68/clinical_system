# Phase 00 — Evidence ledger

One row per mandatory gate. A gate is `PASS` only when a named artifact or a
reproducible command produced the stated result. Everything else is `PARTIAL`,
`BLOCKED`, or `OPEN`, with the owner and the next action named.

**Never in this ledger:** credentials, national IDs, medical content, raw
prompts, object keys, or exploit payloads.

- **Environment:** local Docker Compose, single host, 4 vCPU / 7 GB
- **Recorded:** 2026-08-24; gap-fill 2026-08-26; first-party Inertia/Pest/Telescope/Firebase 2026-08-26; owners named 2026-08-26
- **Toolchain:** PHP 8.3.6 · Composer 2.8.12 · Laravel 13.26.1 · Node 22.23.2 ·
  npm 10.9.8 · Python 3.12.3 · Flutter 3.47.1 · Dart 3.13.1 · Electron 44.0.0 ·
  Electron Forge 7.11.2 · Docker 29.7.2 · PostgreSQL 16 + PostGIS 3.4 · Redis 7
- **Status:** Phase 00 is **OPEN**.

**Gate totals — 47 gates: 31 PASS, 15 PARTIAL, 0 BLOCKED, 1 OPEN.**

Reproduce with `node scripts/evidence/count-gates.mjs`. The script matches only
rows whose first cell is a gate id, and fails on a duplicate id or a mismatched
`--expect`. It exists because an earlier hand-written summary counted the legend
and the test-plan category table as gates and reported figures that did not
match the table; a total nobody can reproduce is not evidence.

## ADR 0010 supersession notice — 2026-08-25, reconciled after migration

[ADR 0010](../../adr/0010-electron-react-typescript-desktop-clients.md) replaced
the doctor/pharmacy Flutter Desktop runtime with Electron, React, and
TypeScript. The migration has since been performed, so this table is the
reconciled status rather than the original to-do list. Where a row below and a
gate row later in this file disagree, **this table is stale by construction and
the gate row wins** — the gate rows are re-evaluated on every verification run.

| Gate / area | Status after migration | What remains |
| --- | --- | --- |
| G-01-01 / G-01-02 architecture | `PASS` | ADR 0010 accepted, Electron C4 component view present, ADRs 0002/0003 and both C4 diagrams reconciled to the Electron reality. No automated Mermaid render check. |
| G-02-01 runtime bootstrap | `PASS` | **Done.** Both Flutter desktop scaffolds removed, Melos reduced to 12 packages (patient app + 11 Dart packages), two independent Electron Forge apps scaffolded at the same paths, added to npm workspaces with distinct app IDs and data roots. No mixed runtime remains — verified by `find` for `*.dart`/`pubspec.yaml` under either desktop. |
| G-02-06 … G-02-09 desktop boundary | `PASS` / `PARTIAL` | See the dedicated gate rows. Inventory, workspace split, and namespace separation pass. Source/behavioural trust-boundary tests remain under G-02-09. Packaged-window proof is G-02-10. |
| G-02-10 packaged E2E | `PASS` | Ubuntu, Windows, and macOS packaged Doctor+Pharmacy WebdriverIO on SHA `4a98fac6538546b52f6eff0c5ef98a9608714b90`, workflow `33155677159`. Signing/notarization remain Phase 23. |
| G-03-02 generated clients | `PASS` | TypeScript generated into `packages/typescript/api_client`. Dart constants generated into `packages/flutter/api_client/lib/src/generated`. |
| G-06-01 local encryption | `PARTIAL` | Linux Electron (Node + Electron 44 ABI) and Linux Dart sqlite3mc canary/rotation/wrong-key/fail-closed tests pass. Windows, macOS, Android, and iOS were not executed. No clinical local writes. See [g-06-01-local-encryption-spike.md](g-06-01-local-encryption-spike.md). |
| G-06-02 / G-06-04 / G-06-05 | `PARTIAL` | Path filters, workspaces, and lockfiles updated; a `desktop` CI job exists. Forge artifacts are not built for any OS, no SBOM has been produced, and SF-001 remains open at high severity. |
| Client contract / E2E / system rows | packaged Electron `PASS` | Unsigned packaged WebdriverIO journeys are G-02-10 `PASS`. Native integration, signing/update, and rollback tests remain Phase 23. |

---

## 1. Architecture and ownership (Phase 00 §1)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-01-01 | ADRs for modular monolith + separate AI service, repo layout, API-first contracts, outbox, UUIDv7, local encryption, data ownership | architecture | [docs/adr](../../adr/) 0001–0007, plus 0008 package policy and 0009 queue boundary | `PASS` | ADR 0006 carries an open compatibility spike (G-06-01) |
| G-01-02 | C4 context, container, and one component diagram showing dependency direction | architecture | [c4-context](../../architecture/c4-context.md), [c4-container](../../architecture/c4-container.md), [c4-component](../../architecture/c4-component-module-dependency.md) | `PASS` | Diagrams are Mermaid; no rendering check in CI yet |
| G-01-03 | Module catalog: owner, public ports, events, tables, classification, prohibited dependencies | architecture | [module-catalog.md](../../architecture/module-catalog.md), 24 modules | `PASS` | Only `Platform` is implemented; the rest are declarations |
| G-01-04 | CODEOWNERS / review rules for clinical, pharmacy-financial, identity, infrastructure, AI safety | architecture | [.github/CODEOWNERS](../../../.github/CODEOWNERS); [accountable-owners.md](../../governance/accountable-owners.md) | `PARTIAL` | Humans named 2026-08-26: Mahmoud holds clinical, pharmacy, privacy/legal, security, and operations. GitHub `@clinic/...` teams still do not exist, so branch protection cannot enforce the file. Assessor/remediator separation is lost. |
| G-01-05 | Automated architecture check fails on a known forbidden-dependency fixture, then fixture removed | architecture | `deptrac analyse --config-file=deptrac.yaml --fail-on-uncovered --report-uncovered` | `PASS` | Clean run after ISR remediations: 0 violations, 0 uncovered. Fixture (`Domain` class importing Eloquent + `DB` facade) produced exit 1 with 2 violations; removed, exit 0. |

## 2. Runtime bootstrap (Phase 00 §2)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-02-01 | Each deployment unit scaffolded without feature code | all stacks | All six units present and building | `PASS` | core-api (Laravel 13 + first-party Inertia status pages for admin/patient/doctor/pharmacy), ai-service (FastAPI), admin-web (React 19/Vite 8), patient-app (Flutter 3.47.1), doctor-desktop and pharmacy-desktop (Electron 44 + React + TS, per ADR 0010), plus 11 shared Dart and 6 shared TypeScript packages. New first-party UI lives in Laravel/Inertia; existing standalone clients are retained, not deleted. |
| G-02-02 | `/live`, `/ready`, build/version metadata, structured errors, correlation IDs, graceful shutdown | backend, AI | `./vendor/bin/pest --filter HealthEndpointTest`; Inertia `PersonaStatusPageTest` | `PASS` (HTTP) | Feature tests through the real middleware stack: envelope shape, ar/en negotiation, correlation echo, malformed-id replacement, secure headers, and a check that `/ready` leaks no host detail. First-party Inertia pages negotiate ar/en the same way. Graceful shutdown under active load remains untested (Phase 21). |
| G-02-03 | Liveness checks only process health; readiness checks critical startup state without making every optional dependency a core outage | backend | `./vendor/bin/pest --filter HealthEndpointTest` | `PASS` | Asserted at the HTTP layer: `/live` is unenveloped and touches no dependency; `/ready` names its checks and marks `ai_service` non-critical |
| G-02-04 | AI/Qdrant readiness failure does not make Laravel core unready | backend, AI | `./vendor/bin/pest --filter ReadinessIsolationTest` | `PASS` (unit) | 6 tests, 13 assertions. Proves ready-with-AI-degraded, ready-with-optional-hard-fail, unready-on-critical-fail, and configurable criticality. The container-level system test (actually stopping the AI service) is still outstanding. |
| G-02-05 | Octane workers do not leak request-scoped state; regression test with two synthetic identities | backend | `./vendor/bin/pest --filter OctaneStateIsolationTest` | `PASS` | Reset wired to both `RequestReceived` and `RequestTerminated`, so state survives neither an early return nor an exception. 5 tests with two synthetic identities, including a negative control asserting the leak is real without the hook. |
| G-02-06 | Desktop migration inventory recorded before replacement; no real desktop database silently deleted | desktop, architecture | [desktop-migration-inventory.md](desktop-migration-inventory.md) | `PASS` | Recorded before any file was removed. No `*.db`/`*.sqlite` existed, ADR 0006's encryption spike never closed so no build was permitted to write clinical content locally, and neither app was ever packaged. Safe to replace; no export/import plan required. |
| G-02-07 | Dart/Melos workspace holds the patient app only; npm workspaces hold admin web and both Electron desktops | architecture, desktop | `melos bootstrap`; `npm ls --workspaces` | `PASS` | Melos bootstraps 12 packages (was 14): patient app plus 11 Dart packages, no desktop entries. npm workspaces resolve admin-web, both desktops, and 6 `packages/typescript/*`. No mixed runtime remains in either desktop app. |
| G-02-08 | Doctor and pharmacy differ across every security-relevant namespace | desktop, security | `npm run desktop:test` | `PASS` | App ID, product/executable name, user-data directory, protocol scheme, asset scheme, encrypted-DB namespace, device-credential namespace, capability registry, and update channel all distinct, asserted per app including a check that neither config contains the sibling's identity. |
| G-02-09 | Electron trust boundary: sandbox, context isolation, no Node in renderer, strict CSP, no generic IPC, validated sender, fuses | desktop, security | `npm run desktop:test` | `PARTIAL` | Behavioural sender-origin tests remain; they found a real defect (a credentialed URL `scheme://user:pass@-/` satisfied the origin comparison) which is now rejected. `GrantFileProtocolExtraPrivileges` disabled. Packaged-window WebdriverIO plus binary fuse inspection is G-02-10 `PASS` (Ubuntu/Windows/macOS). |
| G-02-10 | Packaged-artifact Electron E2E: WebdriverIO on the approved OS/architecture matrix, installed-package tests, and binary fuse inspection | desktop, test-engineering | [`g-02-10-packaged-electron-e2e.md`](g-02-10-packaged-electron-e2e.md); workflow [33155677159](https://github.com/mahmoudemad68/clinical_system/actions/runs/33155677159); later confirmation [33398311982](https://github.com/mahmoudemad68/clinical_system/actions/runs/33398311982) | `PASS` | First close: SHA `4a98fac6538546b52f6eff0c5ef98a9608714b90`, Ubuntu/Windows/macOS packaged Doctor + Pharmacy WebdriverIO. Current SHA `11ffb25c7470c4b42fd535e9780b235de57297e4` run `33398311982` SUCCESS on Ubuntu, Windows, and macOS. Signing/notarization remain Phase 23. Not production installer evidence. |

## 3. Contract workflow (Phase 00 §3)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-03-01 | Minimal OpenAPI with health endpoints and common envelope/error schemas | contracts | [openapi.yaml](../../../packages/contracts/openapi/openapi.yaml) | `PASS` | — |
| G-03-02 | Validated and linted in CI; Dart patient-mobile and TypeScript desktop/admin clients generated | contracts | `npm run contracts:generate:ts`; `npm run contracts:generate:dart`; CI job `contracts` | `PASS` | Generated Dart constants live in `packages/flutter/api_client/lib/src/generated`. Hand-written `PlatformApi` still maps health into `clinic_common_models`. Live OpenAPI round-trip against a running API remains a later contract-test row. |
| G-03-03 | Breaking-change detector rejects a deliberate breaking change | contracts | `node scripts/contracts/check-breaking.mjs` against baseline `948ff67` | `PASS` | Three injected breaking changes, one per class ADR 0003 names, all detected with exit 1: `operation-removed` (GET /api/v1/meta/version), `enum-narrowed` (ErrorCode), `new-required-property` (ReadinessResult). Contract restored, exit 0. |
| G-03-04 | Event JSON Schemas and compatibility rules; additive-optional within a version, breaking requires new version + dual read | contracts | [envelope.schema.json](../../../packages/contracts/events/envelope.schema.json), [v1](../../../packages/contracts/events/platform/diagnostics_round_trip_recorded.v1.schema.json), [v2](../../../packages/contracts/events/platform/diagnostics_round_trip_recorded.v2.schema.json); `./vendor/bin/pest --filter a_compatible_v2_payload_is_accepted_during_dual_read` | `PASS` | Consumer dual-reads v1 and v2; schema_version 99 still dead-letters as `unsupported_schema_version`. Producer still emits v1.
| G-03-05 | Provider contract-test suites that future adapters must implement | contracts | `./vendor/bin/pest --filter ProviderPortContractTest`; `FirebaseSendPushAdapterTest` | `PASS` | `SendOtp`, `SendPush`, `StoreObject`, `ScanObject`, `GenerateText`, `RetrieveKnowledge` ports exist. Disabled adapters throw `ProviderNotEnabled`. `FirebaseSendPush` is the live FCM adapter behind empty credentials ⇒ `DisabledSendPush`. In-memory `StoreObject` proves private put, metadata, signed URL shape, and denied anonymous access. Live MinIO test skips when the emulator is down. |
| G-03-06 | Event contract validator rejects a bad schema (oracle proof) | contracts | `node scripts/contracts/validate-events.mjs` with a deliberate bad fixture | `PASS` | Fixture produced 5 failures, exit 1: open payload, `classification: credential`, and `national_id` / `token` properties. Removing it returned exit 0. Fixture removed. |

## 4. Persistence and migrations (Phase 00 §4)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-04-01 | PostgreSQL/PostGIS with least-privilege app and migration roles | postgresql | [01-roles-and-extensions.sql](../../../infra/docker/postgres/initdb/01-roles-and-extensions.sql); `PostgresPrivilegeTest`; `\du` | `PASS` | App/worker/reporter/audit-writer/backup are distinct. Reporter has no SELECT on `users`/`otp_requests` and can read `reporting.*` views. Worker cannot read `users` or update grants. App can INSERT audit rows but not UPDATE/DELETE. Production DBA review of live grants is not done. CI has not run this branch. |
| G-04-02 | Migration conventions and transactional migration checks | postgresql | Platform + Laravel default migrations applied: `migrate:fresh` → DONE | `PARTIAL` | Expand→backfill→contract convention documented in ADR 0008/§4; no automated lock-duration check yet. `notifications` is on the production path. Telescope schema is loaded only when `APP_ENV=local` from `database/telescope/`. |
| G-04-03 | Reference abstractions: UUIDv7, clock, transaction runner, pagination cursor, money, country/currency, safe identifiers | platform | `./vendor/bin/pest --filter ValueObjectTest`; `./vendor/bin/pest --filter CursorAndIdempotencyKeyTest`; `./vendor/bin/pest --filter RequestHashAndRetryTest` | `PASS` | Cursor HMAC round-trip, actor-scope mismatch, tamper rejection; idempotency keys hashed and scoped to actor/operation; canonical JSON hash ignores key order. |
| G-04-04 | Generic outbox and idempotency storage with cleanup/retention jobs | platform, postgresql | `./vendor/bin/pest --filter OutboxDispatcherTest`; `platform:prune` | `PASS` | Dispatcher claims with `FOR UPDATE SKIP LOCKED` under a lease. Tests cover exactly-once under forced duplicate delivery, disjoint claims, lease recovery, backoff, dead-lettering, unsupported-version rejection, and dual-read of additive v2. `platform:prune` deletes in chunks and never prunes dead letters. |
| G-04-05 | Redis namespaces/connections for cache, rate limit, queue, realtime even when local Compose uses one instance | platform | 4 named connections in `config/database.php` (`cache`, `queue`, `realtime`, `ratelimit`) | `PASS` | Production separation is configuration only |
| G-04-06 | Redis flush loses no authoritative record; app can warm required caches | platform | `./vendor/bin/pest --filter RedisFlushIsolationTest` | `PASS` | Flush of the cache Redis DB leaves the `platform_diagnostics` row; `CacheWarmer` restores `platform:meta:version` and `platform:ready:flag` only. Skips if Redis is unreachable. |
| G-04-07 | Database constraints reject invalid state (negative proof) | postgresql | Direct SQL negative tests against the live schema | `PASS` | See "Negative test results" below |

## 5. Data protection (Phase 00 §5)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-05-01 | Classification levels defined: public, internal, personal, sensitive, credential | privacy | [classification-policy.md](../../data-classification/classification-policy.md) | `PASS` | Policy written and encoded in the `Classification` enum, with telemetry, metric-label, and cache rules unit-tested |
| G-05-02 | Per table/event/log/metric: classification, purpose, lawful basis, access roles, retention, encryption, deletion owner | privacy | [data-inventory.md](../../data-classification/data-inventory.md); `./vendor/bin/pest --filter PruneExpiredAuthStateCommandTest` | `PARTIAL` | **Technical inventory** is field-level for live Phase 00/01 schema, client credential stores, and Phase 01 metric families. `auth:prune-expired` timed OTP ciphertext NULL and eligible session DELETE are tested. **Legal/PDPL** remains owner acceptance (`owner_approved_2026-08-27`), not a statutory article. Subject-erasure, statutory retention, and G-08-04 stay OPEN. |
| G-05-03 | Logging redaction processor with canary tests for national IDs, phones, tokens, passwords, clinical text, object keys | platform, security | `./vendor/bin/pest --filter RedactionCanaryTest`; `./vendor/bin/pest --filter ExportRedactionTest` | `PASS` (unit + export path) | 41 tests, 67 assertions on the processor. Export-path suite (G-07-05) asserts capture snapshots drop canaries and that a passthrough redactor fails closed in strict mode. |
| G-05-04 | TLS on non-local hops; private networking for DB/Redis/Qdrant; private object storage; encrypted volumes | devops | Compose binds every port to `127.0.0.1` only | `PARTIAL` | Local posture correct. Staging TLS and network policy not configured. |
| G-05-05 | Synthetic Egyptian-format data generators that cannot produce known real identities | platform | `./vendor/bin/pest --filter SyntheticDataTest` | `PASS` | Generators write into impossible ranges: national-ID century digit 9 (the scheme assigns 2 or 3) with an impossible date, mobile prefix 019 (unallocated), `.invalid` emails. 8 tests, 4,700+ assertions, including one proving the redactor still catches the generated values. |

## 6. CI and environments (Phase 00 §6)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-06-01 | Client local-encryption compatibility spike across 5 target platforms | mobile, desktop, security | [g-06-01-local-encryption-spike.md](g-06-01-local-encryption-spike.md); `npm run test --workspace @clinic/encrypted-local-store`; `flutter test` in `clinic_local_database` | `PARTIAL` | Linux Electron (Node + Electron 44 ABI canary) and Linux Dart sqlite3mc pass canary, rotation, wrong-key, and fail-closed tests. Backup-exclusion flags encoded. Windows, macOS, Android, and iOS not executed. No clinical local writes. ADR 0006 stays open until all five targets pass. |
| G-06-02 | PR pipeline: format, lint, typecheck, architecture rules, contract validation, unit, integration, security scans, SBOM | devops | [pull-request.yaml](../../../.github/workflows/pull-request.yaml) | `PARTIAL` | 7 path-filtered jobs covering all six units plus contracts and security. Written and YAML-valid but **never executed**: there is no GitHub remote, so no run has ever proven it green. |
| G-06-03 | Post-merge: signed immutable artifacts, signed staging deploy, migrations with lock monitoring, smoke checks | devops | [post-merge.yaml](../../../.github/workflows/post-merge.yaml) | `PARTIAL` | Build-once, keyless-sign-by-digest, attest, and scan are written. The staging job deliberately fails rather than reporting a deploy that cannot happen; production promotion is hard-disabled until Phase 23. |
| G-06-04 | All deployment units build reproducibly from locked dependencies | devops | `composer.lock`; `package-lock.json`; `requirements.txt` (hashed); root `pubspec.lock`; `npx electron-forge package` | `PARTIAL` | core-api image builds from lock, exit 0. `npm ci` from the committed lock reproduces a single copy of every overridden package on Node 22.23.2 / npm 10.9.8. The Dart pub workspace produces one root `pubspec.lock`. Linux x64 unsigned Forge packages exist locally for both desktops (gitignored `out/`). Windows/macOS packages, signing, and CI reproduction are outstanding. |
| G-06-05 | Images/artifacts carry SBOMs with no unaccepted critical findings | security | `npm ci` + `npm audit` on Node 22.23.2 / npm 10.9.8; [security-findings.md](security-findings.md) | `PARTIAL` | **Critical: 0. Moderate: 0. Low: 0.** Root overrides resolve `tar` to 7.5.22, `tmp` 0.2.7, `uuid` 11.1.1, `webpack-dev-server` 5.2.6, each a single copy from a clean `npm ci`. One true high root remains: `extract-zip` `<= 2.0.1`, whose latest published version *is* 2.0.1, so no upgrade exists. High blocks promotion; merge needs a recorded time-boxed exception (SF-001). Semgrep carries 13 rules including 6 Electron boundary rules. SBOM generation itself is configured but unexecuted. |

## 7. Observability (Phase 00 §7)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-07-01 | Propagate traceparent, request/correlation/causation IDs, pseudonymous actor, service/version without PHI | observability | `AssignCorrelationId`; `InstrumentHttp`; `./vendor/bin/pest --filter MetricsAndTraceTest` | `PARTIAL` | Correlation IDs assigned and persisted. W3C `traceparent` is adopted when well-formed and echoed as `traceresponse`; hostile values are dropped. HTTP attributes stay in-process (bounded allow-list). Request inspection is Laravel Telescope (local only). The OpenTelemetry SDK/collector is not used. |
| G-07-02 | Instrument request rate/error/latency, DB pool and query latency, Redis errors, queue depth/age/failures, outbox backlog, Reverb connections, provider failures | observability | `GET /metrics`; `./vendor/bin/pest --filter MetricsAndTraceTest`; `FirebaseSendPushAdapterTest` | `PARTIAL` | Prometheus text now includes HTTP, readiness, outbox pending/dead-letter/age, `clinic_db_connections_*`, `clinic_db_query_duration_seconds_*`, `clinic_horizon_queue_depth`, and `clinic_redis_errors_total`. `clinic_reverb_connections` is exported as 0 (process-local Reverb count is not scraped). `clinic_provider_failures_total` is incremented with `error_class=push` when FCM throws; other provider adapters remain unused. |
| G-07-03 | Bounded metric labels; never patient/doctor/appointment/file/prescription/free-text values | observability | `Classification::allowedAsMetricLabel()`; collector `attributes/bound_metric_labels` | `PASS` | Encoded in types and unit-tested, enforced again at the collector, which deletes `patient_id`, `doctor_id`, `appointment_id`, `prescription_id`, `file_id`, and `user_id` if they ever appear |
| G-07-04 | Alerts with owner, severity, threshold, sustain period, runbook, false-positive review | observability | [alerts/platform.yaml](../../../infra/monitoring/alerts/platform.yaml) | `PARTIAL` | 10 rules, every one carrying owner, severity, sustain period, and a runbook link that resolves. 3 runbooks are written in full; 5 are honest stubs, because writing them needs operational experience the platform has not had yet. |
| G-07-05 | Traces/logs from a synthetic clinical-looking request are redacted before leaving the process | observability, security | `./vendor/bin/pest --filter ExportRedactionTest` | `PASS` | `TelemetryGateway::captureExport` redacts before snapshot. A passthrough redactor in strict mode raises `RedactionFailure`. HTTP span attributes drop `patient_id` and free text. |

## 8. Security and privacy (Phase 00 "Security and privacy work")

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-08-01 | STRIDE + privacy threat model across the 8 named trust boundaries | security | [phase-00-foundation.md](../../threat-models/phase-00-foundation.md) | `PARTIAL` | All 8 boundaries analysed with STRIDE, DFDs, and residual risks. Engineering draft only. **G-08-04 remains OPEN / EXTERNAL_HUMAN.** Phase 22 remains the independent re-review. |
| G-08-02 | Mandatory controls: deny-by-default, request/content-size limits, safe parsers, no wildcard CORS, secure admin headers, secrets withheld from fork jobs, non-root/read-only containers, SBOM provenance, config/flag/secret audit trail, tested redaction, documented emergency rotation | security | `EnforceRequestBounds`; `CorsPolicyTest`; `PersonaStatusPageTest`; `ConfigChangeAuditor`; [emergency-credential-rotation.md](../../runbooks/emergency-credential-rotation.md) | `PARTIAL` | Audit trail and rotation runbook added. CORS origin `*` rejected by test. Inertia pages emit a same-origin CSP; API responses remain `default-src 'none'`. Telescope is local-only and off the production migration path. SBOM provenance still unexecuted (no GitHub remote). Secret-scanning policy exists in CI YAML only. |
| G-08-03 | Versioned mappings to OWASP ASVS 5.0.0, OWASP API Security, OWASP MASVS/MASTG | security | [owasp-asvs-mapping.md](../../security/owasp-asvs-mapping.md) | `PASS` | Engineering taxonomy with APPLIED/PARTIAL/NOT_APPLICABLE/NOT_TESTED. Explicitly not statutory compliance. Owner acceptance of the Phase 00 draft is G-08-04; independent re-review remains Phase 22. |
| G-08-04 | Threat model and data classification have security/privacy approval | security, privacy | [independent-phase-00-phase-01-review-2026-08-26.md](../security-review/independent-phase-00-phase-01-review-2026-08-26.md) | `OPEN` | Independent review on 2026-08-26 recommended **DO NOT APPROVE**. Assessor/remediator separation is lost (Mahmoud holds every named owner role). This implementer cannot close G-08-04. Independent retest is required after ISR remediations. Not statutory compliance. |

## 9. Test plan (Phase 00 "Test plan")

| Category | Requirement | Result | Note |
| --- | --- | --- | --- |
| Unit | UUIDv7, money, quantity, Cairo DST, cursor, error mapping, request hashing, retry classifier, redaction | `PASS` | Pest runs the in-place PHPUnit `*Test.php` classes plus new Pest files. ValueObjectTest, CursorAndIdempotencyKeyTest, RequestHashAndRetryTest, RedactionCanaryTest, ExportRedactionTest |
| Unit | Domain/application dependency tests prove inner layers import no framework code | `PASS` | deptrac plus ArchitectureBoundaryTest |
| Unit | Idempotency same/different hash, concurrent processing, expiry, retryable failure | `PASS` | DiagnosticsSliceTest plus EloquentIdempotencyStore behaviour |
| Unit | Outbox retry capped, jittered, distinguishes permanent failures | `PASS` | RetryPolicy unit tests; dispatcher already covers permanent vs retry |
| Integration | Transaction rollback, outbox atomicity, worker claiming, duplicate consumption, lock expiry, cache loss, migration forward compatibility | `PARTIAL` | Outbox + diagnostics + Redis flush. Mixed-version schema roll remains Phase 21 |
| Integration | S3 private objects, encryption metadata, signed URL expiry, denied anonymous access | `PARTIAL` | In-memory contract PASS. Live MinIO skips when down |
| Integration | Reverb private-channel authorization scaffold, disconnect behavior | `PARTIAL` | BroadcastChannelDenyTest: unauthenticated `/broadcasting/auth` is refused (403 collapsed to 404 in the JSON envelope). Disconnect under load is Phase 21 |
| Integration | FastAPI stub internal auth, deadline propagation, unavailability isolation | `PASS` | pytest 15 passed: auth, deadline, extra-field rejection, PHP-serialize isolation, Hypothesis garbage bodies, staging refuses `DB_*` |
| Contract | OpenAPI validation + generated clients against a running API | `PARTIAL` | Validation and TS/Dart generation pass; not yet run against a live API |
| Contract | Event consumers accept current and previous compatible schemas | `PASS` | Dual-read v1+v2 on `platform.diagnostics_round_trip_recorded`; incompatible v99 dead-letters |
| E2E | Four clients show core health/version in Arabic and English | `PARTIAL` | API `/api/v1/health` negotiates ar/en. First-party Inertia pages for all four personas render catalogue copy in ar/en with RTL. Flutter `HealthPanel` widget tests, admin-web `HealthPanel` tests, and Electron renderers expose locale switches. Packaged Electron locale/RTL is G-02-10 `PASS` on Ubuntu, Windows, and macOS. |
| E2E | Committed synthetic event reaches a consumer exactly once despite forced duplicate delivery | `PARTIAL` | OutboxDispatcherTest covers exactly-once; packaged client E2E is not done |
| System | Stop AI/Qdrant, flush Redis, kill a worker mid-outbox, roll a compatible schema change, graceful shutdown under load | `PARTIAL` | Redis flush PASS. AI isolation unit PASS. Kill-worker covered by lease recovery. Graceful shutdown under load is Phase 21 |
| Security | SAST, dependency, image, IaC, license, SBOM, secret scans with blocking severity policy | `PARTIAL` | Policy and CI YAML exist; GitHub remote has never executed them |
| Security | Canary values for national ID, token, password, prescription-like and lab-like text appear nowhere in logs/traces/errors | `PASS` | RedactionCanaryTest + ExportRedactionTest |

---

## Negative test results

The phase requires forcing at least one denied case to prove the oracle can
fail. Two independent oracles were exercised.

### Event contract validator

Command: `node scripts/contracts/validate-events.mjs`

A fixture declaring `classification: credential`, an open payload, and
`national_id` / `token` properties was added, then removed.

| Input | Expected | Actual |
| --- | --- | --- |
| Fixture present | non-zero exit, findings named | exit 1, 5 findings |
| Fixture removed | exit 0 | exit 0, 2 schemas checked |

### Database CHECK constraints

Direct SQL against the running schema, bypassing the application entirely —
which is the point: these constraints are the layer that survives a caller
reaching the database by another route.

| Input | Expected | Actual |
| --- | --- | --- |
| `outbox_events.classification = 'credential'` | reject | rejected by `outbox_events_classification_check` |
| `outbox_events.status = 'WHATEVER'` | reject | rejected by `outbox_events_status_check` |
| `status = 'PROCESSED'` with `processed_at IS NULL` | reject | rejected by `outbox_events_processed_consistency_check` |
| `idempotency_keys.state = 'SUCCEEDED'` with no status code or response reference | reject | rejected by `idempotency_keys_succeeded_has_outcome_check` |
| Valid `outbox_events` row | accept | `INSERT 0 1` |
| Label `'patient complains of chest pain NID 29801011234567'` | reject | rejected by `platform_diagnostics_label_check` |
| Label `'run-29801011234567'` (14-digit national ID in a valid slug) | reject | rejected by `platform_diagnostics_label_no_identifier_check` |
| Label `'x01012345678'` (11-digit mobile) | reject | rejected by `platform_diagnostics_label_no_identifier_check` |
| Label `'smoke-run-42'` | accept | `INSERT 0 1` |

## Defects found and fixed during this phase

| # | Defect | How found | Fix |
| --- | --- | --- | --- |
| 1 | `RecordRoundTripHandler` committed, then read the outbox `event_id` back and patched the row, leaving a window where the diagnostics row existed with a null outbox link | Review while writing | Record through `OutboxRecorder` inside the transaction; the foreign key is set atomically |
| 2 | All 11 timestamp columns were `timestamp(0) with time zone`. Laravel's `timestampTz()` defaults to precision 0, so microseconds were silently truncated: two events in the same second would share an `occurred_at`, and jittered backoff on `available_at` would quantise to whole seconds | `information_schema` inspection after the first migration run | `timestampTz($col, 6)` across all three migrations; re-verified as precision 6 |
| 3 | `platform_diagnostics.label` pattern `[A-Za-z0-9 _-]{1,64}` accepted arbitrary alphanumeric prose including a 14-digit national ID, while the migration comment claimed free-form content could not reach it | Negative test that was expected to fail and did not | Slug pattern `^[a-z][a-z0-9_-]{0,63}$` plus a second constraint rejecting any run of 10+ digits; OpenAPI and event schema patterns aligned; misleading comment corrected |
| 4 | `oasdiff-js`, an unofficial third-party wrapper, was about to be placed on the breaking-change CI gate | Package identity check before install | Dropped; own auditable checker written, with the official `tufin/oasdiff` image as an independent cross-check in CI |
| 5 | `BufferedTransactionContext` was declared in the same file as `DatabaseTransactionRunner`, so PSR-4 could not autoload it | Review while writing | Split into its own file |
| 6 | The redaction canary suite passed with the national-ID rule deleted. The `card_number` rule (13-19 digits) was swallowing 14-digit national IDs, so that rule was never under test. Nothing leaked, but narrowing `card_number` later (to Luhn-valid numbers, say) would have started leaking national IDs with no test failing | Deliberately deleted the rule to check the suite could fail; it could not | Each canary now asserts the exact emitted hint (`[redacted:national_id]`), pinning every rule independently. Re-verified: deleting the rule now produces 2 failures naming the masking rule |
| 7 | `Money::multiplyBy()` and `add()` could not detect integer overflow. PHP promotes an overflowing int to float mid-expression, so `intdiv()` raised `TypeError` on exactly the input the guard existed to catch | `ValueObjectTest::money_detects_multiplication_overflow` | Replaced magnitude comparison with `exactInt()`, which checks whether the result became a float. Both operands are ints, so a float result always means overflow |
| 8 | `deptrac` found 4 real boundary violations in the Platform kernel: `RecordRoundTripHandler` and `ReadinessProbe` (Application) depended on `Illuminate\Database\ConnectionInterface` and the Redis factory, and `AssignCorrelationId` (Http) depended on an Infrastructure class | First `deptrac` run | Extracted `DiagnosticsRepository`, `DependencyCheck`, and `CorrelationScope` ports. `ReadinessProbe` now holds no framework type, which is why gate G-02-04 is provable as a fast unit test rather than a container test |
| 9 | `config/platform.php` did not exist, so every `config('platform.*')` call in the service provider silently returned null. The AI probe had no base URL, idempotency had no retention, and the outbox had no batch size — all defaulting rather than failing | Reading the provider against the config directory | Wrote the file with fail-closed defaults for every key |
| 10 | `EloquentIdempotencyStore::claim()` caught a unique-violation and then read the existing row. In PostgreSQL a raising statement aborts the whole transaction (SQLSTATE 25P02), so the subsequent read fails and the caller's transaction is poisoned — exactly the situation an ADR 0004 coordinator creates | Feature test: second request with the same key returned 500 | Rewritten as `INSERT ... ON CONFLICT DO NOTHING`, which reports the conflict through the affected-row count and never raises |
| 11 | The OpenAPI contract documented `200` for an idempotent replay while the implementation replayed the original `201`. The implementation was right — "returns the original outcome" includes the status code — so the contract was wrong | Feature test asserting the documented behaviour | Contract now documents replay via the `Idempotent-Replay` header, with an explicit note that branching on status to detect a replay is wrong |
| 12 | `openapi-fetch` captured `globalThis.fetch` at construction, so the admin transport could not be substituted in a test and could not be wrapped later without editing the client | Admin tests hung in the loading state | Dereference `globalThis.fetch` per call |
| 13 | The admin client used `baseUrl: '/'`, which only resolves in a browser. Node, jsdom, and any server-side render fail with "Failed to parse URL" | Same test failure, after the fetch fix | Resolve an absolute origin so one code path works everywhere |
| 14 | Riverpod 3 removed `StateProvider`, which all three Flutter apps used for locale | `melos run analyze` | Replaced with a `Notifier` that validates the locale against the supported set rather than accepting arbitrary assignment |
| 15 | The Flutter widget tests used stub localization delegates supporting only English, so the Arabic RTL assertion failed for a fake reason and the Arabic text assertions were not testing localization at all | Widget test failure with a "locale not supported by all delegates" warning | Use the real `flutter_localizations` delegates, the same stack the apps run |
| 16 | The synthetic national-ID generator emitted 13 digits, not 14: the date component is `YYMMDD` (6) and I wrote 5 | `SyntheticDataTest::national_ids_are_structurally_valid` | Corrected to 6, so generated values are structurally valid and still impossible |
| 17 | `clinic_migrator` had a `CONNECTION LIMIT` of 5, too tight for a test suite that opens both the default and the migration connection per process | `too many connections for role` during the full suite | Raised to 25, with the reasoning recorded in the initdb script |
| 18 | `@electron/fuses@2.1.3` (registry latest) is incompatible with `@electron-forge/plugin-fuses@7.11.2`, which declares peer `^1.0.0`. Install failed outright | `npm install` ERESOLVE | Pinned 1.8.0. ADR 0008 requires resolving against the registry **and** checking compatibility; taking latest is not the same thing |
| 19 | `exactOptionalPropertyTypes` rejects `osxSign: undefined` in the Forge config. The intent — no signing identity in Phase 00 — is expressed by omitting the keys | `npm run desktop:typecheck` | Keys removed; the test now asserts their absence plus the absence of any credential-shaped environment variable |
| 20 | The trust-boundary suite failed on its own documentation: a `toContain('--no-sandbox')` check matched the source comment warning against that flag | First run of the new suite | Added a comment-stripping reader. A security assertion a comment can satisfy is not an assertion |
| 21 | The asset-scheme test asserted the literal appeared in `main/index.ts`, but main references it through `APP_CONFIG`, so the literal lives in `app-config.ts` | Same run | Assert the wiring in main and the literal on the config it reads |
| 22 | jsdom refuses to run under the packaged asset scheme: `localStorage is not available for opaque origins`, because it does not know the scheme was registered standard and privileged | Desktop test run | Desktop tests default to the `node` environment, which is what source/schema inspection needs anyway; renderer component tests will opt into jsdom per file |
| 23 | An npm `overrides` entry for `tar` does not replace nested copies — five physical `tar@6.2.1` directories survived and npm merely flagged them `invalid ... overridden`. This is the second time an override failed to win against nested resolution in this repository (the first was jsdom) | `find node_modules -path '*/tar/package.json'` | Recorded as SF-001 rather than papered over. The override is retained for root-resolving consumers but explicitly documented as not a fix |
| 24 | **Defect 23 was itself wrong.** The five nested `tar@6.2.1` copies were stale dependency-tree/lockfile reconciliation, not a limitation of npm overrides. A clean `rm -rf node_modules package-lock.json && npm install` on the required Node 22.23.2 / npm 10.9.8 resolves the whole tree to a single `tar@7.5.22`, and `npm ci` reproduces it | External review challenged the conclusion; clean reproduction confirmed it | Overrides retained and verified working. SF-001 rewritten with the correction kept visible rather than silently replaced |
| 25 | SF-001 claimed "Forge 7.11.2 pins `@electron/rebuild@3.7.2`". Forge declares `^3.7.0` — a range. The lockfile selected 3.7.2 | Same review | Corrected in SF-001 |
| 26 | SF-001 offered "security owner accepts with expiry" for a **Critical**. ADR 0008 gives Critical no exception path — it blocks merge *and* promotion; only High allows a recorded time-boxed merge exception | Same review | SF-001 rewritten; the option is only offered now that the finding is High |
| 27 | 25 packages were reported as "high" when they were transitive paths through a handful of roots. The real picture was 4 true roots, three of which had fixes available | Same review | Audit now reported by advisory root. `tmp`, `uuid`, and `webpack-dev-server` overridden; only `extract-zip` remains, with no published fix |
| 28 | IPC sender validation accepted **any host** under the custom scheme, allowed localhost unconditionally behind a comment claiming it was unreachable when packaged, and never checked the owning `BrowserWindow` | Same review | Exact-origin comparison, `!app.isPackaged` gate on the dev-server branch, and a registered-WebContents check |
| 29 | A credentialed URL `scheme://user:pass@-/` parses with host `-`, so it satisfied the origin comparison and would have been accepted | The new behavioural sender-policy test | URLs carrying a username or password are rejected before any comparison |
| 30 | `GrantFileProtocolExtraPrivileges` was left at its default while the app serves from a custom protocol, keeping an elevated `file://` path the application has no use for | Same review | Fuse disabled, per Electron guidance |
| 31 | The headline ledger totals were wrong: legend and test-plan category rows were counted as gates | Same review | `scripts/evidence/count-gates.mjs` counts only `G-NN-NN` rows, fails on duplicates or a mismatched expectation, and runs in CI |
| 32 | `BROADCAST_CONNECTION=null` made `/broadcasting/auth` return 200 for an unauthenticated subscriber because `NullBroadcaster::auth` is a no-op | Feature `BroadcastChannelDenyTest` against PostgreSQL | Tests now use the Reverb driver. Unauthenticated private-channel auth raises `AccessDeniedHttpException` (403), collapsed to 404 by `ExceptionRenderer` |
| 33 | The concurrent-idempotency feature test inserted `PROCESSING` with a dummy request hash, so the second request was classified as `IDEMPOTENCY_KEY_REUSED` rather than `IDEMPOTENCY_IN_PROGRESS` | Feature suite in FrankenPHP + PostgreSQL | Test now stores the canonical hash of the body that will be posted |
| 34 | AI pytest imported `tests.conftest`, which fails unless the repo root is on `PYTHONPATH` | Local pytest collection | Fixtures injected via pytest; `pythonpath` includes `src` |
| 35 | Published Telescope migrations sat on the production `database/migrations` path, so `migrate:fresh` would create request-dump tables in every environment | Review while finishing Phase 00 Inertia/Telescope gating | Moved to `database/telescope/` and loaded only when `APP_ENV=local`. Pest asserts the production path is empty |

Defects 2 and 3 are worth noting as a pair: both were framework or regex
defaults that looked correct in review and were only exposed by inspecting what
the database actually did. Neither would have failed a happy-path test.

## Blockers requiring a decision or a human owner

| # | Blocker | Needs |
| --- | --- | --- |
| 1 | GitHub CODEOWNERS teams do not exist; branch protection cannot enforce review rules | Create the GitHub organization and teams, or map `@clinic/...` to real usernames. Humans are named in [accountable-owners.md](../../governance/accountable-owners.md). |
| 2 | ADR 0006 encryption spike (G-06-01) | Linux canary/rotation/fail-closed evidence exists. Still needs Windows, macOS, Android, and iOS execution. Not waived. No client may store clinical content locally until closed. |
| 3 | Packaged Electron E2E (G-02-10) | **Closed as PASS** on SHA `4a98fac6538546b52f6eff0c5ef98a9608714b90`, workflow `33155677159` (Ubuntu `98797779682`, Windows `98797779679`, macOS `98797779637`). Both Doctor and Pharmacy packaged apps launched on each OS. Signing/notarization remain Phase 23. |
| 4 | No staging environment | Infrastructure decision and budget |
| 5 | SF-001: one high advisory (`extract-zip <= 2.0.1`) in the Electron build toolchain with no published fix. Blocks promotion, not merge | Security owner decision under ADR 0008: time-boxed merge exception with an expiry, a vendored patch, or a tooling change (which needs a compatibility ADR) |

## Honest summary

**47 gates: 31 PASS, 15 PARTIAL, 0 BLOCKED, 1 OPEN** (reproduce with
`node scripts/evidence/count-gates.mjs --expect PASS=31,PARTIAL=15,BLOCKED=0,OPEN=1`).

All six deployment units build, lint, type-check, and test green:

| Unit | Verification |
| --- | --- |
| core-api | **Pest**: 130 unit (4,915 assertions) on host PHP 8.3.6; 64 feature (448 assertions, 1 skipped: live MinIO) in FrankenPHP 8.3 against Docker PostgreSQL 16 + Redis 7. deptrac 0 violations / 530 allowed / 0 uncovered; Pint clean; PHPStan level 6 clean. First-party Inertia status pages, database notifications, local-only Telescope, and the Firebase push adapter are in tree. |
| ai-service | pytest **15 passed** (auth, deadline, isolation, queue boundary, Hypothesis fuzz); ruff clean on tests/src |
| admin-web | 5 tests, type-check and type-aware ESLint clean, production build |
| doctor-desktop | 35 tests (boundary + sender policy + local-encryption probe), type-check clean; Linux x64 unsigned Forge package produced |
| pharmacy-desktop | 35 tests, type-check clean; Linux x64 unsigned Forge package produced |
| patient-app + 11 Dart packages | `melos analyze` clean with `--fatal-infos --fatal-warnings`; secure-storage and local-database spike tests pass on Linux |
| 6 shared TypeScript packages | type-check clean; `@clinic/encrypted-local-store` 16 tests |

Eight oracles have been demonstrated capable of failing: the event validator,
the database CHECK constraints, `deptrac`, the redaction canaries, the
breaking-change detector, the synthetic-data generator, the Electron
trust-boundary suite, and the gate counter.

### What external review corrected

An external review rejected this ledger's dependency conclusion, and it was
right. The claimed unfixable Critical was a stale-lockfile artifact: a clean
install on the project's required Node 22 toolchain resolves to a single
`tar@7.5.22`, and `npm ci` reproduces it. The finding also mis-stated Forge as
pinning a version it declares as a range, grouped 25 transitive paths as if they
were 25 findings, and proposed an exception route that ADR 0008 does not
authorize for a Critical. Defects 24 to 31 record all of it.

The same review found three real code defects in the Electron boundary — an
origin check that accepted any host under the scheme, an unenforced
"development only" claim, and a missing fuse — plus a missing owning-window
check. Fixing the origin check with a *behavioural* test then surfaced a fourth
defect the previous substring tests could never have caught: a credentialed URL
satisfied the comparison.

### What is still not true

- **SF-001 remains open at high severity.** `extract-zip <= 2.0.1` has no
  published fix; 2.0.1 *is* the latest. High blocks promotion; merge needs a
  recorded, time-boxed exception from a security owner.
- **The Electron packaged-window matrix is G-02-10 PASS.** Ubuntu, Windows, and
  macOS each packaged and launched Clinic Doctor and Clinic Pharmacy
  (workflow `33155677159`). Source and behavioural tests remain under G-02-09.
  Signing and notarization remain Phase 23.
- **Packaged Electron E2E CI has run.** Workflow `33155677159` on SHA
  `4a98fac6538546b52f6eff0c5ef98a9608714b90`. Other CI jobs stay on their own
  gates (G-06-02 is still PARTIAL).
- **Redaction is proven on the in-process export path** (G-07-05). Local
  request inspection is Laravel Telescope. There is no OpenTelemetry collector
  or OTLP export (G-07-01 stays PARTIAL: no distributed trace pipeline).
- **`clinic_reporter` is limited to reporting views** on identity data in local
  `clinic_test` (`PostgresPrivilegeTest`). Production grant review is still
  outstanding.
- **No authorization layer exists for later clinical modules.** Independent
  security/privacy re-review is deferred to Phase 22. **G-08-04 is OPEN** after
  the 2026-08-26 independent review (DO NOT APPROVE). Assessor/remediator
  separation is lost.
- **No SBOM has been produced, and no load test has ever run.** Linux
  encrypted-store tests and unsigned Electron packages exist; they do not close
  five-platform G-06-01.

Phase 00 must not be described as complete. Packaged Electron E2E (G-02-10)
is PASS on Ubuntu, Windows, and macOS for SHA
`4a98fac6538546b52f6eff0c5ef98a9608714b90`. The local-encryption spike
(G-06-01) is PARTIAL: Linux passed; the other four OS targets have not run.
G-08-04 is OPEN and does not waive remaining gates.
