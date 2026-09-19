# Doctor AI and communication screens

AI is optional, read/recommend-only, and never required for core clinical work. Chat is encounter-scoped and becomes read-only after the server deadline.

## Screen 1 — Doctor AI assistant

**Maturity:** Planned, Phase 17; feature- and capability-gated. **Route concept:** right panel or `/assistant`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate a high-fidelity AI assistant as a resizable right panel beside the clinical canvas, with explicit mode, scope, streaming state, and “not saved” notice.

- **Purpose:** Ask specialty-scoped questions generally or use minimum authorized context during an active consultation.
- **Layout:** Mode selector (`General specialty` / `Current consultation`), conversation history, composer, source/scope notice, and persistent “Not saved to the medical record” banner.
- **Content/actions:** Ask, Stop generation, retry as a new intent, switch/select conversation, and select response text for explicit copy. Encounter mode is enabled only by server state.
- **States:** Retrieving, generating, streaming, cancelled, degraded, scope denied, access revoked mid-run, capacity/rate limited, output invalid, and core still available.
- **Safety/accessibility:** Sanitize active Markdown/links, minimize screen-reader streaming chatter, never translate clinical content without approved provenance, and do not persist unrestricted AI context locally.

## Screen 2 — Copy AI suggestion to notes

**Maturity:** Planned, Phase 17. **Surface:** guarded modal from AI response.

**Stitch target:** Desktop — Electron doctor workspace overlay inside a 1440 × 900 px frame. Generate a focused editable-confirmation modal that clearly separates the original AI suggestion from the doctor-authored note.

- **Purpose:** Convert selected AI text into an ordinary doctor-authored note only after visible human review.
- **Layout:** Current patient/encounter banner, provenance notice, editable text area prefilled with selected text, character/validation feedback, and side-by-side original when helpful.
- **Content/actions:** Edit, Cancel, and Add to notes. Submit uses a fresh idempotency key and current record version.
- **States:** Editing, validation error, stale encounter, consultation completed, access revoked, saving, committed, and conflict.
- **Rules:** No background copy, automatic save, prescription action, or AI override. Confirmation explicitly says the doctor becomes the author responsible for the edited note.

## Screen 3 — Private knowledge library

**Maturity:** Planned, Phase 16. **Route concept:** `/knowledge`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate a high-fidelity private knowledge library with a version/status table, upload drawer, and activation timeline.

- **Purpose:** Let a verified doctor manage private, specialty-context documents without publishing shared content.
- **Layout:** Document table with title, language, version, scope, ingestion status, updated time, and active marker; upload/version drawer and detail timeline.
- **Content/actions:** Select clean file through opaque picker, add provenance, upload version, inspect safe manifest/status, retry same intent, activate ready version, and roll back to retained ready version where allowed.
- **States:** Uploading, quarantined, processing, ready, failed with safe reason, active, inactive, and AI platform unavailable.
- **Safety:** Scope is displayed but server-derived; no shared publish control, raw chunks, embeddings, Qdrant/provider control, object paths, or arbitrary file access.

## Screen 4 — Doctor notifications

**Maturity:** Planned, Phase 09. **Route concept:** notification drawer/center.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate a high-fidelity notification drawer over the normal shell plus its full-center empty state, using privacy-safe previews.

- **Purpose:** Present durable appointment, queue, correction, lab, chat, and follow-up notifications with privacy-safe previews.
- **Layout:** Unread/read groups, type icon/text, safe summary, time, and filter. Desktop local notification click opens the matching opaque resource then refetches.
- **Content/actions:** Open, mark read, mark all read if approved, and retry refresh. No notification body becomes authorization.
- **States:** Loading, empty, offline cached headers, unread, read, resource no longer available, session revoked, and sequence gap.
- **Accessibility/privacy:** Avoid clinical free text and sensitive lock-screen content; announce unread counts; use exact localized time; clear cache on logout/revocation.

## Screen 5 — Post-visit chat inbox

**Maturity:** Planned, Phase 09. **Route concept:** `/chat`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity chat inbox with writable/read-only groups, deadlines, unread status, and no new-thread action.

- **Purpose:** List encounter-scoped conversations opened after completed consultations.
- **Layout:** Thread list with patient safe identity, encounter reference/date, unread count, last safe preview if policy permits, and writable-until/read-only badge.
- **Content/actions:** Open thread, filter unread/read-only, and refresh. There is no new-thread or arbitrary patient search action.
- **States:** Empty, writable, expiring soon, read-only, offline, stale/reconnect, access denied, and archived by retention policy.
- **Safety/accessibility:** Exact participant scope is server-derived. Deadline is shown as server fact; local clock never authorizes sending. List supports keyboard navigation and non-color status cues.

## Screen 6 — Post-visit chat thread

**Maturity:** Planned, Phase 09. **Route concept:** `/chat/:threadId`.

**Stitch target:** Desktop — Electron doctor workspace, 1440 × 900 px. Generate one high-fidelity text-chat thread with encounter header, exact deadline, paginated messages, connection status, and bottom composer/read-only state.

- **Purpose:** Exchange bounded text with the patient during the 48-hour post-visit window, then preserve read-only history.
- **Layout:** Participant/encounter header, exact deadline, paginated message stream, reconnect indicator, and keyboard-safe composer fixed to the bottom.
- **Content/actions:** Send text, retry the same pending message, cancel local pending edit, load older messages, and return to inbox.
- **States:** Sending optimistically by client message ID, delivered, ambiguous/reconciling, failed, offline, reconnecting, version conflict, deadline expired, and read-only.
- **Safety:** Realtime carries IDs only; API refetch supplies message bodies. No attachments/emergency channel in V1. The coming-soon emergency specialist feature, if shown, is nonfunctional and clearly labeled.

## Sources

Phases [09](../docs/phases/09_notifications_and_post_visit_chat.md), [16](../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md), and [17](../docs/phases/17_doctor_ai.md).
