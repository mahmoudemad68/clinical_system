# Doctor prescription, lab, and document screens

Finalized prescriptions and clinical documents are append-only/versioned. Printing and file viewing use canonical server artifacts through narrow desktop capabilities.

## Screen 1 — Prescription editor

**Maturity:** Planned, Phase 06. **Route concept:** `/encounters/:id/prescription`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity multi-item prescription editor with medication search, structured item rows/cards, and persistent draft/sync state.

- **Purpose:** Build a structured multi-item prescription inside the current encounter.
- **Layout:** Medication search/add area, editable item cards/table, prescription summary, and sticky Draft/sync/version bar.
- **Content/actions:** Search approved medication references, choose exact item, enter dose, frequency, duration, dates, route, bounded note, and reminder mode; update/remove with explicit focus management.
- **States:** Empty draft, local save, syncing, conflict, retired medication, validation error, reference unavailable, and locked after finalization/exposure.
- **Safety/accessibility:** Medication snapshot remains immutable even if catalog changes. Search is reference-only, not stock or advice. Quantity/time precision survives Arabic/English formatting and screen-reader labels associate every instruction with its medication.

## Screen 2 — Prescription review, finalize, correct, or amend

**Maturity:** Planned, Phase 06. **Route concept:** `/prescriptions/:id/review`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity prescription review screen with a complete read-only summary, state/version timeline, and guarded finalize/correct/amend actions.

- **Purpose:** Make irreversible/versioned transitions explicit and understandable.
- **Layout:** Read-only complete prescription, validation checklist, state timeline (`Draft`, `Finalized`, `Exposed`, `Amended`), current/original version selector, and action panel.
- **Content/actions:** Confirm exact reminders, Finalize, correct before exposure, or amend after exposure with mandatory reason and full-content review.
- **States:** Validation blocked, finalizing, finalized, exposed, corrected/amended, competing version conflict, ambiguous outcome, and notification pending.
- **Rules:** No destructive replace/delete. Patient-visible correction consequences are explained. Same-key retry reconciles; a different payload conflicts.

## Screen 3 — Prescription print preview

**Maturity:** Planned, Phase 06. **Route concept:** modal/window from prescription detail.

**Stitch target:** Desktop — Electron doctor workspace document window, 1200 × 850 px. Generate a high-fidelity PDF preview with artifact/version identity and a minimal verified print action bar.

- **Purpose:** Preview and print the exact canonical PDF version/hash after exposure is recorded.
- **Layout:** Artifact identity header, sandboxed document preview, version/hash metadata, printer action, and close/retry controls.
- **Content/actions:** Generate preview, Print, cancel OS dialog, retry download, or reprint the same artifact. No editable fields or arbitrary file path/URL input.
- **States:** Rendering, ready, artifact verification failed, download expired, print cancelled, print failed, and reprint available. Cancellation cannot undo already recorded exposure.
- **Safety/accessibility:** Main process downloads, bounds, verifies, temporarily stores, prints, and cleans up. Provide accessible document summary when embedded PDF is not screen-reader usable.

## Screen 4 — Lab request editor

**Maturity:** Planned, Phase 07. **Route concept:** `/encounters/:id/labs/new`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity lab-request editor with catalog search, selected-test table, instructions, and encounter identity.

- **Purpose:** Create a request from approved lab catalog items or bounded custom tests during an active encounter.
- **Layout:** Search/select panel, selected tests list, per-item instructions, and review footer tied to current patient/encounter.
- **Content/actions:** Add/remove/reorder tests, add bounded instructions, review, and Submit request with one intent.
- **States:** Empty, validation error, duplicate item, stale encounter/request, submitting, committed, and denied after access changes.
- **Safety/accessibility:** Patient/doctor identity comes from active context, not form fields. The final summary announces count and custom entries; no AI interpretation control appears in this phase.

## Screen 5 — Pending lab results and secure viewer

**Maturity:** Planned, Phase 07. **Route concept:** `/workspace/labs` and detail.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity pending-results queue with a secure PDF/image detail viewer and explicit confirm/review actions.

- **Purpose:** Triage uploaded/physically delivered results and record doctor confirmation/review.
- **Layout:** Queue with status, patient safe identity, request date, source/provenance, and current version; detail opens approved PDF/image in a sandboxed viewer beside action history.
- **Content/actions:** Open current file, Confirm received, Mark reviewed, retry a safe download, and return to encounter when authorized.
- **States:** Requested, patient-marked delivered, quarantined/processing, available/uploaded, doctor-confirmed, reviewed, rejected, version conflict, and access denied.
- **Safety:** Patient “delivered” is clearly unverified. File access is reauthorized/audited; raw path, object key, signed URL, or unsafe file type never reaches the renderer.

## Screen 6 — Medical report, sick leave, and referral editor

**Maturity:** Planned, Phase 07. **Route concept:** `/encounters/:id/documents`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity structured clinical-document editor with template controls, version timeline, preview, and guarded finalization.

- **Purpose:** Create versioned structured clinical documents from approved templates for an active or doctor-owned encounter.
- **Layout:** Document type/template selector, patient/current-version identity banner, structured fields, preview panel, and version timeline.
- **Content/actions:** Save draft, finalize, correct with reason, render, print/download through approved capability, and inspect prior versions.
- **States:** Draft, template outdated, validation error, finalized, rendering, current/corrected, print failure, and stale version.
- **Rules/accessibility:** Server supplies identity and template version; escaped typed rendering prevents injection. Prior artifacts remain preserved. Arabic/English templates are separately approved, not ad hoc machine translations.

## Sources

Phases [06](../docs/phases/06_prescriptions_reminders_and_printing.md) and [07](../docs/phases/07_labs_files_reports_and_referrals.md).
