---
name: clinic-test-engineering
description: Design, implement, or repair this clinic project's automated test architecture, fixtures, reusable load-test harnesses, contract suites, end-to-end/system tests, and phase evidence. Use for test mechanics, flaky-test diagnosis, invariant coverage, or evidence quality; AI evaluation gates belong to clinic-ai-evaluation-governance, while k6 execution, capacity, and SLO conclusions belong to clinic-observability-performance. Not for business/security policy or independent assurance.
---

# Clinic Test Engineering

Build tests that can falsify roadmap claims across all stacks and failure modes. Preserve independent oracles: domain and security owners define required invariants, while this skill owns how they are exercised repeatably and evidenced.

## Read the required sources

Read completely before designing or changing tests:

- [Roadmap invariants, phase map, and evidence policy](../../../docs/phases/README.md)
- [Cross-cutting architecture, packages, CI, and test contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The active phase file and every dependency contract exercised by the test

For performance/failure testing also read [Phase 21](../../../docs/phases/21_performance_scaling_observability_and_resilience.md). For security-adversarial coverage read the authorized scope in [Phase 22](../../../docs/phases/22_security_privacy_and_compliance_validation.md). For restore/release drills read [Phase 23](../../../docs/phases/23_disaster_recovery_release_and_production.md). Inspect current test conventions, factories, schemas, generated clients, CI jobs, prior results, known flakes, and local changes.

## Ownership and independence

Own:

- requirement-to-test traceability, layer selection, deterministic oracles, fixtures/factories, test-data builders, clocks/IDs/provider stubs, and cleanup;
- Laravel, Flutter mobile, Electron desktop, React admin web, Python, OpenAPI/event/tool, cross-service, E2E, system, resilience, and k6 harness mechanics;
- race/idempotency/replay/failure-injection scenarios, accessibility/localization automation, sharding, reports, and evidence manifests;
- flake diagnosis and test reliability budgets without weakening assertions;
- proof that an important test fails when its protected invariant is deliberately violated in a safe test seam.

The owning domain/architecture skill specifies behavior and state meaning. PostgreSQL/realtime/file/AI/client skills supply faithful seams and fixtures. Security/privacy assurance owns threat selection, rules of engagement, finding severity/closure, risk exceptions, and release recommendation. This skill may implement an authorized security regression but cannot mark a security finding closed or convert an unreviewed legal/clinical requirement into a passing oracle.

Do not alter production policy merely to make a test easy. Request a narrow seam—clock, ID generator, port, fault injector, or observer—when observability is missing.

## Test invariants

1. Every critical workflow proves allowed and denied behavior. A happy-path demonstration is insufficient.
2. Exercise the real lowest layer that owns the claim: pure logic in unit tests, database truth in real PostgreSQL integration tests, contracts at producer/consumer boundaries, and journeys in E2E/system tests.
3. Tests are deterministic under controlled time, randomness, identifiers, locale, timezone, provider responses, and concurrency coordination. Do not use arbitrary sleeps or retries to hide races.
4. Use synthetic data only. Never copy production databases, National IDs, phones, credentials, medical text/files, prompts, pharmacy data, or payment data into fixtures, snapshots, reports, or CI logs.
5. Factories create valid minimal entities by default. Invalid, cross-tenant, stale, expired, duplicate, or unauthorized states must be explicit and readable.
6. Assert durable outcomes and forbidden side effects: rows/versions, constraints, audit/outbox, recipient scope, stock/money reconciliation, object state, and absence of leakage—not controller method calls or incidental implementation details.
7. Critical writes cover same-key replay, changed-payload mismatch, ambiguous timeout, concurrent distinct keys, crash/retry, reordered/duplicate events, and reconciliation.
8. No test may turn Redis, client cache, Qdrant, analytics, or a mock into medical/operational truth. Use real authoritative components where the assertion depends on them.
9. A flaky test is a defect. Quarantine requires a tracked owner, reason, reproducer/evidence, expiry, and preserved gate visibility; blanket retries do not count as a fix.
10. Evidence is tied to the exact code/artifact/config, environment, seed, command, tool version, time, and result. A screenshot or “tests passed” statement alone is not phase evidence.

## Stack and harness contract

Use the locked Phase 00 choices and existing repository convention:

- Laravel: one repository-standard Pest or PHPUnit runner, Laravel HTTP/database fakes only where faithful, and Mockery at external ports rather than internal implementation details.
- Flutter patient mobile: `flutter_test`, widget/golden tests, repository tests, `integration_test`, Android/iOS secure-storage/encrypted-database compatibility, and generated Dart client checks.
- Electron doctor/pharmacy desktop: Vitest, React Testing Library, MSW in Node mode, typed preload/IPC integration tests, WebdriverIO with `@wdio/electron-service` against packaged artifacts, axe-core, native-module/encrypted-database/utility-process compatibility, and install/update tests. Playwright Electron requires the Phase 00 experimental-launcher compatibility gate.
- React admin web: Vitest, React Testing Library, MSW, browser Playwright, and axe-core integration.
- Python/AI: `pytest`, `pytest-asyncio`, `respx`, Hypothesis, provider-contract fixtures, and versioned evaluation datasets; model judges never decide correctness alone.
- Contracts/system: generated OpenAPI clients, JSON/event/tool schema checks, approved schema/property fuzzing, real PostgreSQL/PostGIS, Redis, Reverb, private S3 emulator, Qdrant for AI scope, and k6 for HTTP/WebSocket/load scenarios.

Do not introduce a second runner, floating dependency, deprecated library, or record/replay fixture containing secrets/sensitive bodies without an ADR-backed need.

## Layered test model

### Unit

Prove pure policies, state machines, reducers, serializers, calculations, redactors, validators, filters, and retry classifiers. Use table/property tests for state/action/actor combinations, Arabic/Unicode inputs, Cairo/UTC boundaries, integer money, exact quantities, and bounded outputs.

### Integration

Prove adapter and authoritative-store behavior with real local/ephemeral dependencies: migrations/constraints/index predicates, transaction isolation/locks, outbox commit, cache loss, queue replay, private channels, S3 quarantine, Qdrant scope filters, provider timeouts, encrypted local persistence, and key/session revocation.

### Contract

Prove OpenAPI-generated PHP, Dart patient, TypeScript Electron/admin, and Python compatibility plus event, job, IPC, AI tool, provider, and connector schemas. Test compatible evolution, unknown/removed fields, version handling, typed denial/timeouts, idempotency, size limits, and safe errors. Consumers must not infer privilege from a new field.

### End to end

Exercise user-visible critical journeys across the real deployed test stack and approved stubs. Include direct unauthorized requests, duplicate taps, session expiry, cancellation, offline/reconnect, stale realtime sequence, localized/RTL rendering, accessibility, and ambiguous writes—not only UI button visibility. Electron journeys launch the packaged process model and verify main/preload/renderer boundaries rather than treating the renderer as an ordinary browser page.

### System and resilience

Exercise cross-service degradation, restart/replay/recovery, source-of-truth reconciliation, capacity thresholds, failover where scoped, and core independence from AI/providers. Validate invariant preservation during failure as well as eventual recovery.

### Security regression

Automate bounded regression cases supplied by security/privacy assurance: BOLA/BFLA/property authorization, state abuse, CSRF/XSS/SSRF/parser limits, tenant leakage, signed access, prompt/tool injection boundaries, session/secret redaction, and resource limits. Broader DAST/pentest execution and finding disposition remain with the security skill and written rules of engagement.

## Workflow

### 1. Build a coverage matrix

For each phase requirement/gate create:

```text
test_id | source/invariant | risk | layer | preconditions/data
action/fault | oracle + forbidden effects | owner | command/evidence
```

Prioritize identity/authorization, consultation grants, finalized versions, files, tenant separation, inventory/financial ledgers, idempotency, AI scopes/tools/red flags, recovery, and V1 exclusions. Assign every claim to the lowest conclusive layer, then add broader coverage only for integration risk.

### 2. Design deterministic data and seams

Use per-test tenants/actors/resources, transaction or namespace isolation, fixed UTC clocks with `Africa/Cairo` conversions, deterministic IDs/seeds, bounded fake providers, and explicit cleanup. Coordinate races with barriers/latches/advisory hooks or database sessions; do not depend on scheduler timing.

Model external responses as typed success, denial, timeout, disconnect, rate limit, malformed output, stale callback, and retryable/permanent failure. Ensure fakes obey the same contract as production adapters.

### 3. Implement the smallest conclusive proof

Make assertions at public/domain boundaries. Include a negative control or mutation check for high-risk or newly built harnesses so a false-positive suite is exposed. Avoid snapshotting sensitive or volatile output and avoid tests that merely match documentation wording, generated headings, log text, or internal call order.

### 4. Run and diagnose

Run focused tests, repeat race/flake-sensitive cases under varied seeds/workers, then affected package/application suites, contract/generated-client checks, E2E/system/security subsets, and CI-equivalent commands. Diagnose failures from artifacts and state; never raise timeouts or add retries without evidence that the system contract allows the delay.

### 5. Publish evidence

Store machine-readable results and a safe summary with artifact/config hashes. Record skipped/quarantined/not-run cases as gaps, not passes. Redact payloads and retain only the minimum evidence under the approved policy.

## Required cross-domain scenarios

At minimum, preserve coverage for:

- duplicate National-ID matching without enumeration and cross-account/profile attachment races;
- booking overlap, consultation start/end grant atomicity, and denial before start/after end;
- clinical draft conflict and append-only finalized prescription behavior before/after exposure;
- file quarantine/scan failure, signed access expiry, wrong actor/resource, and parser limits;
- private realtime subscription plus gap/reconnect snapshot recovery;
- stock ledger/FEFO/expiry, partial receipt, double sale/cancel/refund, integer money, and reconciliation;
- connector stale/unmapped/replayed data without authoritative corruption;
- AI tenant/specialty/visit scope, prompt injection, tool allowlist/grant, deterministic red flags, confirmation, provider failure, and core availability;
- admin safe projections, no PHI/raw infrastructure, suppression/freshness/unknown states;
- restore/replay/rebuild preserving PostgreSQL/S3 truth and never treating Redis/Qdrant as authoritative.

## Scope and authorization limits

- Do not run load, DAST, penetration, failover, restore, destructive migration, external provider, or production tests without explicit environment/scope authorization and stop conditions.
- Never send SMS/push/email, charge a terminal/provider, upload hostile content externally, or target a third party as an incidental test step.
- Do not suppress, skip, loosen, or rewrite an invariant because implementation currently fails it.
- Do not claim coverage percentage, passing automation, OWASP/NIST mapping, or a green CI job proves security, compliance, legal sufficiency, clinical validity, or production readiness.

## Completion evidence

Return the risks and newly proven behavior first. List changed harnesses/tests, exact commands and results, negative-control outcome, coverage-matrix updates, flakes/skips/gaps, environment/tool versions, and evidence locations. State which independent domain, security/privacy, clinical/legal/pharmacy, performance, or release review remains.
