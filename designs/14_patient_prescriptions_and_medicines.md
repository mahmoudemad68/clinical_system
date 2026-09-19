# Patient prescription and medicine screens

Prescription content is read-only for patients in V1. Medicine availability is an observation with freshness, not a reservation, exact quantity promise, or substitution recommendation.

## Screen 1 — Prescriptions list

**Maturity:** Planned, Phase 06. **Route concept:** `/prescriptions`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a Material 3 prescription list with Current/Previous tabs, correction emphasis, reminder summaries, and Find My Medicines actions.

- **Purpose:** Separate current and previous prescriptions and surface corrections prominently.
- **Layout:** Current/Previous tabs, cards with prescribing doctor/date, active period, corrected badge, medication count, and reminder summary; Find My Medicines on eligible current prescriptions.
- **Content/actions:** Open detail, open availability search, view correction notice, and load older pages.
- **States:** Empty, active, expired/previous, corrected/amended, new/unread, loading, partial failure, and denied.
- **Safety/accessibility:** No renew, adherence, edit, or delete control. Medication names/instructions are not exposed in notifications by default. Corrected state combines banner/icon/text and is announced first.

## Screen 2 — Prescription detail and version history

**Maturity:** Planned, Phase 06. **Route concept:** `/prescriptions/:id`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a scrollable read-only prescription detail with corrected/current banner, medication instruction cards, reminder section, and controlled version history.

- **Purpose:** Read the exact current immutable version, item instructions, active window, reminders, and controlled original history.
- **Layout:** Corrected/current banner, doctor/date/version identity, medication item cards, free notes, active period, reminder schedule, and version timeline.
- **Content/actions:** Switch to original when permitted, download/view authorized document, Find My Medicines, and open reminder details.
- **States:** Current, original, corrected/amended, artifact rendering/unavailable, active/expired, version changed during load, and access denied.
- **Precision/accessibility:** Preserve dose/frequency/route/duration exactly; do not machine-recalculate or truncate. Screen-reader item groups bind medication identity to every instruction.

## Screen 3 — Reminder schedule detail

**Maturity:** Planned, Phase 06. **Route concept:** `/prescriptions/:id/reminders`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a precise reminder-schedule detail with medication header, mode, Cairo-local occurrences, active window, and notification-permission state.

- **Purpose:** Explain doctor-confirmed reminder occurrences without turning them into adherence tracking.
- **Layout:** Medication header, exact mode (`Exact times`, `Interval`, or confirmed generated schedule), Cairo-local upcoming occurrences, active date window, and notification status.
- **Content/actions:** View occurrence schedule and manage general notification permission. No patient edit/skip/taken controls in V1.
- **States:** Active, upcoming, ended, superseded by correction, notifications denied, delivery delayed, and no reminder configured.
- **Rules/accessibility:** Local time accounts for Cairo/DST. Lock-screen preview uses minimum safe data. The UI never records or implies doses taken.

## Screen 4 — Medication search

**Maturity:** Planned, Phase 14. **Route concept:** `/find-medicine`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a search-first medicine finder with scan action, canonical medication results, complete strength/form/package identity, and accessible empty/error states.

- **Purpose:** Resolve a manual Arabic/English name, ingredient, alias, or permitted barcode to a canonical medication.
- **Layout:** Debounced search/scan, bounded canonical results with brand/ingredient/strength/form/package, recent safe selections only if approved, and no-results help.
- **Content/actions:** Search, scan, select medication, request nearby availability with permission, clear, and retry.
- **States:** Initial, typing/debounce, loading, no results, ambiguous, inactive item, query invalid/rate limited, offline, and service unavailable.
- **Privacy/accessibility:** Search terms tied to a user are not logged. Transliteration-safe rendering, RTL input, complete accessible result names, and keyboard/switch support are required.

## Screen 5 — Available branches for one medication

**Maturity:** Planned, Phase 14. **Route concept:** `/find-medicine/:id/branches`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a branch availability list/map screen with freshness disclaimer, distance, payment methods, directions, and no stock quantity or reservation UI.

- **Purpose:** Show public branches that report availability, optionally ordered by distance.
- **Layout:** Medication identity, permission/freshness disclaimer, list/map toggle, and branch cards with availability/uncertainty, rounded distance, payment methods, address, `as_of`, and Directions.
- **Content/actions:** Request location once, adjust bounded radius, open directions with Google Maps disclosure, open branch details, and refresh.
- **States:** Available, uncertain/stale, omitted/unavailable, no nearby result, permission denied, search unavailable, and location cleared.
- **Rules:** Never show price, exact quantity, batches, expiry, source system/vendor, or reservation action.

## Screen 6 — Find My Medicines coverage

**Maturity:** Planned, Phase 14. **Route concept:** `/prescriptions/:id/find-medicines`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a coverage-first results screen where branch cards lead with N/N medicine coverage, then distance, per-item availability, freshness, and directions.

- **Purpose:** Compare branches by how many distinct medications from the latest current active prescription they cover.
- **Layout:** Prescription/version banner, results sorted by coverage `N/N` first then distance, full/partial distinction, per-medication availability matrix, freshness, payment methods, and directions.
- **Content/actions:** Open branch coverage, drill into one medication's branches, directions, refresh, and return to prescription.
- **States:** Full, partial, none, current version changed, prescription inactive/not owned, stale/uncertain, no location, and service failure.
- **Safety/accessibility:** Record one idempotent exposure/access event without changing the prescription. Full/partial uses text and symbols, never implies stock reservation, and precise location is discarded on logout/after use.

## Sources

Phases [06](../docs/phases/06_prescriptions_reminders_and_printing.md) and [14](../docs/phases/14_medicine_search_and_prescription_fulfillment.md).
