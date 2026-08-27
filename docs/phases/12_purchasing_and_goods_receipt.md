# Phase 12 — Purchasing and Goods Receipt

## Objective

Deliver branch-scoped supplier records, purchase orders, partial goods receipts, and atomic posting of received batches into the immutable inventory ledger.

A receipt is one idempotent strong-consistency workflow: purchase-order progress, goods-receipt records, batch creation, stock movements, balances, audit, and outbox either commit together or all roll back. Provider calls, notifications, and analytics never occur inside that transaction.

## Plan traceability

- Sections 51-53, lines 1697-1775: batches and immutable stock movements that receipt must create.
- Sections 54-55, lines 1777-1823: receipt effects on low-stock and expiry alerts.
- Sections 56-57, lines 1825-1891: supplier records, purchase-order flow, default received quantity, batch/movement creation, and partial receipt.
- Section 62, lines 1989-2017: native versus integrated source of truth.
- Sections 102-107, lines 2949-3107: queue separation, outbox, API rules, and purchase-receive idempotency.
- Sections 109-111, lines 3117-3255: supplier, PO, receipt, batch/movement tables and indexes.
- Sections 117-122, lines 3346-3467: isolation, privileged access, rate limits, audit, and log redaction.
- Sections 152 and 159, lines 4085-4110 and 4254-4266: safe retries, partial receive, and repeated-receipt correctness.
- Sections 167-169, lines 4413-4482: CI, safe migrations, and secrets.
- Sections 172-174, lines 4522-4622: strong consistency and asynchronous side effects.

## Entry criteria and dependencies

- Phase 10 supplies active medications, exact packaging conversions, branch tenancy/capabilities, and strict NATIVE/INTEGRATED mode.
- Phase 11 supplies InventoryService, batches, immutable movements, balances, alert events, and reconciliation.
- Phase 00 supplies idempotency, transaction context, outbox, audit, OpenAPI, telemetry, and test environments.
- Pharmacy owners approve purchasing/receiving roles, evidence requirements, and acceptable PO/receipt retention.

## Non-goals

- No supplier API integration, automatic ordering, online payment, accounts payable, price negotiation, branch transfer, or full procurement ERP.
- No receipt into an INTEGRATED branch.
- No floating quantities, negative receipt, untracked over-receipt, or direct balance update.
- No deletion or rewriting of a posted goods receipt.
- No stock creation before receipt commit.
- No offline receipt synchronization.

## Laravel module ownership and services

### Ownership

    Purchasing module
      suppliers, purchase orders, order items,
      goods receipts, receipt items, PO state/progress

    Inventory module
      batches, movements, balances, FEFO readiness, alerts

    ReceivePurchaseService
      owns cross-module transaction and idempotent workflow contract

    PharmacyOrganizations
      branch status, mode, membership, purchasing/receiving capability

Purchasing cannot insert stock_batches or stock_movements. ReceivePurchaseService calls PurchasingReceiptService and InventoryService with one transaction context. Inventory cannot change PO status.

### Module services and external integrations

    Eloquent models: Supplier, PurchaseOrder, PurchaseOrderItem, GoodsReceipt

    PurchasingReceiptService
      lockOutstanding(po_id, transaction_context)
      recordReceipt(receipt_command, transaction_context)
      applyReceivedTotals(po_id, receipt_totals, transaction_context)

    InventoryService
      registerReceivedBatch(batch_command, transaction_context)

    PurchaseAuthorization
      requireCreate(actor, branch)
      requireReceive(actor, branch)

    IdempotencyStore
    TransactionManager
    Clock

- Separate create/order/receive service methods; a generic update endpoint cannot skip state rules.
- Receipt calculation is pure and testable independently of Eloquent.
- Supplier storage is local/manual. Any future external provider uses a small purpose-specific interface and cannot auto-receive.

## Packages and runtime components

- Laravel 13, PostgreSQL, Horizon, outbox, audit, Prometheus, Laravel Telescope (local), Sentry, and UUIDv7 foundations.
- brick/money for ordered/received unit cost as integer minor units with EGP.
- deptrac/deptrac, Larastan/PHPStan, Pest/PHPUnit, and Eris for state/math property tests.
- Pharmacy Electron desktop uses React/TypeScript, TanStack Query, React Hook Form, Zod, MUI, i18next, and an OpenAPI-generated TypeScript client behind typed preload/main capabilities. Printing, export, authenticated transport, and any encrypted draft storage are main-owned privileged adapters, optionally delegating blocking work to a utility process after the OS/ABI spike, not renderer packages.

Do not add a workflow/state-machine package. Backed enum transitions and coordinating-service tests keep the purchasing contract visible.

## Persistent schemas, invariants, and indexes

### PostgreSQL

    suppliers
      id UUIDv7 primary key
      organization_id UUID not null
      name string not null
      phone/address/tax metadata encrypted or minimized per policy
      status enum ACTIVE | INACTIVE
      created_at / updated_at

    purchase_orders
      id UUIDv7 primary key
      branch_id / supplier_id UUID not null
      order_number string not null
      status enum DRAFT | ORDERED | PARTIALLY_RECEIVED | RECEIVED
      ordered_at timestamptz nullable
      currency char(3) not null default EGP
      version bigint not null
      created_by UUID
      created_at / updated_at

    purchase_order_items
      id UUIDv7 primary key
      purchase_order_id / medication_id / packaging_id UUID not null
      packaging_version bigint not null
      ordered_quantity_packages bigint not null
      ordered_quantity_smallest bigint not null
      received_quantity_smallest bigint not null default 0
      unit_cost_minor bigint not null
      version bigint not null

    goods_receipts
      id UUIDv7 primary key
      purchase_order_id / branch_id UUID not null
      receipt_number string not null
      status enum POSTED
      received_at timestamptz not null
      received_by UUID not null
      idempotency_key_hash bytea not null
      request_hash bytea not null
      created_at timestamptz

    goods_receipt_items
      id UUIDv7 primary key
      goods_receipt_id / purchase_order_item_id UUID not null
      medication_id UUID not null
      received_quantity_smallest bigint not null
      batch_number string not null
      expiry_date date not null
      unit_cost_minor bigint not null
      stock_batch_id UUID not null
      stock_movement_id UUID not null

Constraints/indexes:

- Unique purchase_orders(branch_id, order_number).
- Unique purchase_order_items(purchase_order_id, medication_id, packaging_id) unless split costs are explicitly modeled as separate lines.
- Ordered/received quantities are positive/nonnegative bigint and received never exceeds ordered outstanding quantity.
- Currency is consistent across the order and receipt items.
- Unique goods_receipts(purchase_order_id, receipt_number) and goods_receipts(branch_id, idempotency_key_hash).
- Unique goods_receipt_items(goods_receipt_id, purchase_order_item_id, batch_number, expiry_date).
- purchase_orders(branch_id, status, ordered_at desc), purchase_orders(supplier_id, ordered_at desc), and receipt indexes by PO/time.

### Hard invariants

1. A PO belongs to one NATIVE branch and one supplier from the same organization.
2. DRAFT items may change; ORDERED/PARTIALLY_RECEIVED item identity and ordered quantity do not silently mutate.
3. A posted receipt has at least one positive item and is immutable.
4. If the receiver supplies no quantity override, the command defaults to that item's remaining ordered quantity—not the original order after prior partial receipts.
5. Received quantity cannot exceed outstanding quantity unless the PO is explicitly amended before receipt; V1 has no implicit over-receipt.
6. Every receipt item creates/reuses exactly one source-bound batch and exactly one PURCHASE_RECEIVE movement.
7. received_quantity equals the sum of posted receipt items; status is PARTIALLY_RECEIVED until all lines reach ordered amount, then RECEIVED.
8. Duplicate requests return the original receipt; they never create duplicate batches or stock.
9. INTEGRATED, suspended, wrong-branch, wrong-role, retired-medication, expired-at-receipt, and stale-version commands deny.

## Detailed success, failure, concurrency, and data flows

### Create and order a PO

1. Authenticated branch user submits supplier and active medication/package lines.
2. Server resolves branch membership/capability and requires NATIVE mode.
3. Convert package counts to smallest units using the stored packaging version; calculate totals with integer money.
4. Create DRAFT with optimistic version.
5. Submit/ORDER command locks the PO, validates supplier/lines/currency/medication status, transitions to ORDERED, audits, writes a minimal outbox event, and commits.

### Full or partial receipt

1. Client retrieves the latest outstanding quantities and submits item quantities, batch numbers, expiry dates, costs, receipt number, PO version, and Idempotency-Key.
2. ReceivePurchaseService authenticates and builds a server-owned branch authorization context.
3. Idempotency store rejects same key/different canonical request.
4. Begin transaction; lock PO and all affected item rows in deterministic ID order.
5. Recheck ORDERED/PARTIALLY_RECEIVED, NATIVE mode, capability, version, medication/package references, and outstanding totals.
6. For omitted quantities, use remaining; validate positive and at most outstanding.
7. Insert immutable goods receipt and item drafts.
8. For each receipt item, Inventory registers the source-bound batch, PURCHASE_RECEIVE movement, and balance using the same transaction.
9. Purchasing stores returned batch/movement IDs, increments received totals, derives PARTIALLY_RECEIVED or RECEIVED, writes audit and outbox.
10. Commit finalizes the idempotent response. Alert/cache/notification consumers run after commit.

### Concurrency

- Two receipts for one PO lock the same PO/items. The second sees reduced outstanding quantity and either posts a valid remainder or receives a version/outstanding conflict.
- Duplicate network retries use the same idempotency record/receipt IDs.
- A stock-batch uniqueness conflict is reconciled only when branch, medication, batch, expiry, cost/source semantics match; otherwise fail safely.
- Deadlock/serialization failure rolls back PO, receipt, batch, movement, balance, audit, and outbox together before bounded retry.

### Failure and correction

- Inventory validation failure: no receipt/PO progress commits.
- Outbox/provider failure: receipt remains committed and side effects retry.
- Unknown API outcome: client queries by idempotency key/receipt number before retry.
- A discovered posted-receipt error is not edited/deleted. An authorized, reasoned inventory adjustment and linked purchasing correction record are required; scope/policy must be approved before UI enablement.
- Reconciliation mismatch blocks further receipts for the affected branch/medication and alerts operations.

## API, event, and job contracts

### Public pharmacy API

    GET  /api/v1/pharmacy/branches/{branch_id}/suppliers?cursor=...
    POST /api/v1/pharmacy/branches/{branch_id}/suppliers
    PUT  /api/v1/pharmacy/branches/{branch_id}/suppliers/{supplier_id}

    GET  /api/v1/pharmacy/branches/{branch_id}/purchase-orders?cursor=...
    POST /api/v1/pharmacy/branches/{branch_id}/purchase-orders
    PUT  /api/v1/pharmacy/branches/{branch_id}/purchase-orders/{po_id}/draft
    POST /api/v1/pharmacy/branches/{branch_id}/purchase-orders/{po_id}/order
    POST /api/v1/pharmacy/branches/{branch_id}/purchase-orders/{po_id}/receipts
    GET  /api/v1/pharmacy/branches/{branch_id}/goods-receipts/{receipt_id}

All mutations use record version; order and receipt use Idempotency-Key.

Stable errors include PURCHASE_ACCESS_DENIED, BRANCH_MODE_READ_ONLY, PO_NOT_RECEIVABLE, PO_VERSION_CONFLICT, RECEIPT_DUPLICATE_CONFLICT, RECEIVED_QUANTITY_EXCEEDS_OUTSTANDING, RECEIPT_BATCH_INVALID, MEDICATION_NOT_RECEIVABLE, PACKAGING_VERSION_CONFLICT, INVENTORY_RECONCILIATION_REQUIRED, and RECEIPT_OUTCOME_UNKNOWN.

### Events

- purchasing.purchase_order_ordered.v1, goods_receipt_posted.v1, purchase_order_partially_received.v1, and purchase_order_received.v1.
- Payloads contain IDs, branch, PO/receipt status/version, counts, and correlation data—not supplier contact, cost totals, batch free text, or actor details.
- inventory.stock_movement_posted.v1 remains owned by Inventory and is written in the same transaction.

### Jobs

- No job performs receipt posting. Optional stale-draft cleanup, report generation, analytics, and notifications consume committed IDs.
- Jobs are Horizon-owned, idempotent, bounded, and dead-letter visible.
- A reconciliation job compares PO received totals, receipt items, movement sources, and balance ledger without auto-correcting unexplained differences.

## Client work

### Pharmacy Electron desktop (React + TypeScript)

- Supplier list/form, PO draft editor, ordered/outstanding display, full/partial receipt screen, barcode lookup, batch/expiry/cost inputs, and immutable receipt detail.
- Default received quantity visibly uses remaining quantity and requires confirmation; the client never silently posts on screen open.
- Offline UI may retain an encrypted unsent form draft in SQLCipher-backed SQLite owned outside the renderer; receipt requires online submission, idempotency, and explicit pending/unknown/succeeded states.
- On timeout, poll the canonical result; do not offer a fresh duplicate action.
- File export and printing use purpose-specific preload capabilities with validated bounded data; the renderer cannot choose arbitrary filesystem paths or invoke a shell/print API directly.
- Branch mode/capability/read-only state, Arabic/English, keyboard/barcode workflows, focus order, date validation, accessible errors, and safe printing/export are required.

### Admin

No supplier/PO content is exposed through the generic admin role. Operational aggregate counts, if later needed, are de-identified and capability-gated.

## Security and privacy controls

- Enforce organization/branch object authorization, create/order/receive function authorization, NATIVE mode, and step-up for high-impact receipt/correction policy.
- Server derives branch, supplier organization, medication, conversion, quantity totals, actor, movement type, and source; mass assignment cannot alter them.
- Bound line counts, quantities, costs, strings, dates, and total request size; reject overflow, NaN/floats, duplicate line IDs, and formula-injection export fields.
- Supplier contact/legal data is minimized/encrypted by classification and absent from events/logs/traces/metrics.
- Audit supplier changes, PO ordering, receipts, partial/completed states, correction/adjustment links, denials, and reconciliation failures.
- No supplier API credentials or external connectivity exist in this phase.
- Use database constraints/row locks rather than Redis as correctness; protect migrations/backups and use least-privilege runtime/migration roles.

## Test plan

### Unit and property tests

- PO state transitions, remaining/default quantity, full/partial status derivation, package conversion, integer money, over-receipt denial, line/currency rules, and authorization/mode policy.
- Property tests generate orders/receipt sequences and assert received equals receipt sum, never exceeds ordered, status matches remainder, and replay is idempotent.
- Electron renderer form/state tests and preload/main draft, print, export, polling, cancellation, and sender/scope validators run as isolated unit suites.

### Integration tests

- Real PostgreSQL verifies full/partial receipt transactions, concurrent receivers, duplicate idempotency, source uniqueness, row-lock order, deadlock retry, and rollback when any Inventory call fails.
- Assert one receipt item maps to one batch/movement and balances/PO totals reconcile.
- Retired medication, changed packaging version, expired batch, suspended branch, mode switch attempt, and insufficient permissions deny with no partial rows.
- The packaged main-owned encrypted draft adapter passes native-ABI, wrong-key/no-blank-replacement, migration, branch/logout cleanup, and optional-utility crash tests; print/export adapters accept only bounded DTOs and approved destinations.

### Contract tests

- Generated TypeScript pharmacy client covers money/quantities, omitted received quantity semantics, versions, idempotency outcome, and stable errors.
- Preload/IPC contract tests cover draft persistence, barcode input, print/export, canonical-result polling, sender validation, schema bounds, timeout, and cancellation.
- PurchasingReceiptService and InventoryService transaction-context contracts prove all-or-nothing behavior.
- Events replay safely and omit supplier/contact/cost data.

### End-to-end tests

- Order 100, receive 80, observe PARTIALLY_RECEIVED/20 remaining, receive 20, observe RECEIVED and exact two receipt/movement groups.
- Omitted quantity receives only remaining.
- Repeating either receipt returns the original result and stock does not double.
- Cashier/other branch/INTEGRATED branch cannot receive; Redis/notification outage does not affect committed receipt.
- Packaged Electron E2E covers scanner/keyboard receipt, encrypted draft restart recovery, unknown-outcome polling, safe print/export, and denial of arbitrary path/SQL/generic IPC requests.

### System, performance, and security tests

- Concurrent high-line-count receipts meet agreed p95 and do not create lock starvation or ledger discrepancy.
- Fault injection at every transaction step proves complete rollback; worker/outbox replay proves no duplicate side effect.
- Test BOLA/BFLA, mass assignment, idempotency hash mismatch, quantity/cost overflow, stale version, race, CSV/formula injection, sensitive logs, and privilege escalation.
- Backup/restore followed by purchasing-inventory reconciliation reports zero unexplained mismatch.

## Observability, migration, and rollout

Metrics: PO/receipt totals by bounded status, partial ratio, receipt latency/conflicts/rollback/error class, idempotency replays, lock/deadlock rate, reconciliation discrepancies, and outbox/queue age. Supplier, branch, medication, batch, actor, cost, and free-text reasons are not labels.

Rollout:

1. Expand supplier/PO/receipt schemas and deploy disabled commands.
2. Seed synthetic suppliers/orders and prove transaction/reconciliation suites.
3. Enable PO drafts for one allowlisted NATIVE branch.
4. Enable ordering, then receipt after signed opening inventory reconciliation.
5. Monitor receipt conflicts, ledger parity, deadlocks, and support incidents before cohort expansion.
6. Rollback disables new order/receipt actions; posted receipts and stock remain immutable/readable.

## Acceptance and exit gate

- Full/partial receipts atomically update PO progress, immutable receipt records, batches, movements, balances, audit, and outbox.
- Duplicate/reordered/concurrent receipt requests never over-receive or duplicate stock.
- All wrong-role, wrong-branch, INTEGRATED, stale-version, overflow, retired-medication, and reconciliation-failure paths deny with zero partial writes.
- Purchasing-to-inventory reconciliation and restore evidence is exact.
- Unit, property, integration, contract, E2E, system/load, security, observability/runbook, migration, client, accessibility/localization, and approval gates pass.
- No supplier API, accounts payable, auto-ordering, branch transfer, offline receipt, or other future feature is enabled.
