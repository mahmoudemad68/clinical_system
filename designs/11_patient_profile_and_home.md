# Patient profile and home screens

The patient app is phone-first Flutter with Material 3, Arabic/English, RTL/LTR, light/dark themes, scalable text, and server-authoritative navigation. Sensitive data is not cached merely for convenience.

## Screen 1 — Patient profile onboarding

**Maturity:** Planned, Phase 02; follows the partially current registration flow. **Route concept:** `/onboarding/profile`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a high-fidelity Material 3 onboarding step with one primary action, mobile keyboard-safe spacing, and no clinical fields.

- **Purpose:** Create or link the patient profile after phone verification without revealing matching results.
- **Layout:** Short stepper with demographics, health-neutral measurements where approved, review, and submit. Keep one main action per mobile viewport.
- **Content/actions:** Self-reported demographic fields, height/weight/gender/status only as defined, Save/Continue, Back, and final confirmation. National ID is re-entered only if the verified registration intent requires it and is not persisted locally.
- **States:** New/resumed, validation, submitting, generic profile linked/created outcome, manual review pending, optimistic conflict, and offline draft limitations.
- **Safety/accessibility:** Clearly label self-reported fields; no clinical fields or record preview. Keyboard type, input order, hints, and error focus work in Arabic and English.

## Screen 2 — Identity review pending

**Maturity:** Planned, Phases 01–02. **Route concept:** `/onboarding/review`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a calm, privacy-safe pending-review screen with a centered status hero, next steps, and limited actions.

- **Purpose:** Provide a safe holding screen when profile facts conflict or higher-assurance review is required.
- **Layout:** Calm status illustration/icon, generic heading, what happens next, security guidance, reference/request ID when safe, and limited actions.
- **Content/actions:** Refresh status, update allowed contact channel through the high-risk recovery flow, contact support, or sign out. Never show another account/profile or exact match reason.
- **States:** Pending, more evidence required under an approved flow, approved, rejected with safe next step, rate limited, and temporarily unavailable.
- **Privacy:** Wording is identical enough to resist enumeration. The screen contains no National ID, historical phone, clinical content, reviewer notes, or takeover controls.

## Screen 3 — Patient home

**Maturity:** Planned, Phase 08. **Route concept:** `/home`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate the primary Material 3 home screen with top app bar, dominant Today hero, bounded care cards, and bottom navigation.

- **Purpose:** Present the most important current action and bounded entry points to the patient's care journey.
- **Layout:** Hero/Today card followed by quick destinations: Medical AI, Find Medicine, Find Doctor, Appointments, Prescriptions, Medical Record, Labs & Reports, and Chat. Bottom navigation persists primary destinations.
- **Content/actions:** Hero prioritizes upcoming dose, upcoming appointment, pending lab, new prescription, or follow-up; tapping always refetches the source. Secondary sections are bounded and show `as_of` when meaningful.
- **States:** First-use empty, loading skeleton, refreshing, partial/degraded section, stale card, offline, and action no longer available.
- **Safety/accessibility:** Home cards contain minimum lock-screen-safe detail and are not authorization. Clear heading order, semantic card labels, text/icon status, and 200% text reflow.

## Screen 4 — Profile and demographics

**Maturity:** Planned, Phase 02. **Route concept:** `/profile`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a profile summary screen with clear edit/security/settings entry points and a separate mobile form state for demographic editing.

- **Purpose:** View patient account/profile status and edit allowed demographics without exposing protected identity internals.
- **Layout:** Profile summary, verification/link state, language/security shortcuts, and an Edit demographics form in a separate route/sheet.
- **Content/actions:** Edit approved fields with base version, save, resolve conflict by refreshing/reviewing, open sessions, recovery, language, and privacy settings.
- **States:** Loading, current, self-reported pending, saving, validation, version conflict, manual review, account suspended, and offline read-only.
- **Rules:** National ID is masked or absent and never an editable ordinary field. Clinical facts have no edit controls here. Server errors remain field-safe and request IDs are shown only when useful.

## Screen 5 — App language and privacy settings

**Maturity:** Partially current for language; planned for the full settings route. **Route concept:** `/settings`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a native-feeling Material 3 grouped settings list with language, theme, notifications, location, security, privacy, and about sections.

- **Purpose:** Control Arabic/English presentation and understand notification, location, local data, and privacy behavior.
- **Layout:** Grouped settings list: Language, Theme/system, Notifications, Location explanation, Security/sessions, Local data/clear requests, About/status.
- **Content/actions:** Switch language without losing in-flight form intent, open OS permission settings, manage push preference where allowed, clear eligible AI/local data, and open platform status.
- **States:** Saved, OS permission denied/restricted, feature unavailable, clear request pending/completed, and setting rejected by policy.
- **Accessibility/privacy:** Language names remain recognizable in their native scripts. Explain that precise location is requested only just-in-time and discarded; no background tracking toggle exists.

## Screen 6 — Appointments entry and summary

**Maturity:** Planned, Phase 03. **Route concept:** `/appointments` landing.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate an appointment landing screen with next-appointment hero, Upcoming/Past tabs, status-rich cards, and bottom navigation.

- **Purpose:** Provide the home-level appointment summary and navigation into full appointment management.
- **Layout:** Next appointment card, status chips with text, tabs for Upcoming and Past, and prominent Find doctor action. Detailed behavior is expanded in the appointments document.
- **Content/actions:** Open next appointment, view all, find doctor, and resume a booking conflict/reselection when safe.
- **States:** No appointments, booked, checked in, waiting, in consultation, completed, cancelled, no-show, offline snapshot, and stale status/refetch.
- **Rules:** Appointment state never implies medical-record access. Cairo-local date/time, location, appointment type, duration, price, and pay-at-clinic state display from the server snapshot.

## Sources

Phases [01](../docs/phases/01_auth_identity_and_access.md), [02](../docs/phases/02_onboarding_verification_profiles_and_locations.md), [03](../docs/phases/03_scheduling_availability_and_booking.md), and [08](../docs/phases/08_patient_experience_discovery_reviews_and_localization.md).
