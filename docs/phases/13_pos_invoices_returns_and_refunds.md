# Phase 13 — POS, Invoices, Returns, and Refunds

## Objective

Deliver an online, branch-scoped pharmacy POS with barcode/search cart entry, integer quantities and money, configured cash/card recording, immutable paid invoices, cancellation through stock reversals, and separately audited returns/refunds.

Sale, inventory allocation, invoice, payment record, audit, and outbox commit in one PostgreSQL transaction. Card handling is limited to recording an approved external terminal result/reference. This platform never captures, transmits, logs, or stores PAN, CVV, track data, PIN, or cardholder authentication secrets.

## Plan traceability

- Sections 50-53, lines 1666-1775: smallest units, batches, FEFO, and immutable SALE/RETURN/INVOICE_CANCEL movements.
- Sections 58-60, lines 1893-1964: search/barcode cart, quantity, configured discounts, cash/card, invoice, return/refund/cancel, immutable cancellation, and restockable policy.
- Section 61, lines 1966-1987: owner visibility across branches; no branch transfer.
- Section 62, lines 1989-2017: NATIVE versus INTEGRATED source of truth.
- Sections 101-107, lines 2926-3107: durable side effects, outbox, API shape, and POS/refund idempotency.
- Sections 109-111, lines 3117-3255: invoices, items, payments, returns, refunds, movements, IDs, and indexes.
- Sections 117-122, lines 3346-3467: network security, MFA, rate limits, append-only audit, and redacted logs.
- Sections 132-139, lines 3640-3833: POS p95 target and PostgreSQL/connection scaling.
- Sections 154 and 159-162, lines 4144-4156 and 4254-4330: POS stays online, pharmacy correctness, load, and stress.
- Sections 171-174, lines 4503-4622: online payment excluded, strong consistency, source-of-truth, and async rules.

## Entry criteria and dependencies

- Phase 10 supplies medication/package references, branch payment-method settings, strict roles/capabilities, and NATIVE/INTEGRATED mode.
- Phase 11 supplies FEFO allocation, immutable movements, balances, reversals, and reconciliation.
- Phase 12 supplies received stock and batch provenance.
- Phase 00 supplies idempotency, transaction/outbox/audit, OpenAPI, telemetry, secrets, and test harnesses.
- Pharmacy/legal/accounting owners approve discount, cancellation, return/restockability, refund, cash balancing, invoice numbering, and retention policy.
- An approved external card terminal/process is selected. If none is approved, CARD remains disabled.

## Non-goals

- No online payment gateway, card-not-present flow, stored card, token vault, payment capture/refund API, patient reservation, insurance, tax engine, loyalty, credit sale, branch transfer, or offline POS.
- No PAN/CVV/cardholder data or free-form terminal receipt storage.
- No invoice deletion/edit after payment.
- No return automatically restocked; an authorized actor selects RESTOCKABLE or NON_RESTOCKABLE under approved policy.
- No selling expired/quarantined/insufficient stock.
- No POS writes for INTEGRATED branches.

## Architecture, ownership, and SOLID boundaries

### Ownership

    POS/Sales module
      cart validation, server pricing/discount calculation,
      invoices, invoice items, payment records, returns, refunds,
      cancellation/refund state machines and immutable references

    Inventory module
      FEFO allocation and linked SALE/RETURN/INVOICE_CANCEL movements

    CompleteSaleCoordinator
    CancelInvoiceCoordinator
    ProcessReturnRefundCoordinator
      own shared transaction boundaries and idempotency

    ExternalTerminalReferencePort
      validates/normalizes an already completed terminal result reference;
      performs no card capture and receives no PAN/CVV

### Ports

    SalesAuthorization
      requireSell(actor, branch)
      requireCancel(actor, branch)
      requireRefund(actor, branch)

    PricingPolicy
      price(cart_line, branch_configuration)
      applyConfiguredDiscount(...)

    InventoryCommandPort
      allocateFefo(...)
      reverseMovements(...)
      appendReturnMovement(...)

    InvoiceRepository
    ReturnRepository
    PaymentRecordRepository
    ExternalTerminalReferencePort
    TransactionManager
    IdempotencyStore

- The client submits medication, package/quantity, and a permitted discount reference; the server computes authoritative totals.
- Inventory owns batch choice and quantity mutation. POS stores immutable allocation/movement references.
- Card and cash adapters implement distinct narrow contracts. A generic payment-provider object is not injected into domain code.
- Events trigger receipts/analytics/notifications only after commit.

## Packages and runtime components

- Laravel 13, PostgreSQL, Horizon, outbox, audit, OpenTelemetry, Sentry, and UUIDv7 foundations.
- brick/money for all totals, discounts, refund amounts, and currency calculations.
- A small internal external-terminal reference validator; no payment SDK is required for V1.
- deptrac/deptrac, Larastan/PHPStan, Pest/PHPUnit, and Eris for money/allocation/refund property tests.
- Pharmacy Electron desktop uses React/TypeScript, TanStack Query, Zod, MUI, i18next, and an OpenAPI-generated TypeScript client. Barcode, authenticated HTTP/realtime, printing, secure storage, and update/native integrations are exposed only as narrow typed preload capabilities implemented by validated main-process handlers.
- If a non-authoritative encrypted cart draft is approved, keep it in main-owned and authorized SQLCipher-backed SQLite outside the renderer, preferring a utility process where the target-OS/ABI spike supports it. Main unwraps the random database key through Electron `safeStorage`; production fails closed when OS-backed protection is unavailable, including Linux `basic_text`. Pass wrong-key, no-empty-database, migration, rekey, native-addon, signed-package, and supported-OS/architecture tests.

Do not add a browser card form, generic PCI field component, or terminal SDK until a separate approved integration scope exists.

## Persistent schemas, invariants, and indexes

### PostgreSQL

    invoices
      id UUIDv7 primary key
      branch_id UUID not null
      invoice_number string not null
      status enum PAID | CANCELLED | PARTIALLY_RETURNED | RETURNED
      subtotal_minor / discount_minor / total_minor bigint not null
      currency char(3) not null default EGP
      payment_method enum CASH | CARD
      created_by UUID not null
      paid_at / cancelled_at timestamptz nullable
      cancellation_reason_code string nullable
      version bigint not null
      created_at timestamptz

    invoice_items
      id UUIDv7 primary key
      invoice_id / medication_id UUID not null
      medication_snapshot_json bounded not null
      quantity_smallest bigint not null
      unit_price_minor / line_subtotal_minor / line_discount_minor /
        line_total_minor bigint not null
      returned_quantity_smallest bigint not null default 0

    invoice_stock_allocations
      invoice_item_id / stock_movement_id / batch_id UUID
      allocated_quantity_smallest bigint not null
      primary key (invoice_item_id, stock_movement_id)

    payments
      id UUIDv7 primary key
      invoice_id UUID not null
      method enum CASH | CARD
      amount_minor bigint not null
      currency char(3) not null
      status enum RECORDED | REVERSED
      external_terminal_reference string nullable
      external_terminal_status enum APPROVED | VOIDED | REFUNDED nullable
      recorded_at / reversed_at timestamptz nullable

    returns
      id UUIDv7 primary key
      invoice_id / branch_id UUID not null
      status enum POSTED
      reason_code string not null
      created_by UUID not null
      created_at timestamptz

    return_items
      id UUIDv7 primary key
      return_id / invoice_item_id UUID not null
      quantity_smallest bigint not null
      disposition enum RESTOCKABLE | NON_RESTOCKABLE
      refund_amount_minor bigint not null
      return_stock_movement_id UUID nullable

    refunds
      id UUIDv7 primary key
      return_id / invoice_id UUID not null
      method enum CASH | CARD
      amount_minor bigint not null
      currency char(3) not null
      external_terminal_reference string nullable
      status enum RECORDED
      created_by UUID not null
      created_at timestamptz

Constraints/indexes:

- Unique invoices(branch_id, invoice_number).
- Monetary fields are nonnegative bigint; total equals subtotal minus discount; payment equals invoice total for V1.
- Quantities are positive smallest-unit bigint and returned quantity never exceeds sold quantity.
- CARD payment/refund requires an approved external_terminal_reference; CASH requires it null.
- Explicit checks reject strings resembling PAN/CVV patterns in terminal-reference fields and strict allowlist/length validation applies.
- Unique payments(invoice_id) for V1 single payment.
- Unique external terminal reference within branch/method/purpose as approved to prevent replay.
- invoices(branch_id, paid_at desc), invoices(branch_id, status, paid_at), returns(invoice_id, created_at), refunds(invoice_id, created_at).
- Unique idempotency records are scoped to branch/actor/operation; source-linked movement uniqueness comes from Inventory.

### Hard invariants

1. Only an active NATIVE branch with sell capability can create a sale.
2. Prices/discounts/totals are calculated server-side in integer minor units and EGP.
3. PAID invoices and items are immutable; cancellation, return, and refund append linked records.
4. One sale commits invoice/payment and all FEFO movements together or commits nothing.
5. CANCELLED reverses all eligible original sale movements exactly once and cannot be cancelled again.
6. Return quantity across all returns cannot exceed sold quantity minus prior valid returns.
7. RESTOCKABLE appends linked positive return movement to the original batch only when policy/expiry/condition permits; NON_RESTOCKABLE never increases available stock.
8. Refund total cannot exceed eligible returned value or original paid amount.
9. CARD records only method, amount, approved external reference/status/time. PAN/CVV never enters an accepted request/schema.
10. Repeated/unknown-outcome commands reconcile by Idempotency-Key and return the original result.

## Detailed success, failure, concurrency, and data flows

### Complete cash/card sale

1. Cashier scans/searches medication and submits branch, medication/package quantities, optional configured discount reference, payment method, and Idempotency-Key.
2. Laravel resolves branch/capability/NATIVE mode and active payment method.
3. For CARD, client records only the external terminal's already approved opaque reference/status; no card data is sent.
4. Server resolves active medication/package conversion and pricing; computes immutable line/totals.
5. Begin transaction, claim idempotency, lock needed inventory batches in deterministic FEFO order, and allocate every line.
6. If any line is insufficient/expired, roll back all allocations.
7. Insert invoice/items/allocations/payment and movement links, audit, and outbox.
8. Commit and return canonical invoice. Printing/notifications/analytics run afterward.

### Cancel invoice

1. Authorized actor submits invoice version, reason, confirmation, and Idempotency-Key.
2. Coordinator locks invoice, requires PAID and no incompatible prior return/refund, and rechecks branch/capability.
3. Inventory creates exactly linked INVOICE_CANCEL reverse movements for original SALE allocations.
4. Payment is marked REVERSED; for CARD the platform records an external terminal VOIDED/REFUNDED reference supplied after the external action, never initiates it.
5. Invoice becomes CANCELLED with audit/outbox in the same transaction.

### Return and refund

1. Actor selects invoice items/quantities, reason, and per-item disposition under branch policy.
2. Server computes remaining returnable quantities and proportional refund using approved rounding policy.
3. For card refund, external terminal operation occurs outside this platform; only an approved opaque refund reference is submitted.
4. Transaction locks invoice/items, rechecks remaining quantities/value/idempotency, inserts return/refund, updates returned counters, and calls Inventory for RESTOCKABLE movements.
5. Derive PARTIALLY_RETURNED or RETURNED, audit, outbox, and commit.

### Failure/concurrency

- Concurrent sales serialize on batch balances and cannot oversell.
- Concurrent returns serialize on invoice items and cannot over-return/refund.
- External terminal result is rejected if duplicated, malformed, unapproved, wrong amount/currency, or already used.
- Unknown outcome never causes a new intent; query invoice/refund by idempotency key.
- Redis/Horizon/printer failure cannot roll back or duplicate a committed sale.
- Ledger mismatch blocks affected stock-changing commands and raises operations alert.

## API, event, and job contracts

### Public pharmacy API

    POST /api/v1/pharmacy/branches/{branch_id}/pos/sales
    GET  /api/v1/pharmacy/branches/{branch_id}/invoices/{invoice_id}
    GET  /api/v1/pharmacy/branches/{branch_id}/invoices?cursor=...
    POST /api/v1/pharmacy/branches/{branch_id}/invoices/{invoice_id}/cancel
    POST /api/v1/pharmacy/branches/{branch_id}/invoices/{invoice_id}/returns
    GET  /api/v1/pharmacy/branches/{branch_id}/returns/{return_id}

All mutations require Idempotency-Key; cancellation/returns also require aggregate version.

Accepted card JSON has only:

    payment_method: CARD
    external_terminal_reference: bounded opaque string
    external_terminal_status: APPROVED

Schemas set additionalProperties false and contain no PAN, card number, CVV, expiry, track, PIN, or cardholder fields.

Stable errors include POS_ACCESS_DENIED, BRANCH_MODE_READ_ONLY, PAYMENT_METHOD_DISABLED, CARD_TERMINAL_REFERENCE_INVALID, CARD_TERMINAL_REFERENCE_REUSED, CART_INVALID, PRICE_VERSION_CONFLICT, INSUFFICIENT_STOCK, INVOICE_NOT_CANCELLABLE, RETURN_QUANTITY_EXCEEDED, REFUND_AMOUNT_EXCEEDED, RETURN_NOT_RESTOCKABLE, IDEMPOTENCY_KEY_REUSED, and INVENTORY_RECONCILIATION_REQUIRED.

### Events/jobs

- sales.invoice_paid.v1, invoice_cancelled.v1, return_posted.v1, and refund_recorded.v1 carry IDs/status/amount minor/currency/counts only, never terminal reference, item names, or actor details.
- Inventory emits its own linked movement events.
- GenerateReceiptDocument, SendReceiptNotification, AnalyticsProjection, and ReconcileSalesInventory are post-commit Horizon jobs with IDs only, bounded retry, idempotency, and dead-letter visibility.
- No job completes a sale/cancellation/return/refund.

## Client work

### Pharmacy Electron desktop (React + TypeScript)

- Keyboard/barcode-first cart, server price refresh, smallest-unit-safe quantity, configured discount selector, cash/card method gating, and clear online state.
- CARD workflow instructs staff to complete the approved external terminal action, then enter/scan only its opaque reference/status. No card-entry fields exist.
- Show pending/unknown/succeeded idempotent submission and poll after timeout.
- Print canonical server invoice/return; printing failure offers reprint and never repeats the sale.
- Cancellation/return/refund require role, confirmation, reason, version, and exact affected items.
- Clear cached cart/financial data on branch/session change. An optional encrypted unsent cart is UI state only and cannot allocate stock offline.
- The renderer never handles device/API tokens, raw database keys, SQL, arbitrary IPC, external commands, or printer handles. It receives only validated DTOs from the preload facade; main-owned handlers bind every capability to the active window, session, branch, size limit, and deadline, optionally delegating approved blocking work to a utility process.
- Arabic/English, accessible scanner focus, error summaries, currency display, large controls, and safe receipt rendering are required.

### Owner view

Owner sees authorized branch aggregates/invoices, never another organization. Exports are bounded, audited, formula-safe, and contain no terminal/card data.

## Security and privacy controls

- Enforce branch object/function authorization, NATIVE mode, payment-method setting, and step-up for cancellation/refund/high discounts.
- Compute totals, movement types, batch selection, returnable quantity, refund, status, actor, and timestamps server-side.
- Reject unknown JSON fields and scan all accepted strings/log pipelines to prevent accidental PAN/CVV ingestion; never echo rejected card data.
- Use opaque external references with strict pattern/length/uniqueness; encrypt if provider contract treats them as sensitive.
- Rate-limit expensive/search/mutation operations and bound cart size, quantity, price, discount, return lines, exports, and printing payloads.
- Audit sales, cancellations, returns, refunds, denials, external-reference state, reconciliation, and reprints without product free text or payment reference.
- Protect against receipt HTML/template injection and CSV formula injection.
- Keep terminal references, money details, invoice items, and reasons out of logs/traces/metric labels/crash reports.
- No network egress to a payment gateway exists in V1.

## Test plan

### Unit/property tests

- Money/discount/rounding/totals, payment-method/reference rules, invoice state, cancellation eligibility, returnable/refundable remainder, restock disposition, and capability/mode.
- Property tests across random carts/returns assert total equations, conservation of sold/returned quantities, refund bounds, and replay idempotency.
- PAN/CVV-like field/property/fuzz corpus is rejected without being logged.
- Electron renderer cart/reducer/format tests and preload/main sale, result-poll, print, scanner, encrypted-cart, sender, scope, and payload validators run as isolated unit suites.

### Integration tests

- Real PostgreSQL concurrent FEFO sales, multi-line rollback on one shortage, duplicate idempotency, unknown outcome, concurrent returns, cancellation-versus-return race, and external-reference uniqueness.
- Invoice/payment/movements/audit/outbox atomicity and complete rollback at each injected failure point.
- Redis/Horizon/printer failure leaves one committed invoice and replay-safe side effects.
- Main-owned encrypted cart and print adapters pass native-ABI, wrong-key/no-blank-replacement, migration/rekey, branch/logout cleanup, print failure, optional-utility crash, and signed-package tests without leaking keys, SQL, terminal references, or invoice content.

### Contract tests

- Generated TypeScript pharmacy client has no card-sensitive fields and covers money, versions, idempotency, and stable conflicts.
- Preload/IPC contracts expose distinct sale, result-poll, print, encrypted-cart, and scanner capabilities; tests reject generic send/on bridges, unexpected sender frames, arbitrary paths/URLs, extra fields, and oversized receipt/cart payloads.
- ExternalTerminalReferencePort fakes/production implementation share approved/reused/malformed semantics.
- Events omit references/item free text and replay without duplicate notification/analytics.

### End-to-end tests

- Cash and external-terminal-reference sales consume FEFO stock and print one invoice.
- Duplicate submit returns the same invoice; two users competing for last stock cannot oversell.
- Cancel creates reverse movements and immutable CANCELLED invoice.
- Partial RESTOCKABLE and NON_RESTOCKABLE returns update quantities/refund/stock correctly; no over-return/refund.
- Cashier/other branch/INTEGRATED branch/disabled card method are denied.
- Packaged Electron E2E covers scanner/cart, external-terminal reference, unknown-result recovery, canonical print/reprint, restart with an encrypted non-authoritative cart, and denial of direct IPC/path/SQL/scope injection.

### System, performance, and security tests

- Meet POS p95 at production-shaped cart/batch concurrency; stress until lock contention and verify controlled recovery.
- Fault-inject database/Redis/Reverb/printer/worker restarts and restore/reconcile invoice-payment-ledger equality.
- Test BOLA/BFLA, role escalation, mass assignment, price/discount tampering, overflow, replay, race, terminal-reference reuse, PAN/CVV leakage canaries, receipt XSS/CSV injection, and sensitive logging.
- Renderer XSS/hostile receipt content cannot obtain Node/Electron, read encrypted cart/token material, open arbitrary navigation, or trigger shell/file/print/update capabilities.

## Observability, migration, and rollout

Metrics: sale/cancel/return/refund count and amount by bounded status/method/currency, p95, insufficient stock, idempotency replay, lock/deadlock, external-reference validation failures, print/job failure, and reconciliation discrepancy. No invoice, branch, actor, product, terminal reference, or reason labels.

Rollout:

1. Expand schemas and deploy all mutations disabled.
2. Run synthetic cash flows and ledger reconciliation.
3. Enable CASH for one allowlisted NATIVE branch.
4. Enable CARD only after approved terminal/process, schema/card-data security tests, and staff training.
5. Enable cancellation, then returns/refunds after role/policy sign-off and reconciliation evidence.
6. Rollback disables new mutations while preserving read/reprint and immutable records; never delete invoices/movements.

## Acceptance and exit gate

- Atomic sale/cancel/return/refund workflows reconcile exactly with payments and inventory under concurrency/retry/failure.
- Paid invoices/movements remain immutable and every correction is linked/authorized/audited.
- The code, OpenAPI, UI, logs, traces, fixtures, events, and tests contain no PAN/CVV capture/storage path.
- Cross-tenant, wrong-role, INTEGRATED, amount/price/discount tampering, overflow, replay, race, over-return/refund, and external-reference abuse tests deny safely.
- POS p95/load/stress, restore/reconciliation, security scans, migration/rollback, observability/runbooks, client/accessibility/localization, and business/security approval evidence passes.
- No online payment, stored card, reservation, offline sale, insurance, branch transfer, or other future feature is enabled.
