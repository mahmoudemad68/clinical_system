# Phase 00 — Evidence ledger

One row per mandatory gate. A gate is `PASS` only when a named artifact or a
reproducible command produced the stated result. Everything else is `PARTIAL`,
`BLOCKED`, or `OPEN`, with the owner and the next action named.

**Never in this ledger:** credentials, national IDs, medical content, raw
prompts, object keys, or exploit payloads.

- **Environment:** local Docker Compose, single host, 4 vCPU / 7 GB
- **Recorded:** 2026-08-24
- **Toolchain:** PHP 8.3.6 · Composer 2.8.12 · Laravel 13.26.1 · Node 20.20.2 ·
  npm 10.8.2 · Python 3.12.3 · Flutter 3.47.1 · Dart 3.13.1 · Docker 29.7.2 ·
  PostgreSQL 16 + PostGIS 3.4 · Redis 7
- **Status:** Phase 00 is **OPEN**. Foundation and contract gates are evidenced;
  client, CI, observability, and assurance gates are not.

## Legend

| Result | Meaning |
| --- | --- |
| `PASS` | Verified by a named command or artifact, with the result recorded |
| `PARTIAL` | Implemented, not yet fully evidenced |
| `BLOCKED` | Cannot be evidenced in this environment; blocker named |
| `OPEN` | Not started |

---

## 1. Architecture and ownership (Phase 00 §1)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-01-01 | ADRs for modular monolith + separate AI service, repo layout, API-first contracts, outbox, UUIDv7, local encryption, data ownership | architecture | [docs/adr](../../adr/) 0001–0007, plus 0008 package policy and 0009 queue boundary | `PASS` | ADR 0006 carries an open compatibility spike (G-06-01) |
| G-01-02 | C4 context, container, and one component diagram showing dependency direction | architecture | [c4-context](../../architecture/c4-context.md), [c4-container](../../architecture/c4-container.md), [c4-component](../../architecture/c4-component-module-dependency.md) | `PASS` | Diagrams are Mermaid; no rendering check in CI yet |
| G-01-03 | Module catalog: owner, public ports, events, tables, classification, prohibited dependencies | architecture | [module-catalog.md](../../architecture/module-catalog.md), 24 modules | `PASS` | Only `Platform` is implemented; the rest are declarations |
| G-01-04 | CODEOWNERS / review rules for clinical, pharmacy-financial, identity, infrastructure, AI safety | architecture | [.github/CODEOWNERS](../../../.github/CODEOWNERS) | `PARTIAL` | Team handles are placeholders. The GitHub org teams do not exist, so branch protection cannot enforce the file. Needs the five accountable owners from the entry criteria. |
| G-01-05 | Automated architecture check fails on a known forbidden-dependency fixture, then fixture removed | architecture | `deptrac analyse --config-file=deptrac.yaml` | `PASS` | Clean run: 0 violations, 216 allowed, 0 uncovered. Fixture (`Domain` class importing Eloquent + `DB` facade) produced exit 1 with 2 violations; removed, exit 0. |

## 2. Runtime bootstrap (Phase 00 §2)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-02-01 | Each deployment unit scaffolded without feature code | all stacks | All six units present and building | `PASS` | core-api (Laravel 13.26.1), ai-service (FastAPI), admin-web (React 19/Vite 8), patient-app, doctor-desktop, pharmacy-desktop (Flutter 3.47.1) plus 11 shared Dart packages |
| G-02-02 | `/live`, `/ready`, build/version metadata, structured errors, correlation IDs, graceful shutdown | backend, AI | `phpunit --filter HealthEndpointTest` | `PASS` (HTTP) | 17 feature tests through the real middleware stack: envelope shape, ar/en negotiation, correlation echo, malformed-id replacement, secure headers, and a check that `/ready` leaks no host detail. Graceful shutdown under active load remains untested (Phase 21). |
| G-02-03 | Liveness checks only process health; readiness checks critical startup state without making every optional dependency a core outage | backend | `phpunit --filter HealthEndpointTest` | `PASS` | Asserted at the HTTP layer: `/live` is unenveloped and touches no dependency; `/ready` names its checks and marks `ai_service` non-critical |
| G-02-04 | AI/Qdrant readiness failure does not make Laravel core unready | backend, AI | `phpunit --filter ReadinessIsolationTest` | `PASS` (unit) | 6 tests, 13 assertions. Proves ready-with-AI-degraded, ready-with-optional-hard-fail, unready-on-critical-fail, and configurable criticality. The container-level system test (actually stopping the AI service) is still outstanding. |
| G-02-05 | Octane workers do not leak request-scoped state; regression test with two synthetic identities | backend | `phpunit --filter OctaneStateIsolationTest` | `PASS` | Reset wired to both `RequestReceived` and `RequestTerminated`, so state survives neither an early return nor an exception. 5 tests with two synthetic identities, including a negative control asserting the leak is real without the hook. |

## 3. Contract workflow (Phase 00 §3)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-03-01 | Minimal OpenAPI with health endpoints and common envelope/error schemas | contracts | [openapi.yaml](../../../packages/contracts/openapi/openapi.yaml) | `PASS` | — |
| G-03-02 | Validated and linted in CI; Dart and TypeScript test clients generated | contracts | `.github/workflows/pull-request.yaml` job `contracts` | `PARTIAL` | Lint, event validation, TS generation, and a stale-client check all run in CI. The Dart client is hand-written against the contract rather than generated; generation is deferred until the contract carries real operations (Phase 01). |
| G-03-03 | Breaking-change detector rejects a deliberate breaking change | contracts | `node scripts/contracts/check-breaking.mjs` against baseline `948ff67` | `PASS` | Three injected breaking changes, one per class ADR 0003 names, all detected with exit 1: `operation-removed` (GET /api/v1/meta/version), `enum-narrowed` (ErrorCode), `new-required-property` (ReadinessResult). Contract restored, exit 0. |
| G-03-04 | Event JSON Schemas and compatibility rules; additive-optional within a version, breaking requires new version + dual read | contracts | [envelope.schema.json](../../../packages/contracts/events/envelope.schema.json), [events/README.md](../../../packages/contracts/events/README.md), 1 payload schema | `PASS` | Dual-read consumer test arrives with the first real consumer |
| G-03-05 | Provider contract-test suites that future adapters must implement | contracts | — | `OPEN` | No provider ports exist yet in Phase 00 |
| G-03-06 | Event contract validator rejects a bad schema (oracle proof) | contracts | `node scripts/contracts/validate-events.mjs` with a deliberate bad fixture | `PASS` | Fixture produced 5 failures, exit 1: open payload, `classification: credential`, and `national_id` / `token` properties. Removing it returned exit 0. Fixture removed. |

## 4. Persistence and migrations (Phase 00 §4)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-04-01 | PostgreSQL/PostGIS with least-privilege app and migration roles | postgresql | [01-roles-and-extensions.sql](../../../infra/docker/postgres/initdb/01-roles-and-extensions.sql); `\du` shows 5 roles with connection caps | `PASS` | `clinic_app`/`worker`/`reporter` hold no DDL; `pgsql_migrator` connection is the only DDL path |
| G-04-02 | Migration conventions and transactional migration checks | postgresql | 3 Platform migrations applied: `migrate:fresh` → all 6 DONE | `PARTIAL` | Expand→backfill→contract convention documented in ADR 0008/§4; no automated lock-duration check yet |
| G-04-03 | Reference abstractions: UUIDv7, clock, transaction runner, pagination cursor, money, country/currency, safe identifiers | platform | `phpunit --filter ValueObjectTest` | `PARTIAL` | 21 tests, 488 assertions: UUIDv7 version + monotonicity, v4 rejection, exact money arithmetic with overflow detection, quantity unit safety, Cairo DST (+02:00 winter / +03:00 summer), classification rules. Pagination cursor and idempotency-key hashing still untested. |
| G-04-04 | Generic outbox and idempotency storage with cleanup/retention jobs | platform, postgresql | `phpunit --filter OutboxDispatcherTest`; `platform:prune` | `PASS` | Dispatcher claims with `FOR UPDATE SKIP LOCKED` under a lease; 10 tests cover exactly-once under forced duplicate delivery, disjoint claims across two workers, lease recovery, backoff, dead-lettering, and unsupported-version rejection. `platform:prune` deletes in chunks and never prunes dead letters. |
| G-04-05 | Redis namespaces/connections for cache, rate limit, queue, realtime even when local Compose uses one instance | platform | 4 named connections in `config/database.php` (`cache`, `queue`, `realtime`, `ratelimit`) | `PASS` | Production separation is configuration only |
| G-04-06 | Redis flush loses no authoritative record; app can warm required caches | platform | — | `OPEN` | Needs the flush-Redis system test |
| G-04-07 | Database constraints reject invalid state (negative proof) | postgresql | Direct SQL negative tests against the live schema | `PASS` | See "Negative test results" below |

## 5. Data protection (Phase 00 §5)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-05-01 | Classification levels defined: public, internal, personal, sensitive, credential | privacy | [classification-policy.md](../../data-classification/classification-policy.md) | `PASS` | Policy written and encoded in the `Classification` enum, with telemetry, metric-label, and cache rules unit-tested |
| G-05-02 | Per table/event/log/metric: classification, purpose, lawful basis, access roles, retention, encryption, deletion owner | privacy | [data-inventory.md](../../data-classification/data-inventory.md) | `PARTIAL` | Every Phase 00 table, event, log, metric, and cache prefix inventoried. Retention owners are `UNASSIGNED` and lawful basis is blank: both need the accountable privacy and legal owners, and stating them without those owners would be worse than leaving them blank. |
| G-05-03 | Logging redaction processor with canary tests for national IDs, phones, tokens, passwords, clinical text, object keys | platform, security | `phpunit --filter RedactionCanaryTest` | `PASS` (unit) | 41 tests, 67 assertions. Asserts the *specific* rule fires per canary, not merely that the value vanished — see defect 6. Also asserts ordinary operational values survive, so a scrub-everything implementation fails. Export-path integration (G-07-05) still outstanding. |
| G-05-04 | TLS on non-local hops; private networking for DB/Redis/Qdrant; private object storage; encrypted volumes | devops | Compose binds every port to `127.0.0.1` only | `PARTIAL` | Local posture correct. Staging TLS and network policy not configured. |
| G-05-05 | Synthetic Egyptian-format data generators that cannot produce known real identities | platform | `phpunit --filter SyntheticDataTest` | `PASS` | Generators write into impossible ranges: national-ID century digit 9 (the scheme assigns 2 or 3) with an impossible date, mobile prefix 019 (unallocated), `.invalid` emails. 8 tests, 4,700+ assertions, including one proving the redactor still catches the generated values. |

## 6. CI and environments (Phase 00 §6)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-06-01 | Client local-encryption compatibility spike across 5 target platforms | mobile, desktop, security | — | `OPEN` | Condition of ADR 0006. No client may ship local clinical storage until closed. |
| G-06-02 | PR pipeline: format, lint, typecheck, architecture rules, contract validation, unit, integration, security scans, SBOM | devops | [pull-request.yaml](../../../.github/workflows/pull-request.yaml) | `PARTIAL` | 7 path-filtered jobs covering all six units plus contracts and security. Written and YAML-valid but **never executed**: there is no GitHub remote, so no run has ever proven it green. |
| G-06-03 | Post-merge: signed immutable artifacts, signed staging deploy, migrations with lock monitoring, smoke checks | devops | [post-merge.yaml](../../../.github/workflows/post-merge.yaml) | `PARTIAL` | Build-once, keyless-sign-by-digest, attest, and scan are written. The staging job deliberately fails rather than reporting a deploy that cannot happen; production promotion is hard-disabled until Phase 23. |
| G-06-04 | All deployment units build reproducibly from locked dependencies | devops | `composer.lock`; `package-lock.json`; `requirements.txt` with hashes (616 lines, `--require-hashes`) | `PARTIAL` | core-api image built from lock, exit 0. Flutter `pubspec.lock` files not yet generated. |
| G-06-05 | Images/artifacts carry SBOMs with no unaccepted critical findings | security | `pull-request.yaml` and `post-merge.yaml` | `PARTIAL` | Syft SBOM generation, Trivy filesystem and image scanning, Gitleaks, and Semgrep with 7 clinic-specific rules are configured and blocking on CRITICAL/HIGH. Unexecuted, as above. |

## 7. Observability (Phase 00 §7)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-07-01 | Propagate traceparent, request/correlation/causation IDs, pseudonymous actor, service/version without PHI | observability | `AssignCorrelationId`; `otel-collector.yaml` | `PARTIAL` | Correlation IDs assigned, echoed, and persisted on every outbox row, proven by test. The OTel SDK is installed but not instrumented, so `traceparent` is not yet propagated. |
| G-07-02 | Instrument request rate/error/latency, DB pool and query latency, Redis errors, queue depth/age/failures, outbox backlog, Reverb connections, provider failures | observability | Partial indexes support backlog and dead-letter queries | `OPEN` | No metrics exported yet |
| G-07-03 | Bounded metric labels; never patient/doctor/appointment/file/prescription/free-text values | observability | `Classification::allowedAsMetricLabel()`; collector `attributes/bound_metric_labels` | `PASS` | Encoded in types and unit-tested, enforced again at the collector, which deletes `patient_id`, `doctor_id`, `appointment_id`, `prescription_id`, `file_id`, and `user_id` if they ever appear |
| G-07-04 | Alerts with owner, severity, threshold, sustain period, runbook, false-positive review | observability | [alerts/platform.yaml](../../../infra/monitoring/alerts/platform.yaml) | `PARTIAL` | 10 rules, every one carrying owner, severity, sustain period, and a runbook link that resolves. 3 runbooks are written in full; 5 are honest stubs, because writing them needs operational experience the platform has not had yet. |
| G-07-05 | Traces/logs from a synthetic clinical-looking request are redacted before leaving the process | observability, security | — | `OPEN` | Depends on G-05-03 canary suite and OTel export |

## 8. Security and privacy (Phase 00 "Security and privacy work")

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-08-01 | STRIDE + privacy threat model across the 7 named trust boundaries | security | [phase-00-foundation.md](../../threat-models/phase-00-foundation.md) | `PARTIAL` | All 7 boundaries analysed with STRIDE, plus every named threat from the phase file and 4 residual risks. **Engineering draft, not independently reviewed** — which is exactly the limitation Phase 22 exists to remove. |
| G-08-02 | Mandatory controls: deny-by-default, request/content-size limits, safe parsers, no wildcard CORS, secure admin headers, secrets withheld from fork jobs, non-root/read-only containers, SBOM provenance, config/flag/secret audit trail, tested redaction, documented emergency rotation | security | `EnforceRequestBounds` (size + JSON depth); `limits.ini`; non-root UIDs 10001/10002; `no-new-privileges`; enumerated CORS origins | `PARTIAL` | Several controls in place. Audit trail, secret-scanning policy, emergency rotation runbook, and SBOM provenance are absent. |
| G-08-03 | Versioned mappings to OWASP ASVS 5.0.0, OWASP API Security, OWASP MASVS/MASTG | security | — | `OPEN` | Not started |
| G-08-04 | Threat model and data classification have security/privacy approval | security, privacy | — | `OPEN` | Requires the named accountable humans; cannot be self-approved |

## 9. Test plan (Phase 00 "Test plan")

| Category | Requirement | Result | Note |
| --- | --- | --- | --- |
| Unit | UUIDv7, money, quantity, Cairo DST, cursor, error mapping, request hashing, retry classifier, redaction | `OPEN` | Subjects implemented; no test files authored |
| Unit | Domain/application dependency tests prove inner layers import no framework code | `OPEN` | Needs `deptrac.yaml` (G-01-05) |
| Unit | Idempotency same/different hash, concurrent processing, expiry, retryable failure | `OPEN` | Logic implemented in `EloquentIdempotencyStore` |
| Unit | Outbox retry capped, jittered, distinguishes permanent failures | `OPEN` | Retry scheduler not yet written |
| Integration | Transaction rollback, outbox atomicity, worker claiming, duplicate consumption, lock expiry, cache loss, migration forward compatibility | `PARTIAL` | Migrations verified against real PostgreSQL; the rest pending |
| Integration | S3 private objects, encryption metadata, signed URL expiry, denied anonymous access | `OPEN` | MinIO in Compose; not exercised |
| Integration | Reverb private-channel authorization scaffold, disconnect behavior | `OPEN` | Reverb installed, not configured |
| Integration | FastAPI stub internal auth, deadline propagation, unavailability isolation | `OPEN` | Health endpoints written; internal contract not implemented |
| Contract | OpenAPI validation + generated clients against a running API | `PARTIAL` | Validation and TS generation pass; not yet run against a live API |
| E2E | Four clients show core health/version in Arabic and English | `OPEN` | ar/en catalogues written; no client consumes them |
| E2E | Committed synthetic event reaches a consumer exactly once despite forced duplicate delivery | `OPEN` | `outbox_event_id` is returned specifically to make this assertable |
| System | Stop AI/Qdrant, flush Redis, kill a worker mid-outbox, roll a compatible schema change, graceful shutdown under load | `OPEN` | — |
| Security | SAST, dependency, image, IaC, license, SBOM, secret scans with blocking severity policy | `OPEN` | Policy defined in ADR 0008; no scanner configured |
| Security | Canary values for national ID, token, password, prescription-like and lab-like text appear nowhere in logs/traces/errors | `OPEN` | Highest-value missing test. `PatternRedactor` is unproven without it. |

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

Defects 2 and 3 are worth noting as a pair: both were framework or regex
defaults that looked correct in review and were only exposed by inspecting what
the database actually did. Neither would have failed a happy-path test.

## Blockers requiring a decision or a human owner

| # | Blocker | Needs |
| --- | --- | --- |
| 1 | CODEOWNERS teams do not exist; branch protection cannot enforce review rules | The five accountable owners named in the Phase 00 entry criteria (clinical, pharmacy, privacy/legal, security, operations) |
| 2 | Threat model and data classification approval | Security and privacy owners. Engineering cannot self-approve. |
| 3 | ADR 0006 encryption spike | Five-platform compatibility results plus key rotation, recovery, backup exclusion, and migration tests |
| 4 | No staging environment | Infrastructure decision and budget |

## Honest summary

Phase 00 now delivers all six deployment units, and each one builds, lints, type-checks, and tests green:

| Unit | Verification |
| --- | --- |
| core-api | 123 tests, 4,919 assertions against real PostgreSQL; deptrac 0 violations / 0 uncovered; Pint clean |
| ai-service | Health contract, startup config validation, isolation assertion |
| admin-web | 5 tests, type-check clean, ESLint clean with type-aware rules, production build |
| 3 Flutter apps + 11 packages | `melos analyze` clean with `--fatal-infos --fatal-warnings`, 20 tests, format clean |

Six oracles have been demonstrated capable of failing: the event validator, the
database CHECK constraints, `deptrac`, the redaction canaries, the
breaking-change detector, and the synthetic-data generator. That last group
matters more than the passing counts. The redaction canary suite **passed a
deliberate sabotage** before it was strengthened, and the synthetic-ID generator
was producing 13-digit values that a weaker test would have accepted.

What is still not true:

- **Nothing in CI has ever run.** Both workflows are written and YAML-valid, but
  there is no GitHub remote, so "CI is configured" is the honest claim, not
  "CI is green".
- **Redaction is proven as a unit, not on the export path.** No test yet follows
  a synthetic clinical request all the way to the collector (G-07-05).
- **`clinic_reporter` can read every table.** Harmless now — no table holds
  personal data — and unacceptable before Phase 01 stores a patient profile.
- **No authorization layer exists.** Every deny-by-default statement in the
  threat model is a design commitment, not running code, until Phase 01.
- **Nothing has been independently reviewed.** The threat model, the data
  classification, and the controls were all written and assessed by the same
  party. Phase 22 exists precisely to remove that.
- **No staging environment, no SBOM has ever been produced, no load test has
  ever been run.**

Phase 00 must not be described as complete. Of the four blockers below, three
need a named human owner and cannot be closed by engineering at all.

**Phase 01 dependency readiness:** the foundation Phase 01 consumes — module
boundaries, contracts, idempotency, outbox, redaction, migrations, and the test
harness — is in place and evidenced. Before Phase 01 stores its first patient
profile it must close G-07-05 (redaction on the export path), narrow the
`clinic_reporter` grant, and have CI actually execute.
