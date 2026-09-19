# Patient AI triage screens

Patient AI is an optional, feature-gated intake and recommendation flow. Deterministic rules own emergency stops and urgency floors. AI never diagnoses, treats, books autonomously, or replaces manual doctor search.

## Screen 1 — Medical AI consent and limitations

**Maturity:** Planned, Phase 19. **Route concept:** `/medical-ai/start`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a high-trust AI consent screen with limitations first, emergency warning, privacy summary, consent control, and visible manual-search alternative.

- **Purpose:** Establish informed purpose, limitations, emergency-use boundary, privacy/retention behavior, and consent before any symptom input.
- **Layout:** Short scannable sections, non-emergency warning, data/retention summary, consent control, Start assessment, and persistent Find doctor manually alternative.
- **Content/actions:** Read details, consent, start, go to manual search, or leave. Consent is versioned and not bundled with unrelated settings.
- **States:** Ready, feature unavailable, rate/cost cohort denied, consent policy updated, offline, and session resumable/expired.
- **Accessibility/safety:** Critical warning is first in reading order, not color-only, and remains understandable at 200% text. No streaming/generated text on this screen.

## Screen 2 — Fixed intake questions

**Maturity:** Planned, Phase 19. **Route concept:** `/medical-ai/:session/intake`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a one-question-per-screen fixed-intake UI with progress, typed answer control, large actions, stop/clear, and keyboard-safe layout.

- **Purpose:** Collect one schema-defined fixed answer at a time under optimistic versioning.
- **Layout:** Progress indicator, question, typed answer control, optional explanation, Back governed by server state, Continue, Stop, Clear, and manual search link.
- **Content/actions:** Answer required formats/options/ranges, choose “I don't know” where defined, skip only optional items, and stop/clear.
- **States:** Answering, validating, saving, version conflict from another device, reconnecting with minimum encrypted draft, rate limited, expired, and emergency transition.
- **Rules/accessibility:** Client never invents question keys/options. Screen reader announces progress/question once; controls use large targets, RTL-safe order, and color-independent validation.

## Screen 3 — Dynamic follow-up questions

**Maturity:** Planned, Phase 19. **Route concept:** continuation of intake.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a stable follow-up-question screen visually consistent with fixed intake, clearly labeled as follow-up and retaining manual-search/stop controls.

- **Purpose:** Ask only policy-approved bounded follow-ups after the fixed intake when no red flag has stopped the session.
- **Layout:** Same stable question scaffold plus a clear “Follow-up” label, remaining/budget-neutral progress wording, and why-this-question help using approved static copy.
- **Content/actions:** Answer, unknown, optional skip, stop, clear, or return only where server version permits.
- **States:** Generating safe question, ready, invalid model proposal replaced by safe message, capacity/provider unavailable, version conflict, sufficient information, and emergency transition.
- **Safety:** The model cannot directly render questions or request prohibited data, diagnosis, or medication advice. Failure always preserves a manual doctor-search route.

## Screen 4 — Emergency stop

**Maturity:** Planned, Phase 19. **Route concept:** terminal safety state.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a prominent but calm emergency-stop screen using fixed approved copy, clear next actions, high contrast, and no ordinary Continue button.

- **Purpose:** Immediately stop normal AI questioning/result generation when deterministic clinician-approved rules detect a red flag.
- **Layout:** Prominent fixed approved emergency heading/copy, country-appropriate next actions, Leave assessment, standard contact/directions behavior if approved, and no ordinary Continue.
- **Content/actions:** Follow approved emergency instructions, open validated contact/directions, or leave. No booking priority, dispatch, monitoring, or clinician-chat promise.
- **States:** Emergency stopped, contact capability unavailable, offline copy available, and session closed.
- **Accessibility/safety:** Static localized copy cannot be overwritten by streaming output. Use text/icon/hierarchy, high contrast, screen-reader immediate announcement, and avoid panic-inducing animation.

## Screen 5 — Triage result and recommended specialty

**Maturity:** Planned, Phase 19. **Route concept:** `/medical-ai/:session/result`.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a patient-safe triage result with urgency hero, uncertain possible causes, recommended specialty, limitations, and primary Find doctors action.

- **Purpose:** Present uncertain possible causes, deterministic urgency floor, recommended specialty, grounding/limitations, and next safe action.
- **Layout:** Urgency banner, “Possible causes — not a diagnosis,” explanation, specialty card, limitations/source notice, Find doctors, and manual search/close.
- **Content/actions:** Find doctors for validated specialty, start manual search, review answers where policy permits, clear session, or exit.
- **States:** Ready, stale result superseded by newer answer, incomplete/safe inability to continue, AI unavailable, specialty unresolved, expired, and cleared.
- **Safety/accessibility:** Never lower server rule urgency or show definitive diagnosis/treatment. Urgency uses words/icon, and only a clinician can diagnose is visible before actions.

## Screen 6 — AI booking proposal confirmation

**Maturity:** Planned, Phase 19. **Route concept:** standard booking handoff after result.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate a distinct final booking confirmation screen with AI recommendation context separated from exact server booking facts and one human confirmation control.

- **Purpose:** Keep AI recommendation separate from the one visible human-confirmed booking mutation.
- **Layout:** Recommended-specialty context, selected doctor/location/type, exact Cairo date/time, duration, EGP price, pay-at-clinic/cancellation facts, proposal expiry, and distinct final confirmation area.
- **Content/actions:** Confirm booking through one-time proof, edit doctor/slot using standard discovery, return to result, or cancel.
- **States:** Confirmation displayed, proof requesting, booking pending/unknown, booked, proposal expired, slot conflict with alternatives, version conflict, and offline prohibited.
- **Rules:** AI prose is never the confirm control. Model/FastAPI cannot obtain proof or execute booking. No silent alternative selection or automatic retry with a new intent.

## Sources

Phase [19](../docs/phases/19_patient_ai_triage_and_booking_tools.md).
