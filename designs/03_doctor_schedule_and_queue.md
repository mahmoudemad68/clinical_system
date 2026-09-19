# Doctor schedule and queue screens

The doctor desktop also hosts a capability-gated clinic-staff route; it is not a separate fifth client. Operational staff projections contain booking/check-in facts only, never clinical history.

## Screen 1 — Weekly schedule editor

**Maturity:** Planned, Phase 03. **Route concept:** `/practice/locations/:id/schedule`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity schedule-management screen with persistent navigation, a weekly time grid, and an editable side drawer.

- **Purpose:** Define recurring working intervals for one selected clinic location.
- **Layout:** Week grid with Cairo-local time axis, interval blocks, day toggles, and an editable side drawer. Location selector remains locked in the page header.
- **Content/actions:** Add, resize, duplicate, delete interval; copy day; Save changes; discard. Detect overlap and invalid duration immediately.
- **States:** Loading, empty week, unsaved, saving, version conflict, schedule invalid, and impacted appointments warning.
- **Rules/accessibility:** Never overwrite a newer version silently. Keyboard users can add/edit intervals through equivalent forms; grid color is supplemented with text/pattern. DST policy and effective date are shown when relevant.

## Screen 2 — Schedule exceptions

**Maturity:** Planned, Phase 03. **Route concept:** `/practice/locations/:id/exceptions`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity calendar/list exception screen with impact counts and a right-side editor.

- **Purpose:** Add vacation, holiday, blocked interval, emergency closure, or special working day without rewriting the weekly rule.
- **Layout:** Calendar/list toggle; each exception has type, range, status, and appointment impact count. A side panel edits one exception.
- **Content/actions:** Create exception, review affected appointments, confirm, edit future exception, or cancel through an audited workflow.
- **States:** Draft, conflict, overlapping exception, active/past, impacted appointments, emergency closure, and save failure.
- **Rules:** Existing appointments are flagged operationally, not silently cancelled. Time displays in Cairo with exact UTC-backed instants; every destructive impact receives a clear confirmation.

## Screen 3 — Appointment types and pricing

**Maturity:** Planned, Phase 03. **Route concept:** `/practice/locations/:id/appointment-types`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity data-management screen with an accessible offerings table and create/edit drawer.

- **Purpose:** Configure physical examination, follow-up, consultation, or other approved offerings per location.
- **Layout:** Compact table/cards with name, duration, EGP price, active state, and next availability effect; drawer for create/edit.
- **Content/actions:** Add type, choose approved type, enter integer-safe price display and duration, activate/deactivate, and save with expected version.
- **States:** Empty, active/inactive, invalid price/duration, stale version, referenced-by-bookings warning, and denied.
- **Rules/accessibility:** Server calculates/stores minor units and owns eligibility. Forms announce currency and units, handle Arabic numerals safely, and never allow a client-supplied booking price to become authoritative.

## Screen 4 — Doctor dashboard

**Maturity:** Planned, Phases 04–08. **Route concept:** `/workspace`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate the primary clinical dashboard with left navigation, top context bar, a dominant Current Patient hero, and dense operational cards below.

- **Purpose:** Give the doctor one operational landing page centered on the current patient and today's work.
- **Layout:** Current Patient hero across the top; below it cards/tabs for Waiting Queue, Today's Appointments, Completed, Upcoming, Pending Lab Results, and Follow-ups. A narrow status rail shows connectivity and unresolved work.
- **Content/actions:** Current patient shows name, age/gender, appointment type, location, start time, warnings, allergies, and current medication only when active access permits. Quick actions: Open Record, Prescription, Request Lab, Ask AI, Complete Consultation.
- **States:** No active patient, ready to start, active, access suspended, offline, delayed, sequence gap/refetch, and unresolved consultation.
- **Rules:** Current-patient content appears only after server-confirmed consultation start. Realtime hints refresh the snapshot; they never advance state directly.

## Screen 5 — Day appointments and queue

**Maturity:** Planned, Phase 04. **Route concept:** `/workspace/queue`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate a high-density queue operations screen optimized for keyboard use, with Current, Waiting, and Completed groups.

- **Purpose:** Operate the doctor's assigned queue for a selected location/date.
- **Layout:** Three-column or tabbed groups for Current, Waiting, and Completed/No-show; each row shows safe operational booking facts and status/version. Current row receives strongest hierarchy.
- **Content/actions:** Select waiting appointment, Start consultation, End/Resume current session, mark operational exceptions when capability allows, and refresh after conflict.
- **States:** Loading, empty, approaching delay, stale queue, active consultation exists, offline read-only, start pending/unknown/succeeded, end blocked by clinical requirements, and access suspended.
- **Rules:** Start is explicit and is the only access-grant point. Offline disables start/end/reorder. Conflict recovery refetches before another action and preserves one idempotency key for an ambiguous attempt.

## Screen 6 — Staff check-in and walk-in desk

**Maturity:** Planned, Phases 03–04; capability-gated inside doctor desktop. **Route concept:** `/clinic-desk`.

**Stitch target:** Desktop — Electron clinic-staff route inside the doctor app, 1440 × 900 px. Generate an operational check-in desk with a day list, safe patient lookup, and an action drawer; exclude all clinical content.

- **Purpose:** Let authorized clinic staff check in booked patients or create a walk-in without clinical access.
- **Layout:** Location-scoped day list with search by safe appointment reference; right-side action drawer for Check in, Undo, No-show, or Create walk-in.
- **Content/actions:** Resolve booking, confirm identity using approved minimum fields, check in, create exact-match/unlinked patient through server workflow, and enter a reason for correction/reorder.
- **States:** Not eligible, already checked in, generic match/review result, stale version, duplicate intent, denied, and offline read-only.
- **Privacy/accessibility:** No diagnosis, medications, allergies, labs, prescriptions, notes, or history. Search never reveals whether a National ID exists, and all queue changes have text status plus audited reason.

## Sources

Phases [03](../docs/phases/03_scheduling_availability_and_booking.md) and [04](../docs/phases/04_realtime_queue_and_consultation_control.md), plus `plan.md` sections 18–27.
