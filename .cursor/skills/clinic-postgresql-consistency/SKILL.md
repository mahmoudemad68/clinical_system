---
name: clinic-postgresql-consistency
description: Design, migrate, tune, or test PostgreSQL/PostGIS persistence and transactional consistency for clinic Core data, including physical query/index/schema changes. Use for constraints, locks, idempotency, outbox, indexes, query plans, or safe migrations; performance workload measurement, capacity, and SLO conclusions belong to clinic-observability-performance. Not for Redis or Qdrant.
---

# Clinic PostgreSQL Consistency

Make PostgreSQL enforce the clinic platform's authoritative invariants under retries, concurrency, partial failure, and rolling deployment. Redis may reduce contention or latency but never substitutes for a database constraint or transaction.

## Ownership and routing

This skill owns the physical relational design, migration safety, database roles, constraints, indexes, isolation/locking strategy, idempotency/outbox/inbox persistence, query-plan evidence, reconciliation queries, retention mechanics, and database recovery compatibility.

The active business-area skill defines business meaning and allowed transitions. Laravel module services own workflow coordination; realtime owns delivery; integrations own canonical sync semantics; AI owns Qdrant. This skill translates approved invariants into PostgreSQL and proves they hold. Do not introduce a schema-level workflow that bypasses the owning module service.

## Required phase sources

Always read [Phase 00](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md), then the exact active phase. Pay particular attention to:

- identity and care consistency in [Phase 01](../../../docs/phases/01_auth_identity_and_access.md), [Phase 02](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md), [Phase 03](../../../docs/phases/03_scheduling_availability_and_booking.md), [Phase 04](../../../docs/phases/04_realtime_queue_and_consultation_control.md), [Phase 05](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md), [Phase 06](../../../docs/phases/06_prescriptions_reminders_and_printing.md), [Phase 07](../../../docs/phases/07_labs_files_reports_and_referrals.md), [Phase 08](../../../docs/phases/08_patient_experience_discovery_reviews_and_localization.md), and [Phase 09](../../../docs/phases/09_notifications_and_post_visit_chat.md);
- pharmacy ledgers and mirrors in [Phase 10](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), [Phase 11](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md), [Phase 12](../../../docs/phases/12_purchasing_and_goods_receipt.md), [Phase 13](../../../docs/phases/13_pos_invoices_returns_and_refunds.md), [Phase 14](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), and [Phase 15](../../../docs/phases/15_external_pharmacy_integrations.md);
- AI metadata and isolation in [Phase 16](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md), operational projections in [Phase 20](../../../docs/phases/20_admin_analytics_and_system_health.md), performance in [Phase 21](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), assurance in [Phase 22](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and recovery in [Phase 23](../../../docs/phases/23_disaster_recovery_release_and_production.md).

## Authoritative conventions

- Use PostgreSQL/PostGIS in implementation and integration tests. SQLite cannot validate the required locking, partial/exclusion indexes, geography, constraints, or transaction behavior.
- Use UUIDv7 identifiers, `timestamptz` for instants, `date` for date-only facts, IANA time-zone identifiers for scheduling intent, `bigint` smallest-unit quantities, and `bigint` integer-minor-unit money with currency. Never use binary floating point for stock or finance.
- Model filtered, joined, authorized, or invariant-bearing facts as typed relational columns. Use JSONB only for bounded extensible metadata with a documented schema and size limit.
- Every mutable record or workflow that can lose concurrent updates uses a version/compare-and-set condition. A preflight service check is not a concurrency guarantee.
- Enforce identity and scope with foreign keys, unique/partial unique constraints, checks, and exclusion constraints where applicable. Eloquent/query-builder predicates must include the authorized tenant/branch even when IDs are globally unique.
- Strong consistency covers access grants, appointments, encounters, prescriptions, payments, invoices, movements, receipts, returns, and refunds. Notifications, analytics, search projections, caches, external mirrors, and AI indexes are eventual and repairable.
- Insert authoritative changes, audit references, idempotency state, and outbox rows in one transaction. Outbox claiming may use `FOR UPDATE SKIP LOCKED`; interactive module service calls must use the locking strategy specified by their phase.
- Acquire multiple rows in a deterministic stable-ID order. Bound deadlock/serialization retries around the entire idempotent coordinating service call, not an arbitrary inner statement.
- Least-privilege application, migration, reporting, backup, and AI-adjacent roles remain separate. FastAPI has no Core database credential or network path.

## Business-critical database invariants

- Booking uniqueness and consultation access must survive concurrent requests; only the approved coordinating service creates/revokes the access grant.
- Completed clinical facts and published prescription versions are immutable. Corrections/amendments append history rather than overwriting exposed content.
- Post-visit chat has one thread per completed encounter, deterministic per-thread ordinals, retry uniqueness by client message ID, and a server-authored `writable_until`; authorization checks the timestamp even if the expiry job is late.
- Catalog packaging conversions are exact positive integers to one smallest tracked unit. Referenced catalog rows retire rather than delete or change identity.
- Native inventory changes only through immutable source-bound movements. Balances never go negative and reconcile to valid movements; FEFO locks eligible batches in expiry/received/ID order.
- A posted receipt and its inventory batches/movements commit together. A sale/cancellation/return/refund and all linked FEFO/reversal movements commit together.
- `NATIVE` branches reject integration mirror writes; `INTEGRATED` branches reject native inventory, purchasing, and POS writes. Recheck mode/version inside every committing transaction.
- Full external sync pages remain in staging until one complete generation validates and atomically promotes. Replays replace absolute observations; they never add quantities.
- Qdrant is a derived, rebuildable index. Knowledge-source and activation metadata live behind AI-owned services, and Core tables are never queried directly by AI workers.

## Migration and query workflow

1. Read the active phase and inspect current migrations, schema, constraints, Eloquent/query-builder queries, data volume assumptions, and production compatibility window.
2. Write the invariant and expected concurrent interleaving before choosing an isolation/locking mechanism. Prefer a database constraint plus a clear conflict mapping over application-only checks.
3. Design tables, keys, checks, foreign keys, unique/partial/exclusion constraints, and indexes together. Every index must name the query/order/filter it serves; avoid speculative indexes and premature partitioning.
4. Implement changes as expand -> deploy compatible code -> resumable bounded backfill -> validate -> switch reads/writes -> contract later. Use lock/statement timeouts and avoid unbounded rewrites on high-volume tables.
5. Make backfills and cleanup jobs checkpointed, idempotent, observable, and safe to stop. Never seed or copy raw production medical data into development or staging.
6. Add reconciliation queries for ledgers, projections, outbox/inbox, sync generations, and other derived state. A mismatch fails closed for the affected write path and raises an operations signal; it is not silently repaired without an approved rule.
7. Capture query plans on production-shaped synthetic data. Treat Phase 21 latency/capacity numbers as measured gates, not guarantees inferred from an index existing.

## Observable verification

Provide evidence using the repository's discovered commands and a real supported PostgreSQL/PostGIS version:

- migrate a clean database, upgrade a prior compatible schema, run the backfill twice, and exercise mixed old/new application versions where rollout requires it;
- prove constraints with negative fixtures and concurrent sessions, including duplicate booking, stale record version, duplicate idempotency key, chat ordinal, FEFO oversell, receipt race, over-return/refund, wrong branch mode, and sync-generation promotion as applicable;
- force deadlock/serialization failure and an unknown client outcome; the whole coordinating service call retries/reconciles without partial writes or duplicate effects;
- roll back an uncommitted coordinating service call and show no orphan audit, idempotency success, outbox, invoice, movement, receipt, or access-grant row;
- run `EXPLAIN (ANALYZE, BUFFERS)` or the repository-approved equivalent on production-shaped synthetic queries and retain bounded plan/latency evidence without PHI;
- flush Redis and restart workers; PostgreSQL truth and reconciliation remain correct;
- verify application/migration/reporting/backup credentials cannot exceed their documented grants and FastAPI cannot connect;
- restore database evidence required by Phase 23 and re-run invariant/reconciliation checks.

Report migrations and constraints added, transaction/isolation choices, indexes with served queries, forward/rollback strategy, measured plans, concurrency tests, and any invariant that still relies only on application code.
