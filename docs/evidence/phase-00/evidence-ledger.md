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
| G-02-01 | Each deployment unit scaffolded without feature code | all stacks | `apps/core-api` (Laravel 13.26.1), `apps/ai-service` (FastAPI) | `PARTIAL` | admin-web and the three Flutter apps not yet scaffolded |
| G-02-02 | `/live`, `/ready`, build/version metadata, structured errors, correlation IDs, graceful shutdown | backend, AI | `OperationalController`, `PlatformHealthController`, `clinic_ai/api/health.py`, `AssignCorrelationId` | `PARTIAL` | Code written; not yet exercised against a running container. Graceful shutdown under load is untested. |
| G-02-03 | Liveness checks only process health; readiness checks critical startup state without making every optional dependency a core outage | backend | `ReadinessProbe` critical/optional split; `/live` performs no dependency check | `PARTIAL` | Logic implemented and reviewed; runtime assertion pending |
| G-02-04 | AI/Qdrant readiness failure does not make Laravel core unready | backend, AI | `phpunit --filter ReadinessIsolationTest` | `PASS` (unit) | 6 tests, 13 assertions. Proves ready-with-AI-degraded, ready-with-optional-hard-fail, unready-on-critical-fail, and configurable criticality. The container-level system test (actually stopping the AI service) is still outstanding. |
| G-02-05 | Octane workers do not leak request-scoped state; regression test with two synthetic identities | backend | `CorrelationIdProvider` with explicit `reset()` | `OPEN` | Octane request hooks not yet wired; the two-identity test does not exist. This is the highest-risk open item in this section — a leak here crosses patients. |

## 3. Contract workflow (Phase 00 §3)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-03-01 | Minimal OpenAPI with health endpoints and common envelope/error schemas | contracts | [openapi.yaml](../../../packages/contracts/openapi/openapi.yaml) | `PASS` | — |
| G-03-02 | Validated and linted in CI; Dart and TypeScript test clients generated | contracts | `npx redocly lint` → "valid, 0 warnings"; `npx openapi-typescript` → 660-line `schema.d.ts` | `PARTIAL` | TypeScript generation proven. Dart client generation not yet run. Neither runs in CI yet. |
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
| G-04-04 | Generic outbox and idempotency storage with cleanup/retention jobs | platform, postgresql | `outbox_events` + `idempotency_keys` tables; `EloquentOutboxRecorder`, `EloquentIdempotencyStore` | `PARTIAL` | Storage and adapters done. Retention/cleanup scheduled jobs not yet written. |
| G-04-05 | Redis namespaces/connections for cache, rate limit, queue, realtime even when local Compose uses one instance | platform | 4 named connections in `config/database.php` (`cache`, `queue`, `realtime`, `ratelimit`) | `PASS` | Production separation is configuration only |
| G-04-06 | Redis flush loses no authoritative record; app can warm required caches | platform | — | `OPEN` | Needs the flush-Redis system test |
| G-04-07 | Database constraints reject invalid state (negative proof) | postgresql | Direct SQL negative tests against the live schema | `PASS` | See "Negative test results" below |

## 5. Data protection (Phase 00 §5)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-05-01 | Classification levels defined: public, internal, personal, sensitive, credential | privacy | `Classification` enum with telemetry/label/cache rules | `PARTIAL` | The enum exists; `docs/data-classification/classification-policy.md` is not yet written |
| G-05-02 | Per table/event/log/metric: classification, purpose, lawful basis, access roles, retention, encryption, deletion owner | privacy | — | `OPEN` | Data inventory not started |
| G-05-03 | Logging redaction processor with canary tests for national IDs, phones, tokens, passwords, clinical text, object keys | platform, security | `phpunit --filter RedactionCanaryTest` | `PASS` (unit) | 41 tests, 67 assertions. Asserts the *specific* rule fires per canary, not merely that the value vanished — see defect 6. Also asserts ordinary operational values survive, so a scrub-everything implementation fails. Export-path integration (G-07-05) still outstanding. |
| G-05-04 | TLS on non-local hops; private networking for DB/Redis/Qdrant; private object storage; encrypted volumes | devops | Compose binds every port to `127.0.0.1` only | `PARTIAL` | Local posture correct. Staging TLS and network policy not configured. |
| G-05-05 | Synthetic Egyptian-format data generators that cannot produce known real identities | platform | — | `OPEN` | Not started |

## 6. CI and environments (Phase 00 §6)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-06-01 | Client local-encryption compatibility spike across 5 target platforms | mobile, desktop, security | — | `OPEN` | Condition of ADR 0006. No client may ship local clinical storage until closed. |
| G-06-02 | PR pipeline: format, lint, typecheck, architecture rules, contract validation, unit, integration, security scans, SBOM | devops | — | `OPEN` | `.github/workflows/` is empty |
| G-06-03 | Post-merge: signed immutable artifacts, staging deploy, migrations with lock monitoring, smoke/contract/authorization-canary checks | devops | — | `OPEN` | No staging environment exists |
| G-06-04 | All deployment units build reproducibly from locked dependencies | devops | `composer.lock`; `package-lock.json`; `requirements.txt` with hashes (616 lines, `--require-hashes`) | `PARTIAL` | core-api image built from lock, exit 0. Flutter `pubspec.lock` files not yet generated. |
| G-06-05 | Images/artifacts carry SBOMs with no unaccepted critical findings | security | — | `OPEN` | No SBOM generation or scanning configured |

## 7. Observability (Phase 00 §7)

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-07-01 | Propagate traceparent, request/correlation/causation IDs, pseudonymous actor, service/version without PHI | observability | `AssignCorrelationId`; `correlation_id` + `causation_id` on every outbox row | `PARTIAL` | Correlation implemented. `traceparent` propagation and OTel wiring not done. |
| G-07-02 | Instrument request rate/error/latency, DB pool and query latency, Redis errors, queue depth/age/failures, outbox backlog, Reverb connections, provider failures | observability | Partial indexes support backlog and dead-letter queries | `OPEN` | No metrics exported yet |
| G-07-03 | Bounded metric labels; never patient/doctor/appointment/file/prescription/free-text values | observability | `Classification::allowedAsMetricLabel()`; readiness check names drawn from a fixed set | `PARTIAL` | Rule encoded in types; no automated label-cardinality check |
| G-07-04 | Alerts with owner, severity, threshold, sustain period, runbook, false-positive review | observability | — | `OPEN` | `infra/monitoring/` referenced by Compose but not authored |
| G-07-05 | Traces/logs from a synthetic clinical-looking request are redacted before leaving the process | observability, security | — | `OPEN` | Depends on G-05-03 canary suite and OTel export |

## 8. Security and privacy (Phase 00 "Security and privacy work")

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-08-01 | STRIDE + privacy threat model across the 7 named trust boundaries | security | Boundaries and controls tabulated in [c4-context.md](../../architecture/c4-context.md) | `PARTIAL` | Boundary table exists; the threat model with per-threat mitigations and abuse tests is not written |
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

The foundation is real and partly proven: PostgreSQL with least-privilege roles
and constraint-enforced invariants, a validated contract that generates a typed
client, an outbox and idempotency design with the concurrency control in the
database where it belongs, and two independent oracles demonstrated capable of
failing.

69 unit tests with 573 assertions now run green, and four independent oracles
have been demonstrated capable of failing: the event validator, the database
CHECK constraints, `deptrac`, and the redaction canaries. That last one is worth
dwelling on — the canary suite passed a deliberate sabotage before it was
strengthened, which means an unstrengthened version of it would have provided
false assurance about the one control standing between clinical content and a
log file.

What is not proven remains larger than what is. Octane request-scope isolation
is unwired (G-02-05), and that is the one open item whose failure mode crosses
patients rather than merely breaking a build. Redaction is proven as a unit but
not on the actual telemetry export path (G-07-05). Three of the four clients do
not exist. CI does not run, so none of these tests gate anything yet. There is
no threat model, no data inventory, and no security scanning.

Phase 00 must not be described as complete, and Phase 01 is not
dependency-ready: it needs G-02-05, G-06-02, and G-07-05 closed first.
