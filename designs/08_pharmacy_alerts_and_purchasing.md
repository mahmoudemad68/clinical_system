# Pharmacy alerts and purchasing screens

Purchasing is available only for native branches. Receipts are atomic online stock mutations; an offline draft may preserve form work but never posts inventory.

## Screen 1 — Stock alert center

**Maturity:** Planned, Phase 11. **Route concept:** `/alerts`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-density alert center with summary cards, urgency filters, accessible table, and persistent branch context.

- **Purpose:** Triage low-stock and 30/60/90-day expiry alerts for the selected branch.
- **Layout:** Summary counts, type/status filters, and table grouped by urgency; each row shows medication, exact threshold/date, current safe balance, state, updated time, and action.
- **Content/actions:** Open inventory/batch detail, update allowed threshold, acknowledge viewing if policy defines it, and refresh. Resolving conditions close alerts automatically; staff do not delete them.
- **States:** Open, resolved, low, expiring, expired, stale, delayed evaluation, empty, offline, and reconciliation required.
- **Accessibility:** Urgency combines icon, text, date/difference, and color. Sorting remains deterministic in RTL, and screen readers receive a concise alert summary before table details.

## Screen 2 — Owner multi-branch overview

**Maturity:** Planned, Phases 11 and 13. **Route concept:** `/owner/overview`.

**Stitch target:** Desktop — Electron pharmacy owner workspace, 1440 × 900 px. Generate a high-fidelity multi-branch overview with aggregate cards, branch comparison table, freshness labels, and no transfer control.

- **Purpose:** Give authorized owners safe aggregate visibility across their own branches.
- **Layout:** Organization header; cards for branch status, stock/alert counts, sales/invoice aggregates, sync state, and performance; branch comparison table with explicit `as_of`.
- **Content/actions:** Filter date/branch, open a scoped branch view, and request bounded audited export if enabled. No cross-organization rows or branch-transfer action.
- **States:** Empty, zero, stale, suppressed where appropriate, partial/degraded branch, denied, and source unavailable.
- **Rules:** Aggregates use separate server projections; one branch's staff cannot infer another. Branch-to-branch transfer is a future feature and is absent, not a disabled operational button.

## Screen 3 — Suppliers

**Maturity:** Planned, Phase 12. **Route concept:** `/purchasing/suppliers`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-fidelity supplier-management screen with bounded search, data table, and create/edit drawer.

- **Purpose:** Maintain bounded supplier records used by purchase orders; no supplier API integration.
- **Layout:** Searchable supplier table with status and safe contact summary; create/edit drawer with version marker.
- **Content/actions:** Create, edit allowed fields, deactivate through approved workflow, and open related PO list. Fields are limited and labels distinguish legal/contact data.
- **States:** Empty, active/inactive, validation, duplicate-safe conflict, optimistic conflict, save pending, denied, and offline read-only.
- **Privacy/accessibility:** Supplier sensitive data is minimized and never placed in telemetry/export by default. Form errors summarize at top and link focus to invalid fields.

## Screen 4 — Purchase order list and detail

**Maturity:** Planned, Phase 12. **Route concept:** `/purchasing/orders` and `/purchasing/orders/:id`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a master-detail purchase-order screen with filters/table on the left or top and immutable order/receipt detail in the main canvas.

- **Purpose:** Find POs and understand ordered, received, and remaining quantities without editing history.
- **Layout:** Status/filterable list; detail header with supplier, branch, dates, currency/total, version, and `DRAFT`, `ORDERED`, `PARTIALLY_RECEIVED`, or `RECEIVED` state; line table and receipt timeline.
- **Content/actions:** Create draft, continue editing draft, submit order, receive outstanding goods, open immutable receipt, print/export through purpose-specific capability.
- **States:** Empty, draft, ordered, partial, received, stale version, source medication retired, and reconciliation blocked.
- **Rules:** Money and quantities show exact units. Posted receipts are not editable/deletable; corrections link to reasoned adjustments.

## Screen 5 — Purchase order editor

**Maturity:** Planned, Phase 12. **Route concept:** `/purchasing/orders/new` or draft edit.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a keyboard/barcode-first PO editor with supplier header, editable line table, totals rail, and sticky save/place-order actions.

- **Purpose:** Build a branch-bound draft PO from active suppliers and catalog packages.
- **Layout:** Supplier/date header, barcode/search add control, editable line table, totals summary, and sticky Save draft / Place order actions.
- **Content/actions:** Add line, choose package, enter count/cost, remove draft line, save, review, and confirm Place order.
- **States:** Unsaved, locally retained draft, saving, version conflict, invalid/retired item, packaging version conflict, placing order, unknown outcome, and ordered.
- **Safety/accessibility:** Server computes conversions/totals and revalidates on order. Scanner focus is deliberate; table editing supports keyboard and error summary. No automatic order or receive on opening.

## Screen 6 — Full or partial goods receipt

**Maturity:** Planned, Phase 12. **Route concept:** `/purchasing/orders/:id/receive`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-fidelity goods-receipt screen with outstanding quantities, editable receipt lines, batch/expiry/cost inputs, and a separate review/confirmation panel.

- **Purpose:** Confirm the actual shipment and atomically create batches, movements, balances, and receipt.
- **Layout:** PO identity/outstanding summary, receipt number, line grid with ordered/received/remaining, default received quantity, batch number, expiry, and cost; review panel before Post receipt.
- **Content/actions:** Adjust actual quantity, scan item, complete batch metadata, omit zero lines, review exact stock impact, confirm, and poll same outcome after timeout.
- **States:** Draft form, offline retained/not submittable, validation, partial/full preview, posting, unknown/reconciling, succeeded with immutable receipt, version/outstanding conflict, and inventory blocked.
- **Rules:** Default remaining quantity is visibly prefilled but never silently posted. One idempotency key survives retry. No fresh duplicate action is offered during ambiguity.

## Sources

Phases [11](../docs/phases/11_inventory_batches_fefo_and_alerts.md), [12](../docs/phases/12_purchasing_and_goods_receipt.md), and [13](../docs/phases/13_pos_invoices_returns_and_refunds.md).
