---
name: clinic-pharmacy-integrations
description: Implement external pharmacy connectors, canonical mappings, staged sync generations, freshness, and integrated availability mirrors. Use for Phase 15 adapter/sync work; never for native inventory or bidirectional source mutation.
---

# Clinic Pharmacy Integrations

Integrate approved external pharmacy inventory sources through branch-bound, read-only adapters while keeping native ledgers isolated. Patient-facing data may use only fully promoted, mapped, fresh, public-safe mirror observations.

## Ownership and routing

This skill owns the `PharmacyIntegrations` module: connector configuration and credential references, adapter contracts, product mappings, sync commands/runs/pages, staging generations, canonical validation, promotion, freshness, quarantine/review state, replay/reconciliation, and the integrated implementation of `BranchAvailabilityQuery`.

Vendor adapters read one approved external API, database, export, or signed relay and map it into the canonical contract. `MedicationCatalog` owns medication identity. `PharmacyOrganizations` owns branch mode/status and connector-service authorization. `MedicineDiscovery` owns the patient projection. Native `Inventory`, `Purchasing`, and `POS` remain separate and receive no integration movements.

Do not turn this skill into a generic ETL engine, arbitrary SQL connector, remote POS, supplier integration, or write-back system.

## Required phase sources

Always read:

- [Phase 00](../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md) for ports, queues, outbox, idempotency, secrets, and module boundaries;
- [Phase 10](../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md) for catalog identity, branch authorization, mode/version, and connector-service capability;
- [Phase 14](../../docs/phases/14_medicine_search_and_prescription_fulfillment.md) for the public availability/freshness contract;
- [Phase 15](../../docs/phases/15_external_pharmacy_integrations.md) for the complete adapter, mapping, sync, schema, security, and rollout specification.

For production work also read [Phase 21](../../docs/phases/21_performance_scaling_observability_and_resilience.md), [Phase 22](../../docs/phases/22_security_privacy_and_compliance_validation.md), and [Phase 23](../../docs/phases/23_disaster_recovery_release_and_production.md). Read [Phase 18](../../docs/phases/18_pharmacy_ai.md) only when exposing the same safe availability port to Pharmacy AI; AI does not gain connector access.

## Hard integration boundaries

- A branch is either `NATIVE` or `INTEGRATED`. An active connector can promote only for its bound active `INTEGRATED` branch. A `NATIVE` branch rejects mirror writes; an `INTEGRATED` branch rejects native inventory, purchasing, and POS writes.
- Branch scope comes from server-owned connector/workload identity and binding. A request, page, cursor, record, mapping, or relay cannot override branch, connector, host, database, table, query, or credential identity.
- Use a separate adapter per vendor/source schema behind `ExternalPharmacyConnector`. Adapters return typed transient, permanent-auth, schema, mapping, timeout, cancellation, and source-unavailable failures; provider types do not escape Infrastructure.
- External observations are absolute quantities/statuses with stable source identity/version/hash. Replay replaces the same canonical record; it never applies an additive quantity delta to either mirror or native ledger.
- Full-sync pages are invisible in staging until the complete generation validates and atomically promotes. Same run/page/hash is a no-op; same sequence with a different hash fails. The prior generation remains active on any partial failure.
- Incremental checkpoints advance only after the page transaction commits. Silence is not deletion; require an explicit tombstone or documented complete-snapshot absence rule. Use full generations when safe incremental semantics cannot be proven.
- Unmatched, ignored, retired, malformed, quarantined, or stale records never become confidently patient-visible. `last_successful_sync_at` advances only after promotion/committed checkpoint.
- Patient projections omit external IDs/vendor, exact quantity, price, cost, raw source state, and connector errors. Stale data is excluded or shown only with the approved uncertainty wording.
- Mode transition is a privileged coordinator: pause new native/connector writes, drain or cancel in-flight work, reconcile the chosen source, compare-and-set branch mode/version and connector config, audit, and rotate/disable credentials. Never implement it as generic branch PATCH.

## Adapter and security constraints

- Prefer bounded HTTPS APIs. If an external database is approved, the adapter owns fixed parameterized allowlisted statements and uses read-only credentials. Never accept configurable SQL, table/column names, paths, arbitrary endpoints, executable expressions, or payload-driven URLs.
- Store only a secret-manager reference and version in PostgreSQL. Use per-connector/branch identity, TLS/mTLS, certificate validation, fixed egress allowlists, rotation/revocation, and access audit. Never return or log plaintext credentials.
- A store-side relay, if approved, is a separately reviewed signed HTTPS client with short-lived workload identity, nonce/timestamp replay defense, branch binding, resource limits, and no Laravel database credentials.
- Bound deadlines, pages, total bytes, records, strings, IDs, cursors, versions, nesting, quantities, timestamps, decompression, retries, and concurrency. Quarantine malformed payload references and expose only safe error codes.
- Connector jobs run on the Laravel Horizon `integrations` lane and carry IDs/configuration version/deadline, not credentials or raw pages. This is not a Python/AI queue.

Use Laravel 13 HTTP client/Guzzle, isolated PDO drivers only for approved fixed DB adapters, PostgreSQL staging/mirror tables, Horizon, outbox, OpenTelemetry/Sentry redaction, `deptrac/deptrac`, Larastan/PHPStan, Pest/PHPUnit, Eris, and Testcontainers/Docker vendor fixtures. Pin selected adapters and drivers in lockfiles.

## Implementation workflow

1. Read Phases 10, 14, and 15. Confirm the source type, branch binding, authority direction, update semantics, freshness threshold, mapping owner, security approval, and rollback behavior. Reject unspecified bidirectional or arbitrary-source requirements.
2. Define the canonical source record and typed errors before adapter code. Include schema version, run/page identity, cursors, last-page marker, external product identity/version, observed time, absolute smallest-unit quantity when trustworthy, explicit availability, and tombstone.
3. Implement the adapter behind fixed credentials/egress and make it pass the common contract suite. Keep source normalization separate from mapping and persistence.
4. Implement idempotent run/page staging, mapping exclusion, count/hash/schema validation, and a short promotion transaction that locks connector/configuration, rechecks branch mode/status/version, swaps the complete generation, advances freshness/cursor, and writes audit/outbox.
5. Classify failures. Retry bounded transient timeouts/eligible 429/5xx with jitter; pause and alert on auth/schema/configuration/permanent failures. A timeout or worker crash is reconciled from committed run/page state before another read or promotion.
6. Expose only the Phase 14 safe availability port. Cache by source generation/freshness with bounded TTL; database freshness checks and active-generation identity remain authoritative.
7. Roll out disabled, then probe/dry-read against synthetic/vendor-approved fixtures, stage without promotion, reconcile/map, transition one allowlisted branch, and enable public reads only after accuracy/security sign-off. Rollback marks availability uncertain and pauses the connector; it never silently falls back to native stock.

## Observable verification

Every adapter and sync change must produce evidence that:

- the shared connector contract suite passes paging, deadlines, cancellation, typed errors, redaction, stable identity, replay, schema drift, malformed/oversized input, and source restart;
- property tests reorder, duplicate, omit, corrupt, and replay pages/records while preserving one deterministic mirror, no additive quantity, monotonic successful freshness, and no partial visibility;
- real PostgreSQL tests prove atomic generation promotion, committed incremental checkpoints, concurrent-run exclusion, configuration/mode change rejection, mapping races, and prior-generation survival;
- Redis/Horizon restart, worker death, timeout, dead letter, replay, and credential rotation resume from committed state without duplicate or mixed generations;
- unmatched/retired/stale records remain absent or uncertain in the patient view, and native ledger rows/movements remain unchanged;
- SSRF, SQL injection, path traversal, malicious Unicode/formula strings, relay forgery/replay, BOLA/BFLA, cross-tenant cache/mapping, decompression bombs, and secret/log canaries fail safely;
- production-shaped full/incremental sync respects source rate limits and backpressure without violating Phase 14 search latency targets;
- backup/restore followed by rebuild/re-sync/reconciliation produces the same promoted public mirror.

Report the adapter and bound branch/source, canonical schema version, credential/egress model, run/page/promotion semantics, freshness policy, contract/security/load tests, rollout state, and any vendor assumption not yet proven.
