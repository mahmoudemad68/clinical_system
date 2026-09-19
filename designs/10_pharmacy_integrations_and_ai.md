# Pharmacy catalog, integrations, and AI screens

Integrated branches expose a read-only availability mirror. Connector credentials and raw records never enter the desktop renderer. Pharmacy AI can read allowed knowledge/live branch stock but cannot mutate inventory, POS, purchasing, returns, or refunds.

## Screen 1 — Catalog and barcode lookup

**Maturity:** Planned, Phases 10–11. **Route concept:** global lookup or `/catalog`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a scanner-first canonical medication lookup with dense result list and a package-conversion detail panel.

- **Purpose:** Resolve a scan or Arabic/English query to one canonical medication/package before inventory, purchasing, or POS actions.
- **Layout:** Large scanner/search input, bounded result list with brand, ingredient, strength, dosage form, manufacturer/package, barcode, and active/retired state; detail side panel shows integer packaging conversions.
- **Content/actions:** Scan, search, choose exact package, copy safe reference, and continue to an authorized workflow. No free-form SQL/filter expression.
- **States:** Loading, no result, ambiguous candidates, exact match, retired/inactive, catalog cached offline, and server unavailable.
- **Precision/accessibility:** Preserve original Arabic/English display text and exact conversion units. Scanner feedback has visual/audio alternatives and candidate cards expose complete accessible names.

## Screen 2 — Public branch availability preview

**Maturity:** Planned, Phase 14. **Route concept:** `/branch/public-preview`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a split preview screen showing the patient-facing branch card/list beside pharmacy-only completeness and freshness notes.

- **Purpose:** Show how the current branch appears to patients without exposing any patient search or location.
- **Layout:** Patient-style branch card/list preview with public identity, address, payment methods, directions destination, availability wording, source freshness, and display language toggle.
- **Content/actions:** Refresh, switch language, open authoritative branch settings, and review stale/read-only explanation.
- **States:** Available, unavailable/omitted per policy, uncertain/stale, integrated mirror delayed, inactive branch, and projection not ready.
- **Privacy:** No exact quantity, price, batch/expiry, cost, connector/vendor name, patient identity, prescription, precise patient point, or branch scope override.

## Screen 3 — Connector setup and status

**Maturity:** Planned, Phase 15; owner-only. **Route concept:** `/integrations/connector`.

**Stitch target:** Desktop — Electron pharmacy owner workspace, 1440 × 900 px. Generate a high-fidelity connector setup/status screen with adapter selection, secret-handoff status, probe/dry-run checklist, and activation timeline.

- **Purpose:** Configure an allowlisted adapter for an integrated branch and communicate whether it is safe to activate.
- **Layout:** Adapter selector, fixed endpoint identity summary, credential handoff status (never the secret), probe/dry-run checklist, reconciliation result, and status timeline.
- **Content/actions:** Select adapter, send credentials directly through approved secret flow, Probe, run dry sync, review mappings/reconciliation, activate through branch-mode transition, and pause.
- **States:** Unconfigured, configuring, probing, auth/schema failed, dry run, reconciliation failed, ready, active, paused, credential rotated, and denied.
- **Rules:** No arbitrary URL, SQL, payload dump, or secret readback. Every risky action confirms branch and may require MFA.

## Screen 4 — Sync runs and freshness

**Maturity:** Planned, Phase 15. **Route concept:** `/integrations/sync-runs`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a high-density sync-monitoring screen with freshness hero, counters, run table, safe error detail, and manual sync/pause controls.

- **Purpose:** Monitor full/incremental runs and the currently promoted mirror without making partial staging visible.
- **Layout:** Freshness hero with last successful `as_of`, active generation, status counts, and run table/detail showing safe page/count/checkpoint/error metadata.
- **Content/actions:** Start manual sync, inspect run, cancel only if contract permits, retry same intent/reconcile, and pause connector.
- **States:** Fresh, stale, running, queued, failed transient/permanent, sync already running, config changed, promotion blocked, paused, and source unavailable.
- **Accessibility/security:** Status uses text/icons and age. No credentials, raw source records, external connection strings, internal hosts, or unrestricted export.

## Screen 5 — Unmatched product mapping review

**Maturity:** Planned, Phase 15. **Route concept:** `/integrations/mappings`.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate a keyboard-efficient mapping review with unmatched-item queue, canonical candidate search, side-by-side comparison, and reasoned confirmation.

- **Purpose:** Map sanitized external product identity to one master medication so it can enter the public mirror.
- **Layout:** Unmatched external item queue, sanitized metadata, canonical candidate search/results, side-by-side comparison, confidence/provenance note, and reasoned confirmation.
- **Content/actions:** Search/select exact master medication, confirm mapping with expected version/reason, skip/escalate, and observe reprojection status.
- **States:** Unmatched, candidates, ambiguous, no candidate, mapped, stale version, reprojecting, failed, and access denied.
- **Rules:** This screen cannot create/edit the master catalog, change branch scope, expose another connector, or reveal raw payloads. Formula-safe cells and full keyboard mapping workflow are required.

## Screen 6 — Pharmacy AI assistant

**Maturity:** Planned, Phase 18; feature- and capability-gated. **Route concept:** `/assistant` or side panel.

**Stitch target:** Desktop — Electron pharmacy workspace, 1440 × 900 px. Generate an AI assistant panel that clearly separates knowledge answers from verified live branch data and keeps normal inventory/POS navigation visible.

- **Purpose:** Answer grounded pharmacy-knowledge questions and exact read-only stock questions for the selected server-owned branch.
- **Layout:** Conversation, composer, branch badge, and response cards explicitly labeled `Knowledge answer`, `Live branch data`, or `Mixed`; live cards show medication identity, exact quantity conversion, source mode, freshness, and `as_of`.
- **Content/actions:** Ask, select an exact medication when ambiguous, Stop, retry as new intent, and navigate to normal inventory UI for permitted manual work.
- **States:** Classifying, retrieving, reading live stock, clarification, stale/uncertain, permission denied, tool unavailable, provider unavailable, cancelled, and capacity-limited.
- **Safety:** AI cannot sell, adjust, refund, reorder, transfer, reserve, substitute, inspect patients, or choose branch scope. Render text as hostile; never show model quantity until structured server validation succeeds.

## Sources

Phases [10](../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), [14](../docs/phases/14_medicine_search_and_prescription_fulfillment.md), [15](../docs/phases/15_external_pharmacy_integrations.md), and [18](../docs/phases/18_pharmacy_ai.md).
