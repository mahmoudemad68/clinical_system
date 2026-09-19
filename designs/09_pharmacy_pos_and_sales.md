# Pharmacy POS and sales screens

POS is keyboard/barcode-first, branch-bound, and online-authoritative. No card number, CVV, expiry, PIN, track, or cardholder field exists anywhere in the product.

## Screen 1 — POS cart

**Maturity:** Planned, Phase 13. **Route concept:** `/pos`.

**Stitch target:** Desktop — Electron pharmacy POS, 1440 × 900 px. Generate a high-fidelity barcode-first checkout screen with persistent scanner focus, large cart table, totals/payment sidebar, and branch/online/mode banner.

- **Purpose:** Build a sale quickly while preserving exact catalog identity, server pricing, and FEFO allocation authority.
- **Layout:** Always-focused scanner/search at top, product results/quick add, large cart table, totals/payment sidebar, and persistent branch/online/mode banner.
- **Content/actions:** Scan/search, select exact package, change smallest-unit-safe quantity, remove line, select configured discount, refresh price, clear cart with confirmation, and proceed to payment.
- **States:** Empty, item added, ambiguous barcode, retired item, price changed, insufficient stock preview, offline blocked, integrated branch read-only, reconciliation required, and unsent encrypted UI draft.
- **Accessibility:** Large controls, audible/visual scan confirmation, keyboard shortcuts with visible alternatives, focus restoration after errors, exact EGP/quantity announcements, and no color-only cart warnings.

## Screen 2 — Payment confirmation

**Maturity:** Planned, Phase 13. **Surface:** guarded second step from POS cart.

**Stitch target:** Desktop — Electron pharmacy POS, 1440 × 900 px. Generate a guarded payment-confirmation step with locked order summary, large cash/card choices, and explicit external-terminal instructions without card-entry fields.

- **Purpose:** Review canonical totals and record cash or an already completed external terminal result.
- **Layout:** Locked item/totals summary, configured payment method cards, and final confirmation region.
- **Content/actions:** Choose Cash or Card if enabled. Card instructions tell staff to complete the approved external terminal operation, then enter/scan only the opaque approved reference/status. Confirm sale or return to cart.
- **States:** Price/version refresh required, method disabled, terminal reference invalid/reused, submitting, unknown outcome/reconciling, failed without allocation, and succeeded.
- **Rules:** The platform never initiates card processing. Final button preserves one idempotency key; duplicate clicks remain disabled but recovery relies on server reconciliation.

## Screen 3 — Sale result and receipt

**Maturity:** Planned, Phase 13. **Route concept:** `/pos/sales/:invoiceId/result`.

**Stitch target:** Desktop — Electron pharmacy POS, 1440 × 900 px. Generate a clear committed-sale result screen with success hierarchy, immutable receipt summary, print/reprint state, and a dominant Start next sale action.

- **Purpose:** Confirm exactly one committed invoice and offer safe receipt handling.
- **Layout:** Success/known-outcome hero, invoice ID/time/branch/payment summary, immutable line/totals, print status, and next-sale action.
- **Content/actions:** Print/reprint canonical server receipt, view invoice, start next sale, and retry printing only. Printing never resubmits the sale.
- **States:** Committed, print generating, printing, cancelled by user, print failed/reprint, unknown sale outcome still reconciling, and receipt unavailable.
- **Safety/accessibility:** Receipt rendering is escaped and verified. No terminal/card data is displayed beyond approved method and safe reference policy. Announce success once and move focus to the outcome heading.

## Screen 4 — Invoice search and detail

**Maturity:** Planned, Phase 13. **Route concept:** `/sales/invoices` and `/sales/invoices/:id`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a master-detail invoice screen with bounded search/filter table and an immutable sales/returns/payment history panel.

- **Purpose:** Locate branch invoices and inspect immutable sale, allocation/payment state, returnability, and audit-safe history.
- **Layout:** Bounded filters/table; detail shows `PAID`, `CANCELLED`, `PARTIALLY_RETURNED`, or `RETURNED`, exact totals, lines, payments, returns, and movement links.
- **Content/actions:** Open, reprint, begin cancellation or return if capable/eligible, and request bounded audited export for owner capability.
- **States:** Empty, no match, paid, cancelled, partial/returned, stale version, action denied, and reconciliation warning.
- **Rules:** No edit/delete invoice. Search is branch-scoped. Exports are formula-safe and omit terminal/card data and another organization.

## Screen 5 — Invoice cancellation

**Maturity:** Planned, Phase 13. **Route concept:** guarded dialog/page from invoice.

**Stitch target:** Desktop — Electron pharmacy workspace overlay inside a 1440 × 900 px frame. Generate a destructive-but-calm cancellation review with invoice impact, reason field, step-up status, and separated confirmation.

- **Purpose:** Reverse an eligible entire paid invoice with linked stock movements and payment state.
- **Layout:** Invoice impact summary, original lines/amount, eligibility statement, mandatory reason, step-up indicator, and destructive confirmation.
- **Content/actions:** Enter approved reason, record external terminal void/refund reference for card only after external action, review, confirm, or cancel.
- **States:** Eligible, incompatible prior return, version conflict, step-up required, pending, unknown/reconciling, cancelled, terminal reference rejected, and inventory block.
- **Rules/accessibility:** Invoice is never deleted. Exact reverse effect is shown before submit. Confirmation wording names invoice and amount, and focus cannot accidentally land on confirm first.

## Screen 6 — Return and refund

**Maturity:** Planned, Phase 13. **Route concept:** `/sales/invoices/:id/return`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-fidelity return/refund editor with eligible-item quantities, disposition controls, computed refund summary, and guarded final review.

- **Purpose:** Return selected quantities and record a proportional refund without over-returning or silently restocking.
- **Layout:** Eligible item table with sold/already returned/remaining, quantity controls, per-item `RESTOCKABLE` or `NON_RESTOCKABLE` disposition, reason, computed refund, and review step.
- **Content/actions:** Select items, enter quantities/reason, choose allowed disposition, record approved opaque external card-refund reference, review exact amount/stock effect, and confirm.
- **States:** Validation, quantity/refund exceeded, disposition denied, concurrent return conflict, pending, unknown/reconciling, partial/full return success, and print/reprint return document.
- **Rules:** Server computes returnability and refund. One intent, no original edits/deletes, and no card fields or automated external refund.

## Sources

Phase [13](../docs/phases/13_pos_invoices_returns_and_refunds.md) and `plan.md` sections 58–61.
