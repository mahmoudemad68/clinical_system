---
name: clinic-pharmacy-domain
description: Implement native pharmacy catalog, tenancy, inventory, FEFO, purchasing, POS, returns/refunds, and patient availability workflows in Laravel. Use for Phases 10–14; external connector and mirror work belongs to clinic-pharmacy-integrations.
---

# Clinic Pharmacy Domain

Implement the V1 pharmacy domain as strongly consistent, branch-scoped Laravel modules backed by PostgreSQL. Preserve immutable financial/stock history, strict operating modes, integer arithmetic, and the difference between availability observation and reservation.

## Ownership and routing

This skill owns:

- `MedicationCatalog`: medications, ingredients, aliases, dosage forms, packaging, provenance, lifecycle, and approved search references;
- `PharmacyOrganizations`: organizations, branches, memberships, branch roles/capabilities, payment-method settings, status, and operating mode;
- `Inventory`: batches, immutable movements, balances, FEFO allocation, adjustments/reversals, thresholds, and stock/expiry alerts;
- `Purchasing`: suppliers, purchase orders, immutable goods receipts, and receipt coordination with Inventory;
- `POS`: invoices, server-side totals, payment records, FEFO sale coordination, cancellation, returns, and refunds;
- `MedicineDiscovery`: public-safe manual search and Find My Medicines aggregation across native or integrated availability ports.

`PharmacyIntegrations` owns external connectors, mappings, staging generations, and mirrors. `Prescriptions` owns published prescription versions; this skill can read them only through an authorized port. AI consumes pharmacy ports but does not own pharmacy policy. Clients never decide branch scope, role, operating mode, price, stock, FEFO, returnability, or totals.

## Required phase sources

Always read [Phase 00](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md) and all pharmacy phases that participate in the requested workflow:

- [Phase 10 — Medication Catalog and Pharmacy Tenancy](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md);
- [Phase 11 — Inventory, Batches, FEFO, and Alerts](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md);
- [Phase 12 — Purchasing and Goods Receipt](../../../docs/phases/12_purchasing_and_goods_receipt.md);
- [Phase 13 — POS, Invoices, Returns, and Refunds](../../../docs/phases/13_pos_invoices_returns_and_refunds.md);
- [Phase 14 — Medicine Search and Prescription Fulfillment](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md).

For prescription exposure read [Phase 06](../../../docs/phases/06_prescriptions_reminders_and_printing.md). For integrated availability read [Phase 15](../../../docs/phases/15_external_pharmacy_integrations.md). For Pharmacy AI callers read [Phase 18](../../../docs/phases/18_pharmacy_ai.md), but keep all Core authorization and writes in Laravel.

## V1 boundaries

Do not implement medication alternatives/therapeutic substitution, reservation, delivery, branch transfer, supplier API/automatic ordering, online payment, remote card processing, controlled-drug workflow, multi-country behavior, exact patient-visible quantity, patient price, or a full offline ERP. These remain non-goals even if a package or external system supports them.

Use Laravel 13, PostgreSQL/PostGIS, the transactional outbox, `brick/money`, UUIDv7, `deptrac/deptrac`, Larastan/PHPStan, Pest/PHPUnit, and Eris/property tests. Do not add an event-sourcing framework: the explicit immutable stock ledger is sufficient.

## Non-obvious domain invariants

### Catalog and tenancy

- Each strength/form/package variation has its own stable medication ID. An active medication has approved provenance and exactly one smallest tracked unit.
- Packaging is an acyclic graph of exact positive integer conversions to the smallest unit. Never store fractional packages or binary floating point.
- Referenced medication/packaging rows retire; they are never deleted, repurposed, or rewritten in historical prescriptions, receipts, movements, or invoices.
- Branch authorization is deny-by-default and derived server-side from active organization, branch, membership, explicit capability, verification/status, and current mode/version. The roles OWNER, PHARMACIST, CASHIER, INVENTORY, PURCHASING, and CONNECTOR_SERVICE grant only their configured capabilities.
- Only `NATIVE` active branches may mutate native inventory, purchasing, or POS. Only a branch-bound connector identity may write an `INTEGRATED` mirror. Every committing command rechecks mode/version; a UI flag or cached context cannot bypass it.

### Inventory and purchasing

- Quantities are `bigint` smallest units. Every stock change appends exactly one source-bound movement; no code directly decrements a balance or edits/deletes a movement.
- Balances never become negative and must reconcile to valid movements. Batch identity is fixed to one branch and medication.
- FEFO considers positive, active, non-expired, non-quarantined balances and orders by expiry date, received time, then batch ID. Lock in that deterministic order inside the sale transaction; Redis is not the oversell defense.
- A posted receipt is immutable. Omitted receipt quantity means the remaining outstanding amount after earlier receipts, not the original order quantity.
- `ReceivePurchaseCoordinator` locks the PO/items, posts receipt/items, registers/reuses source-bound batches, appends `PURCHASE_RECEIVE` movements, advances PO totals/status, and writes audit/outbox in one transaction.

### POS, returns, and discovery

- Prices, discounts, taxes if configured, line totals, and invoice totals are computed server-side in integer minor units and one currency. PAID invoice lines are immutable.
- `CompleteSaleCoordinator` commits invoice, payment record, FEFO allocations/movements, audit, idempotency, and outbox together or nothing. Cancellation appends linked reversal movements once; it never deletes the sale.
- Cumulative valid returns cannot exceed sold quantity, and refunds cannot exceed eligible returned value or the original payment. Only governed RESTOCKABLE returns append positive movement to the eligible original batch.
- CARD means an external terminal has already acted. Accept and store only an allowlisted opaque external reference, approved status, method, amount, currency, and time. Reject PAN/CVV-like input; never accept, log, persist, transmit, tokenize, authorize, capture, void, or refund card data.
- Find My Medicines loads the authenticated patient's current published immutable prescription server-side. It records a `FIND_MEDICINES` access event but does not lock, unlock, or edit the prescription.
- Public availability is a freshness-bounded observation, never a reservation or guarantee. Responses omit quantity, batch, supplier, cost, price, internal status, movement, and connector identity. Rank by coverage descending, distance ascending, then stable branch ID—never paid placement, rating, or price.

## Implementation workflow

1. Read Phase 10 plus every downstream phase participating in the use case. Identify the aggregate owner, caller capability, branch mode, quantity/money units, and whether a cross-module coordinator is mandatory.
2. Express the transition as domain value objects and intent-named ports. Do not expose Eloquent models or permit callers to supply signed movement deltas, movement types, totals, branch scope, or statuses.
3. Put database-enforceable rules in constraints/indexes and race-sensitive work in one PostgreSQL transaction. Lock sources and affected rows in deterministic order; use version checks and scoped idempotency.
4. Have Inventory return immutable allocation/movement references to Purchasing/POS through the shared transaction context. Audit and outbox commit with the authoritative records; alerts, printing, notifications, and analytics run after commit.
5. Make unknown outcomes queryable by idempotency key, receipt number, invoice ID, or external terminal reference. A retry of the same intent returns the original result; same key/different request conflicts.
6. Implement client/API projections that expose only the active actor's branch capabilities and the public-safe patient view. Keep native stock and financial truth online/server-authoritative.
7. Roll out catalog/tenancy first, then inventory, purchasing, POS, and discovery behind server flags and allowlisted branches. Never destructively roll back ledger or invoice history.

## Observable verification

Evidence must include the affected rule, API/port contract, and real PostgreSQL behavior:

- property tests cover packaging trees, integer overflow, movement sequences, FEFO allocation, partial receipts, proportional refunds/rounding, duplicate/reordered commands, and invariant preservation;
- concurrent sales cannot oversell; concurrent receipts cannot over-receive; concurrent returns cannot over-return/refund; one deadlock/serialization retry replays the whole idempotent coordinator;
- ledger reconciliation proves balances equal valid movements, and a forced mismatch fails closed for affected writes and raises an observable alert;
- wrong organization/branch/role, suspended membership, `INTEGRATED` native write, `NATIVE` connector write, stale mode version, retired medication, expired batch, and forged totals are denied;
- sale/receipt/cancellation/refund rollback leaves no orphan invoice, receipt, batch, movement, balance, payment, audit, idempotency-success, or outbox record;
- PAN/CVV-like payloads are rejected before persistence and classified canaries appear in no log, trace, metric, event, export, or crash report;
- patient search returns no price or quantity, excludes stale/invalid sources, does not mutate prescriptions, and remains deterministic under atomic mirror-generation changes;
- production-shaped catalog, FEFO, invoice, and PostGIS search queries meet the measured Phase 21 target, with accessibility/localization and restore/reconciliation evidence.

Report the module/aggregate owner, coordinator and transaction boundary, quantity/money representation, branch-mode checks, immutable records created, contracts/migrations, verification executed, and any pharmacy/regulatory decision still awaiting approval.
