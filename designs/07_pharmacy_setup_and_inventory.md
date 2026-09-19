# Pharmacy setup and inventory screens

Every pharmacy screen is bound to an active organization membership, branch, capability set, and operating mode. Switching branch clears branch-scoped caches and refetches authority.

## Screen 1 — Organization and first-branch onboarding

**Maturity:** Planned, Phase 02. **Route concept:** `/onboarding/pharmacy`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-fidelity owner onboarding screen with a left stepper, wide form area, branch summary, and no inventory/POS navigation.

- **Purpose:** Let a verified owner create one organization draft and its initial branch.
- **Layout:** Desktop stepper: Organization → Branch → Public location → Documents → Review. A summary rail shows completeness and verification requirements.
- **Content/actions:** Legal/display identity, normalized registration fields, public address/map pin, contact/payment-method setup allowed at this phase, secure document upload, Save draft, and Submit.
- **States:** New/resumed draft, duplicate-safe generic review, upload scanning, validation, optimistic conflict, submitted, and denied.
- **Safety/accessibility:** No inventory or POS navigation appears before approval. Documents use opaque handles; duplicate detection does not disclose another organization. Map confirmation makes the saved public location explicit.

## Screen 2 — Pharmacy verification status

**Maturity:** Planned, Phase 02. **Route concept:** `/onboarding/status`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-fidelity organization/branch verification status screen centered on timeline, requirements, and the one permitted next action.

- **Purpose:** Track the immutable organization/branch submission and respond to safe decision reasons.
- **Layout:** Status hero, organization and branch summary, requirement timeline, and action footer.
- **Content/actions:** Submit, view safe changes-requested reasons, edit a new draft, resubmit, and enter workspace after approval/capability refresh.
- **States:** Draft, pending review, in review, changes requested, rejected, approved, branch incomplete, suspended, and stale status.
- **Rules:** Approval activates organization, first branch, and owner membership together. No client-side partial activation, inventory shortcut, internal reviewer notes, or fraud signals.

## Screen 3 — Branch selector and pharmacy home

**Maturity:** Planned, Phases 10–13. **Route concept:** `/workspace`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate the primary branch-bound home screen with left navigation, top branch/mode/connectivity bar, and capability-based work cards.

- **Purpose:** Establish the current branch context and surface authorized high-priority work.
- **Layout:** Persistent top bar with organization, branch selector, `NATIVE`/`INTEGRATED` badge, online state, and user role; home cards for POS, inventory, alerts, purchasing, sync, and AI according to capability.
- **Content/actions:** Switch branch, open a work area, scan to catalog lookup, and inspect read-only reasons. Branch selection is server-scoped, not a free ID input.
- **States:** No accessible branches, active native, integrated read-only, suspended, capability changed, offline/catalog-only, and cache clearing/refetching.
- **Safety/accessibility:** Changing branch clears cart, financial data, stock queries, and branch-specific draft context. Mode and connectivity use text/icon, remain visible at 200% zoom, and are announced after switch.

## Screen 4 — Memberships and branch roles

**Maturity:** Planned, Phases 02 and 10. **Route concept:** `/branches/:id/team`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-fidelity team-management screen with membership table, fixed role badges, and invite/edit drawer.

- **Purpose:** Manage fixed V1 roles/capabilities such as owner, pharmacist, cashier, inventory, purchasing, and connector reviewer.
- **Layout:** Membership table with person-safe label, role, branch, status, MFA/readiness, and actions; invite/edit drawer.
- **Content/actions:** Invite, assign only allowed role, resend/expire invite, revoke with confirmation/reason, and review resulting capability summary.
- **States:** Invited, expired, pending verification, active, suspended, revoked, version conflict, and forbidden role escalation.
- **Safety:** No arbitrary role designer. UI visibility reflects capabilities but does not enforce them. Revocation invalidates caches/realtime immediately; phone handles do not appear in URLs or broad exports.

## Screen 5 — Branch inventory list

**Maturity:** Planned, Phase 11. **Route concept:** `/inventory`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-density inventory screen optimized for barcode and keyboard use, with persistent branch context, scanner search, filters, and accessible table.

- **Purpose:** Browse server-authoritative medication balances for the current branch.
- **Layout:** Scanner/search field, bounded filters, accessible table with medication/package, friendly total, smallest-unit reference, alert/status, source mode, `as_of`, and row actions.
- **Content/actions:** Scan/search canonical medication, open batches, edit threshold when capable, begin adjustment, and refresh. Catalog cache may support lookup offline; balances do not claim freshness offline.
- **States:** Loading, empty, zero, low stock, expiring, retired medication, stale, integrated mirror read-only, offline/unavailable, denied, and reconciliation required.
- **Precision/accessibility:** Never calculate authoritative stock in the renderer. Tables have semantic headers, keyboard row actions, focus retention after scan, and exact Arabic/English quantity formatting.

## Screen 6 — Batch detail and adjustment

**Maturity:** Planned, Phase 11. **Route concept:** `/inventory/:medication/batches`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-fidelity batch detail screen with FEFO table, stock summary, immutable movement timeline, and guarded adjustment drawer.

- **Purpose:** Explain total stock as immutable per-batch balances and allow a governed adjustment.
- **Layout:** Medication identity header, total summary, FEFO-ordered batch table with batch number/expiry/state/balance/source, immutable movement timeline, and capability-gated adjustment drawer.
- **Content/actions:** Filter eligible/expired/quarantined, inspect movements, enter signed adjustment, reason and evidence reference, confirm, and view immutable receipt.
- **States:** Eligible, expiring, expired, quarantined, zero, concurrent version conflict, negative-stock rejection, pending/unknown, posted, and reconciliation blocked.
- **Rules:** No edit/delete movement. Exact smallest units and package version are submitted. Adjustment requires connectivity and may require MFA/step-up; a timeout reconciles the same intent.

## Sources

Phases [02](../docs/phases/02_onboarding_verification_profiles_and_locations.md), [10](../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), and [11](../docs/phases/11_inventory_batches_fefo_and_alerts.md).
