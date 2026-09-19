# Patient appointments, queue, and record screens

Patient clinical surfaces are read-only. Booking/check-in/queue state does not grant the patient or doctor any extra record mutation capability.

## Screen 1 — Appointment list

**Maturity:** Planned, Phase 03. **Route concept:** `/appointments`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a Material 3 appointment list with Upcoming/Past tabs, next-appointment hero, lifecycle cards, and bottom navigation.

- **Purpose:** Browse upcoming and past appointments with unambiguous lifecycle state.
- **Layout:** Tabs for Upcoming/Past, optional status filters, next appointment hero, and chronological cards with doctor, location, type, time, price/payment state, and status.
- **Content/actions:** Open detail, find doctor, load more, and refresh. Review action appears only on eligible completed appointments.
- **States:** Empty, booked, cancelled, checked in, waiting, in consultation, completed, no-show, operational conflict, stale/offline, and failed.
- **Accessibility:** State is text/icon, dates have full accessible labels, cards remain usable at large text, and RTL does not reverse chronological meaning incorrectly.

## Screen 2 — Appointment detail and management

**Maturity:** Planned, Phase 03. **Route concept:** `/appointments/:id`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a scrollable appointment detail with status timeline, exact booking facts, directions, and capability-based cancel/reschedule/review actions.

- **Purpose:** Show the canonical appointment snapshot and allowed next actions.
- **Layout:** Status hero/timeline; doctor, location/directions, offering, Cairo time, duration, EGP price/pay-at-clinic, cancellation policy, and notification summary.
- **Content/actions:** Cancel with reason/confirmation, reschedule atomically, directions, join queue-status view after check-in, and write one eligible review after completion.
- **States:** Action eligible/ineligible with reason, cancellation/reschedule pending, unknown outcome, conflict, completed/no-show, and resource no longer available.
- **Rules:** Reschedule is not a client-side cancel-plus-book sequence. Old appointment remains if new slot loses a race. No record content appears merely because an appointment exists.

## Screen 3 — Own queue status

**Maturity:** Planned, Phase 04. **Route concept:** `/appointments/:id/queue`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a reassuring live queue-status screen with large state hero, number ahead, approaching/delayed copy, last-updated state, and no other-patient data.

- **Purpose:** Reassure the checked-in patient using their own safe queue projection.
- **Layout:** Large state hero, number ahead, `approaching`/`delayed` wording, doctor/location/appointment summary, last updated/version, and refresh/reconnect indicator.
- **Content/actions:** Refresh, open appointment detail, enable notifications if desired, and view directions/contact workflow when approved.
- **States:** Checked in, waiting, approaching, delayed, in consultation, completed, no-show/corrected, reconnecting, sequence gap, stale, and unavailable.
- **Privacy/accessibility:** Never show other patient names, appointment types, reasons, or exact identifiers. Delay is advisory, not a promised minute count. Status changes are announced politely and not by color alone.

## Screen 4 — Medical record timeline

**Maturity:** Planned, Phase 05. **Route concept:** `/records`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a read-only medical record with active-facts summary, chronological cards/timeline, bounded filters, and no edit affordances.

- **Purpose:** Present the patient's bounded longitudinal record read-only with provenance and correction markers.
- **Layout:** High-level active facts plus chronological encounter timeline; filters for visits, prescriptions, labs/files, and reports; pagination/load more.
- **Content/actions:** Expand an entry, open a current version, open authorized document, and view correction provenance. No edit/delete controls or hidden mutation calls.
- **States:** Empty, loading, partial/degraded, corrected, unavailable section, denied, offline unavailable, and pagination failure.
- **Privacy/accessibility:** Do not persist the clinical timeline offline by default. Use semantic timeline/headings, meaningful current/original labels, screen-reader summaries, and safe app-switcher behavior.

## Screen 5 — Encounter detail

**Maturity:** Planned, Phase 05. **Route concept:** `/records/encounters/:id`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a structured read-only encounter detail with doctor/date header and collapsible patient-visible clinical sections.

- **Purpose:** Read one completed visit and its patient-visible contributions.
- **Layout:** Encounter header with doctor/location/date/type; sections for diagnosis, instructions, follow-up, related prescription, labs, files, and documents according to projection.
- **Content/actions:** Open related resource, view current/corrected version and provenance, and return to timeline.
- **States:** Current, corrected, related file processing/unavailable, partial projection, not found/denied, and session expired.
- **Rules:** Patient cannot edit diagnosis, notes, prescription, lab review, or any clinical fact. Missing content is not presented as evidence that none exists.

## Screen 6 — Follow-up and correction history

**Maturity:** Planned, Phases 05–08. **Route concept:** nested encounter/history view.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a clear correction/version history screen with current banner, append-only timeline, original-version access, and follow-up card.

- **Purpose:** Explain append-only corrections and current follow-up instructions without making the original appear deleted.
- **Layout:** Current-version banner, correction timeline with author/time/reason category, original-version access when policy permits, and next follow-up card.
- **Content/actions:** Switch current/original, open follow-up booking discovery, and acknowledge a correction notification where applicable.
- **States:** No correction, corrected, original available/restricted, new correction unread, follow-up due/completed, and related resource unavailable.
- **Safety/accessibility:** Use plain language such as “Updated version” and “Original preserved.” Never expose audit IP/device or internal reviewer notes.

## Sources

Phases [03](../docs/phases/03_scheduling_availability_and_booking.md), [04](../docs/phases/04_realtime_queue_and_consultation_control.md), [05](../docs/phases/05_clinical_records_encounters_and_local_resilience.md), and [08](../docs/phases/08_patient_experience_discovery_reviews_and_localization.md).
