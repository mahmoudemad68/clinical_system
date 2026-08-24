# Phase 11 — Inventory, Batches, FEFO, and Alerts

## Objective

Deliver branch-scoped native inventory with batch/expiry traceability, an immutable stock ledger, transactionally maintained balances, deterministic FEFO allocation, low-stock alerts, and configurable expiry alerts.

The ledger is authoritative for stock changes. A balance is a rebuildable, transactionally updated projection used for fast reads. Redis caches and distributed locks may improve performance but never determine whether stock exists or a sale is valid.

## Plan traceability

- Sections 50-55, lines 1666-1823: smallest tracked units, per-branch batches, FEFO, immutable movements, low-stock thresholds, and expiry jobs.
- Sections 58-60, lines 1893-1964: later sales/cancellation/return movements this phase must support.
- Sections 61-62, lines 1966-2017: owner branch visibility and NATIVE versus INTEGRATED truth.
- Sections 67-70, lines 2113-2211: availability queries and PostgreSQL search/indexing foundation.
- Sections 107-114, lines 3081-3303: idempotency, stock tables/indexes, Redis locks, and Redis separation.
- Sections 117, 119-122, lines 3346-3467: isolation, rate limits, audit, tamper evidence, and redacted logs.
- Sections 132-139, lines 3640-3833: POS/search performance targets and PostgreSQL/connection scaling.
- Sections 159-162, lines 4254-4330: FEFO/idempotency correctness and load/stress testing.
- Sections 172-174, lines 4522-4622: source-of-truth, strong consistency for movements, and background-work boundaries.

## Entry criteria and dependencies

- Phase 10 provides active medication references, exact packaging-to-smallest-unit conversion, organizations/branches, strict roles/capabilities, and operating modes.
- Phase 00 provides transaction/idempotency/outbox/audit conventions, PostgreSQL, Horizon, observability, and security test harnesses.
- Pharmacy owners approve low-stock and expiry threshold defaults and who may adjust stock.
- Legal/accounting owners approve required retention for movement, cost, reason, and actor records.

## Non-goals

- No purchasing workflow, POS invoice, return/refund UI, branch transfer, reservation, online payment, or external mirror sync.
- No full offline pharmacy ERP or offline sales.
- No automated supplier ordering.
- No floating-point stock, negative inventory, sale of expired batches, or direct quantity decrement.
- No branch-to-branch transfer movement type in V1.
- No price exposure to patients.

## Architecture, ownership, and SOLID boundaries

### Ownership

    Inventory module
      stock batches, immutable movements, balances,
      allocation policy, thresholds, stock/expiry alerts, reconciliation

    MedicationCatalog
      stable medication and smallest-unit references only

    PharmacyOrganizations
      branch status, operating mode, actor capability

    Purchasing and POS
      future coordinators invoke Inventory command ports
      inside shared PostgreSQL transactions

### Ports

    InventoryCommandPort
      registerBatch(command, transaction_context)
      appendMovement(command, transaction_context)
      allocateFefo(command, transaction_context)
      reverseMovements(command, transaction_context)

    InventoryQueryPort
      availableByMedication(branch_id, medication_ids)
      batchBalances(branch_id, medication_id)

    InventoryAuthorization
      require(branch_context, capability, native_mode)

    StockAlertPolicy
    StockMovementRepository
    StockBalanceRepository
    Clock

- Inventory owns movement semantics; callers cannot insert signed quantities or movement types directly.
- Allocation returns batch-level movement drafts bound to the same transaction, not mutable batch models.
- The low-stock/expiry evaluators are pure policies. Jobs only select candidates and apply idempotent state transitions.
- A future integrated mirror implements InventoryAvailabilityQuery separately; it never writes native ledger tables.

## Packages and runtime components

- Laravel 13, PostgreSQL, Horizon, Redis, outbox, audit, OpenTelemetry, and Sentry foundations.
- brick/money for batch unit cost in integer minor units/currency.
- Laravel UUIDv7/Symfony UID and injected clock.
- deptrac/deptrac, Larastan/PHPStan, Pest/PHPUnit, and Eris for movement/allocation property tests.
- Pharmacy Flutter uses Riverpod, Dio, generated OpenAPI models, Freezed mappings, barcode adapter, localization, and bounded local catalog caching; stock remains online/server-authoritative.

No event-sourcing package is required. The immutable movement table and explicit domain services implement the needed ledger without introducing a second framework.

## Persistent schemas, invariants, and indexes

### PostgreSQL

    stock_batches
      id UUIDv7 primary key
      branch_id UUID not null
      medication_id UUID not null
      batch_number string not null
      expiry_date date not null
      received_at timestamptz not null
      unit_cost_minor bigint not null
      currency char(3) not null default EGP
      status enum ACTIVE | DEPLETED | EXPIRED | QUARANTINED
      source_type enum OPENING | PURCHASE_RECEIPT | ADJUSTMENT
      source_id UUID not null
      version bigint not null
      created_at timestamptz

    stock_movements
      id UUIDv7 primary key
      branch_id / medication_id / batch_id UUID not null
      movement_type enum OPENING | PURCHASE_RECEIVE | SALE | RETURN |
                         EXPIRY | ADJUSTMENT | INVOICE_CANCEL
      quantity_delta bigint not null
      reason_code string not null
      source_type / source_id string/UUID not null
      reversal_of_movement_id UUID nullable
      actor_id UUID nullable
      correlation_id UUID not null
      occurred_at / created_at timestamptz
      metadata_json bounded nullable

    stock_balances
      branch_id / medication_id / batch_id UUID
      available_quantity bigint not null
      ledger_version bigint not null
      updated_at timestamptz
      primary key (branch_id, medication_id, batch_id)

    branch_inventory_settings
      branch_id / medication_id UUID
      reorder_threshold bigint not null
      expiry_alert_days integer not null
      version bigint not null
      primary key (branch_id, medication_id)

    stock_alerts
      id UUIDv7 primary key
      branch_id / medication_id UUID not null
      batch_id UUID nullable
      alert_type enum LOW_STOCK | EXPIRING | EXPIRED
      threshold_value bigint/integer not null
      status enum OPEN | RESOLVED
      first_detected_at / last_detected_at / resolved_at timestamptz
      deduplication_key string not null unique

Constraints and indexes:

- Unique stock_batches(branch_id, medication_id, batch_number, expiry_date, source_id).
- Check quantity_delta is nonzero; movement sign/type combinations are constrained.
- Check balance is greater than or equal to zero and all quantities fit signed bigint boundaries.
- Unique movement source/type/source_id/batch/reversal tuple prevents duplicate posting.
- Partial unique index on reversal_of_movement_id where not null unless a domain-approved partial reversal model records explicit remaining quantity.
- stock_batches(branch_id, medication_id, expiry_date, status) supports FEFO.
- stock_balances(branch_id, medication_id) includes available_quantity.
- stock_movements(branch_id, medication_id, created_at), stock_movements(source_type, source_id), and stock_movements(batch_id, created_at).
- stock_alerts(status, alert_type, branch_id) and expiry candidate index on active batch expiry_date.

### Hard invariants

1. Quantities are integers in the medication's smallest tracked unit.
2. Every stock change has exactly one immutable movement and source; no code updates quantity without a movement.
3. stock_balances equals the sum of valid movements for its batch and never goes below zero.
4. A batch belongs to exactly one branch and medication; cross-branch movement is impossible.
5. FEFO considers ACTIVE, non-expired batches with positive balances and orders by expiry_date then received_at then batch ID.
6. An expired/quarantined/depleted batch cannot be allocated for sale.
7. Only NATIVE active branches permit ledger writes; INTEGRATED branches use Phase 15 mirror reads.
8. Adjustment, expiry, and reversal require explicit capability, reason, actor, audit, and idempotency.
9. A low-stock alert is open exactly when aggregate available quantity is at/below threshold; it resolves when stock rises above it.
10. Expiry alerts deduplicate by batch/threshold window and never mutate quantity by themselves.

## Detailed success, failure, concurrency, and data flows

### Register initial/received batch

1. Caller supplies an approved medication/package quantity, batch number, expiry, cost, source ID, and idempotency key.
2. InventoryAuthorization rechecks active branch, actor capability, and NATIVE mode.
3. Convert package quantity to smallest units with the versioned Phase 10 conversion; reject overflow/fraction.
4. Begin/participate in the caller's transaction and lock the source/idempotency record.
5. Validate expiry/date and unique source; create the batch if absent.
6. Append OPENING or PURCHASE_RECEIVE positive movement and update stock_balances with compare-and-set ledger_version.
7. Write audit/outbox and commit with the caller workflow.
8. Alert evaluation occurs after commit or synchronously from the new balance without provider calls.

### FEFO allocation

1. POS coordinator sends branch, medication, required smallest-unit quantity, transaction context, and authorized branch context.
2. Inventory selects eligible batch balances ordered by FEFO and locks them using deterministic row order.
3. If total is insufficient, return INSUFFICIENT_STOCK without movements.
4. Allocate from each locked batch until satisfied.
5. Append one SALE movement per allocation and decrement each balance atomically.
6. Return immutable allocation/movement IDs to the POS coordinator; invoice and movement records commit or roll back together.

Concurrent sales cannot oversell because each rechecks locked balances. Redis locks, if used, are only a contention optimization.

### Adjustment, expiry, and reversal

- Adjustment command validates capability, signed direction, reason, evidence reference, and nonnegative resulting balance. It cannot edit the original movement.
- Expiry job identifies batches whose expiry_date is before the business date, locks/rechecks, appends one EXPIRY movement for the remaining quantity, and marks the batch EXPIRED.
- Reversal references original movement(s), validates unused reversible quantity and source state, appends opposite movements, and never deletes originals.
- Unknown transaction outcome is reconciled by source/idempotency key before retry.

### Alerts

Low-stock evaluation sums eligible balances after each committed relevant movement. A daily bounded expiry job uses the branch-configured 30/60/90-day threshold, upserts one alert, and resolves alerts when conditions clear. Notification creation consumes alert events; inventory jobs do not call FCM synchronously.

### Failure behavior

- Deadlock/serialization failure: roll back completely and retry the whole idempotent coordinator within a small bounded budget.
- Balance/ledger mismatch: fail closed for new stock writes on the affected branch/medication, emit a high-priority operations alert, and run read-only reconciliation.
- Redis/Horizon unavailable: sales/receipts remain correct in PostgreSQL; alerts may be delayed.
- Catalog retired after stock exists: keep history/visibility, deny normal new receipt, and require governed disposal/adjustment.

## API, event, and job contracts

### Public pharmacy API

    GET  /api/v1/pharmacy/branches/{branch_id}/inventory?cursor=...
    GET  /api/v1/pharmacy/branches/{branch_id}/inventory/{medication_id}/batches
    PUT  /api/v1/pharmacy/branches/{branch_id}/inventory/{medication_id}/settings
    POST /api/v1/pharmacy/branches/{branch_id}/inventory/adjustments
    GET  /api/v1/pharmacy/branches/{branch_id}/stock-alerts?cursor=...

FEFO allocation, batch receipt, sale, and reversal are internal command ports used by Phase 12/13 coordinators rather than public generic movement endpoints.

Stable errors include BRANCH_MODE_READ_ONLY, INVENTORY_ACCESS_DENIED, MEDICATION_NOT_ACTIVE, PACKAGING_VERSION_CONFLICT, BATCH_EXPIRED, BATCH_QUARANTINED, INSUFFICIENT_STOCK, NEGATIVE_STOCK_FORBIDDEN, LEDGER_VERSION_CONFLICT, DUPLICATE_STOCK_SOURCE, and INVENTORY_RECONCILIATION_REQUIRED.

### Events

- inventory.stock_movement_posted.v1 carries movement/source IDs, branch, medication, batch, type, delta, ledger version, and no display/cost/user data.
- inventory.low_stock_opened/resolved.v1 and inventory.batch_expiring/expired.v1 carry IDs and bounded thresholds/status.
- medication_catalog.medication_retired.v1 triggers validation/cache changes, never silent stock deletion.

### Jobs

- EvaluateLowStock, DetectExpiringBatches, ExpireBatches, ReconcileStockLedger, and ResolveStockAlerts.
- Jobs are Laravel/Horizon-owned, branch-partitioned, lease/row-lock protected, idempotent, bounded, and dead-letter visible.
- Reconciliation reports discrepancies and blocks unsafe writes; it does not silently fabricate adjustment movements.

## Client work

### Pharmacy Flutter desktop

- Branch-scoped inventory list, batch drill-down, low/expiry alerts, and authorized adjustment form.
- Display quantities in useful packages while submitting/storing exact smallest-unit integers and package version.
- Display server-authoritative online/offline status; catalog cache may work offline, but inventory writes and balances require connectivity.
- Scan/search maps to a medication ID, never a client-maintained quantity.
- Confirm adjustments with reason and show immutable movement receipt; no edit/delete affordance.
- Arabic/English, keyboard/barcode efficiency, accessible tables, focus management, and safe error/retry messaging.

### Owner view

Aggregate branch summaries are separate scoped queries; one branch's staff cannot infer another branch. No branch-transfer control appears.

## Security and privacy controls

- Enforce branch capability and NATIVE mode at API, application port, and transactional recheck.
- Require MFA/step-up for high-impact adjustment/disposal/configuration according to approved role policy.
- Protect against BOLA/BFLA, mass assignment of branch/type/delta/source, and forged reversal references.
- Bound quantities, conversions, batch strings, alert thresholds, query pages, and export sizes; reject integer overflow.
- Use server time/business date and trusted package conversion, not client clocks or calculations.
- Audit views/exports as policy requires and every adjustment, expiry, reversal, threshold change, denied write, and reconciliation discrepancy.
- Do not log cost details, actor data, raw reasons, or full movement metadata; sanitize exports against formula injection.
- Separate database/runtime credentials; no public PostgreSQL/Redis exposure. Encrypt backups and cost-sensitive fields where policy requires.

## Test plan

### Unit and property tests

- Movement sign/type rules, balance reducer, FEFO tie-breaking, package conversion, alert open/reset, expiry boundaries, reversal eligibility, and role/mode policy.
- Property tests generate random movement sequences and assert nonnegative balances, sum equality, deterministic allocation, full/partial reversals, overflow rejection, and replay idempotency.

### Integration tests

- Real PostgreSQL concurrent FEFO sales prove one winner/valid split and zero oversell.
- Deadlock/serialization retry, duplicate source/idempotency, rollback with a simulated POS failure, adjustment races, expiry-vs-sale race, and balance compare-and-set.
- Horizon duplicate jobs/worker restarts produce one alert/expiry movement.
- Reconciliation rebuilds balances from ledger and detects intentional corruption without auto-hiding it.

### Contract tests

- Generated Dart client covers quantities, money, cursors, versions, and stable conflicts.
- InventoryCommandPort contract passes for production and in-memory test adapters; the integrated-mode adapter remains read-only.
- Event replay/version compatibility proves no consumer depends on free text.

### End-to-end tests

- Receive/open stock into two expiry batches, view total 60, allocate nearest expiry first, and reset low-stock alert after replenishment.
- Expired stock is never sold; adjustment and reversal preserve originals.
- Cashier cannot adjust, inventory role cannot alter another branch, and every INTEGRATED branch native write is denied.
- Redis/worker outage delays alerts but does not change sale correctness.

### System, performance, and security tests

- Production-shaped branch/batch data meets POS write and medicine-availability p95 targets under concurrent allocation.
- Stress to lock contention/deadlock and verify recovery/backpressure without negative stock.
- Test cross-tenant IDs, forged role/mode/source, replay, integer overflow, race conditions, CSV injection, sensitive logging, and privilege escalation.
- Backup/restore plus full ledger reconciliation reaches zero unexplained discrepancy.

## Observability, migration, and rollout

Metrics: movement count by bounded type, allocation latency/conflicts/insufficient-stock, deadlocks/retries, reconciliation discrepancy, batch/alert counts, expiry-job lag, negative-balance constraint attempts, and queue age. Do not use branch, medication, batch, actor, cost, or reason as metric labels.

Rollout:

1. Expand tables/constraints and deploy read-disabled ports.
2. Seed synthetic medication/branch/batch fixtures and prove reconciliation.
3. Enable read-only inventory for an allowlisted NATIVE branch.
4. Import opening stock through audited OPENING movements, compare signed source totals, then enable adjustments.
5. Enable write ports only after Phase 12/13 coordinators pass atomicity tests.
6. Rollback disables writes while preserving ledger/read access; corrections use new movements, never destructive migrations.

## Acceptance and exit gate

- Every balance reconciles exactly to immutable movements and no tested concurrency path creates negative stock.
- FEFO is deterministic and expired/quarantined stock cannot be allocated.
- Low-stock and expiry alerts open/reset/deduplicate correctly and tolerate queue failure.
- Cross-branch, cross-organization, wrong-role, NATIVE/INTEGRATED, replay, overflow, and reversal abuse tests deny safely.
- Required p95/load/stress, restore/reconciliation, audit/outbox, observability/runbook, migration, client, localization/accessibility, and security evidence passes.
- No branch transfer, reservation, offline sale, supplier automation, or other future feature is enabled.
