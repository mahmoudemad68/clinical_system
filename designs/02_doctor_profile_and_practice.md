# Doctor profile and practice screens

Doctor routes stay unavailable until server status and capabilities permit them. A pending, rejected, or suspended doctor sees onboarding/status only and never clinical navigation or cached clinical content.

## Screen 1 — Doctor profile setup

**Maturity:** Planned, Phase 02. **Route concept:** `/onboarding/profile`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity onboarding screen with a left stepper and wide form canvas; use the clinic teal system and no clinical navigation.

- **Purpose:** Create or resume the doctor's draft professional profile after verified account login.
- **Layout:** Left stepper and right form canvas on desktop: Personal details → Professional details → Specialty → Documents → Review.
- **Content/actions:** Approved non-clinical profile fields, license/professional identifiers, one active specialty, language, Save draft, Continue, and Exit safely. Server-derived requirements appear as a checklist.
- **States:** New draft, saved, unsaved changes, loading reference data, validation, optimistic conflict, suspended account, and resumed submission.
- **Rules:** No patient or clinical content. The renderer stores only ordinary editable UI state; sensitive uploads use opaque file handles through the desktop boundary.

## Screen 2 — Verification documents

**Maturity:** Planned, Phase 02. **Route concept:** `/onboarding/documents`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity verification-upload screen with requirement cards, upload progress, and a sticky completion summary.

- **Purpose:** Collect exactly the documents required for the selected doctor profile and specialty.
- **Layout:** Requirement cards with status, file metadata, Replace/Remove before submission, and a sticky progress summary.
- **Content/actions:** Choose file, upload, cancel, retry, and open safe preview when available. Show accepted type/size before opening the native picker.
- **States:** Required/missing, selecting, uploading with progress, quarantined, scanning, accepted, rejected type/size, scan retryable, and failed. “Uploaded” never means “approved.”
- **Safety/accessibility:** Display safe file name only, no raw path/object key; main process validates type, size, purpose, sender, and cleanup. Progress and status are announced without color-only signaling.

## Screen 3 — Verification review and status

**Maturity:** Planned, Phase 02. **Route concept:** `/onboarding/status`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity status screen centered on the review timeline and the single allowed next action.

- **Purpose:** Review the immutable submission snapshot and follow its decision.
- **Layout:** Status hero, submission timeline, requirement summary, and action footer. Internal reviewer notes/fraud signals never appear.
- **Content/actions:** Submit for review with confirmation, view safe requested-change reasons, edit a new draft after changes requested, and resubmit. Approved state offers Enter workspace.
- **States:** Draft, pending review, claimed/in review, changes requested, rejected, approved, suspended, and stale decision refresh.
- **Rules:** Approval activates navigation only after server capability refresh. Rejection wording is actionable but non-sensitive; no client-side override or “activate anyway” control.

## Screen 4 — Clinic locations

**Maturity:** Planned, Phases 02–03. **Route concept:** `/practice/locations`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity practice-management screen with persistent left navigation, page header, and a location table/card grid.

- **Purpose:** List the doctor's one-to-many practice locations and their readiness.
- **Layout:** Filterable cards/table with public name, address, map thumbnail, status, schedules, appointment types, and staff count. Primary Add location action is capability-gated.
- **Content/actions:** Open, add, archive/deactivate through approved workflow, and preview public listing. Each location makes timezone `Africa/Cairo` explicit.
- **States:** Empty first-location setup, active, incomplete, pending verification, inactive, conflict, and denied.
- **Rules:** Schedule and pricing belong to a location, never to the doctor globally. Coordinates are edited only in the detail screen and are never inferred silently.

## Screen 5 — Location details and staff

**Maturity:** Planned, Phase 02. **Route concept:** `/practice/locations/:id`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity location-detail screen with tab navigation, an editable details canvas, and a staff side panel/table.

- **Purpose:** Maintain the public clinic address/map pin and fixed-scope memberships for one location.
- **Layout:** Header with location identity/status; tabs for Details, Public map, Staff, Schedule, and Appointment types.
- **Content/actions:** Edit address, move/confirm pin, save with version, invite staff, view membership status, and revoke membership with confirmation/reason.
- **States:** Unsaved, saving, stale version, invalid Egypt coordinate, invitation sent/expired/accepted, revocation pending/completed, and capability denied.
- **Safety:** Staff projection contains no clinical data. Invite links do not expose phone handles. Membership changes force scope/realtime refresh; hidden actions are not authorization.

## Screen 6 — Public profile and review preview

**Maturity:** Planned, Phase 08. **Route concept:** `/practice/public-profile`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity split-screen preview: patient-style public profile on the left and doctor completeness/actions on the right.

- **Purpose:** Show the doctor exactly what patients can see across approved directory fields.
- **Layout:** Patient-style profile preview beside a completeness panel. Tabs or sections show specialties, locations, appointment types, prices, next availability, rating, and reviews.
- **Content/actions:** Edit profile/location through authoritative routes, switch preview language, and refresh availability. There is no reply/contact-patient action.
- **States:** Published, incomplete, stale availability with `as_of`, no reviews, rating suppressed/not ready, and directory-disabled.
- **Privacy/accessibility:** Reviews are plain text and pseudonymous; never expose patient identity or appointment proof. Preview supports RTL, large text, keyboard, and the same content hierarchy as mobile.

## Sources

Phases [02](../docs/phases/02_onboarding_verification_profiles_and_locations.md), [03](../docs/phases/03_scheduling_availability_and_booking.md), and [08](../docs/phases/08_patient_experience_discovery_reviews_and_localization.md).
