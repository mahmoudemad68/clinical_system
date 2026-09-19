# Patient communication, reviews, and support screens

Notification routing always refetches and reauthorizes the opaque resource. Post-visit chat is text-only for 48 hours and then remains read-only; it is not an emergency service.

## Screen 1 — Notification center

**Maturity:** Planned, Phase 09. **Route concept:** `/notifications`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a Material 3 notification center with unread/read grouping, type filters, privacy-safe cards, and bottom navigation.

- **Purpose:** Present durable appointment, queue, prescription/reminder, correction, lab, chat, and follow-up notices.
- **Layout:** Unread/read sections, type filters, safe notification cards, timestamp, and related-resource action; unread count appears in the app shell.
- **Content/actions:** Open and refetch resource, mark read, mark all read if approved, manage notification settings, and load more.
- **States:** Loading, empty, unread/read, offline bounded cache, delivery delayed, resource expired/not authorized, push token invalid, and session revoked.
- **Privacy/accessibility:** Lock-screen copy contains minimum safe detail. Type plus opaque ID never authorizes access. Clear cached notices on logout/revocation and announce unread-count changes without repeated noise.

## Screen 2 — Chat inbox

**Maturity:** Planned, Phase 09. **Route concept:** `/chat`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a chat inbox with Writable/Read-only grouping, doctor/encounter context, deadlines, unread counts, and no new-chat action.

- **Purpose:** List only threads in which the current patient is an exact participant.
- **Layout:** Writable and Read-only groups; cards show doctor, encounter/date, unread count, safe last preview if approved, and exact writable deadline.
- **Content/actions:** Open thread, filter, refresh, and load more. No arbitrary doctor search or new-chat button.
- **States:** Empty, writable, expiring, read-only, unread, offline, reconnecting, and access revoked.
- **Safety/accessibility:** Explain that chat is post-visit, text-only, and not monitored for emergencies. Deadline/status has text and icon; list navigation works with screen reader and switch control.

## Screen 3 — Chat thread

**Maturity:** Planned, Phase 09. **Route concept:** `/chat/:id`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a keyboard-safe text-chat thread with non-emergency banner, exact deadline, paginated messages, connection state, and composer/read-only variant.

- **Purpose:** Exchange bounded text during the server-controlled window and retain history afterward.
- **Layout:** Doctor/encounter header, deadline/non-emergency banner, paginated message stream, connection state, and composer above the keyboard/safe area.
- **Content/actions:** Compose/send, retry the same pending message, cancel local text, load older messages, and return. No attachment, voice, image, or emergency escalation control in V1.
- **States:** Sending by client message ID, delivered, unknown/reconciling, failed, offline, version conflict, expired/read-only, and participant denied.
- **Accessibility/privacy:** Correct bidi handling per message, accessible sender/time labels, keyboard-safe composition, encrypted bounded local cache only, and cleanup on logout/revocation/retention.

## Screen 4 — Appointment review form

**Maturity:** Planned, Phase 08. **Route concept:** `/appointments/:id/review`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate an accessible review form with appointment summary, labeled rating radio group, bounded comment, privacy guidance, and one submit action.

- **Purpose:** Allow exactly one review for an appointment owned by the patient and completed.
- **Layout:** Doctor/appointment summary, accessible 1–5 rating control, bounded plain-text comment, privacy guidance, moderation explanation, and Submit.
- **Content/actions:** Choose rating, enter optional comment, review warning not to disclose medical/personal details, submit once, or cancel.
- **States:** Eligible, validation, pending moderation, published, duplicate conflict, appointment ineligible/cancelled/no-show, policy changed, and rate limited.
- **Privacy/accessibility:** Rating is a radio group with text labels, not gesture-only stars. Links/control characters are rejected per policy; no clinical content is requested.

## Screen 5 — My reviews

**Maturity:** Planned, Phase 08. **Route concept:** `/reviews`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a simple My Reviews list with doctor/date/rating cards and clear moderation statuses.

- **Purpose:** Show the patient's submitted reviews and their moderation state.
- **Layout:** Chronological cards with doctor, appointment date, rating, safe comment preview, and `Pending`, `Published`, `Hidden`, or `Removed` status with reason category when approved.
- **Content/actions:** Open full review/status and navigate to doctor profile. Editing/deleting is absent unless a future explicit policy adds a versioned workflow.
- **States:** Empty, pending moderation, published, hidden/removed, reconciliation update, and unavailable.
- **Rules:** Never imply that review moderation changes the appointment or medical record. Status and rating remain accessible in RTL and large text.

## Screen 6 — Help, about, and status

**Maturity:** Planned shell refinement; health panel itself is current. **Route concept:** `/help`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a Help/About screen with support topics, privacy/emergency disclaimers, app version/update state, and compact platform-health card.

- **Purpose:** Provide support-safe product/version information, privacy/help links, platform status, and a request ID path without exposing internal systems.
- **Layout:** Help topics, contact/support route, privacy and emergency disclaimers, client version/update state, language, and compact Core/realtime/AI health card.
- **Content/actions:** Retry health, copy request ID after an error, open validated external links after explicit intent, and check for required update.
- **States:** Operational, degraded AI, offline, update available/required, unsupported version, and support unavailable.
- **Safety/accessibility:** External links are allowlisted and never carry auth data. This is not a clinician/emergency contact surface; wording provides approved emergency direction without promising dispatch.

## Sources

Phases [08](../docs/phases/08_patient_experience_discovery_reviews_and_localization.md) and [09](../docs/phases/09_notifications_and_post_visit_chat.md).
