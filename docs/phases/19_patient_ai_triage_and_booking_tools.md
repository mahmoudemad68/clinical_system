# Phase 19 — Patient AI Triage and Booking Tools

## Objective

Deliver a patient-facing, Arabic/English symptom-intake assistant that collects a fixed minimum intake, asks bounded follow-up questions, applies clinician-versioned deterministic red-flag rules, and returns cautious possible causes, urgency, and a canonical recommended specialty. When appropriate, the patient may continue into doctor discovery and the normal atomic booking workflow.

The LLM is advisory and untrusted. It cannot suppress a deterministic red flag, claim a definitive diagnosis, select a doctor or slot on the patient's behalf, or perform a booking without a fresh human confirmation bound to the exact appointment proposal. If AI is unavailable, patients can still find doctors, view slots, book, manage appointments, and use every other core feature manually.

## Plan traceability

- Sections 22-24, lines 861-937: computed availability, transactionally safe booking, and V1 payment-at-clinic behavior.
- Sections 43-45, lines 1482-1550: Patient Home AI entry, manual doctor discovery, and ranking by earliest availability then rating; distance is display-only.
- Section 75, lines 2313-2335: separate patient-safe educational/triage/red-flag/specialty knowledge.
- Sections 90-93, lines 2650-2764: fixed/dynamic intake, deterministic red flags plus LLM reasoning, cautious output, and confirmed AI-assisted booking tools.
- Sections 94-98, lines 2765-2878: core isolation, provider abstraction, traceability, prompt-injection defense, and separate conversation storage.
- Sections 100-104, lines 2898-3028: appointment notifications, bounded queues, and reliable post-commit events.
- Section 107, lines 3081-3108: booking idempotency.
- Sections 116-123, lines 3322-3496: patient authentication, security, rate limiting, audit/log redaction, and external-LLM privacy.
- Sections 132-133 and 140-143, lines 3640-3693 and 3835-3915: AI/core latency and capacity separation, health, and monitoring.
- Sections 148-152, lines 4004-4111: Arabic/English, Egypt configuration, location/directions, and safe client failure handling.
- Sections 157 and 163-164, lines 4216-4239 and 4331-4366: authorization, triage/routing evaluation, and mandatory medical-expert validation.
- Sections 170-174, lines 4484-4622: feature flags/future exclusions, sources of truth, consistency, and background work.

## Entry criteria and dependencies

- Phase 01 provides authenticated patient profiles/device sessions and patient-safe authorization.
- Phases 02-03 provide verified doctors, specialties, locations, appointment types, schedules, availability, atomic booking, idempotency, and payment-at-clinic state.
- Phase 08 provides Patient Home, manual doctor discovery, localization, current location handling, and directions.
- Phase 16 provides isolated AI runtime, patient-safe collection/versioning, provider/retrieval ports, trace records, and evaluation harness.
- Qualified medical reviewers own the red-flag rule set, specialty taxonomy, urgency taxonomy, question limits, patient wording, and versioned promotion thresholds.
- Privacy/legal review has defined provider-processing, retention, age/consent, and escalation-content requirements for the intended Egyptian launch context.

## Non-goals

- No definitive diagnosis, treatment plan, prescription, medication alternative, medical-record write, adherence tracking, or medical-image interpretation.
- No emergency specialist chat, direct emergency-service dispatch, clinician messaging, or claim that the platform monitors the patient.
- No replacement for emergency care, clinician examination, or the manual doctor-search/booking path.
- No automatic booking, rescheduling, cancellation, payment, reservation, or repeated slot attempts.
- No distance-based recommendation ranking; distance remains informational.
- No raw doctor/private/pharmacy knowledge or arbitrary patient medical record retrieval.
- No autonomous browser, external search, URL fetch, shell/code/SQL, or general-purpose tool use.
- No symptom conversation automatically becoming part of the clinical record.

## Architecture, ownership, and SOLID boundaries

### Ownership

```text
Laravel PatientTriage module
  owns intake state machine, answer validation, deterministic safety rules,
  urgency/specialty normalization, consent/retention and patient-visible result

Laravel Appointments/Doctors modules
  own doctor search, ranking inputs, slots, proposals and atomic booking

Laravel AI module
  owns bounded LLM orchestration, patient-KB retrieval, provider policy,
  run budgets, traces and AI-only failure behavior

FastAPI patient_assistant
  owns bounded dynamic-question/summary proposals and grounded answer composition

Qualified clinical governance
  owns red-flag content, thresholds, validation datasets and release sign-off
```

FastAPI never receives booking credentials, database access, an unrestricted patient record, or a generic core tool endpoint. Laravel evaluates all safety rules and executes all core queries/commands.

### Ports and command boundaries

```text
TriageRuleRepository
  activeApprovedVersion(locale, country)

DeterministicTriageEngine
  evaluate(intake_snapshot, rule_version)

DynamicQuestionProvider
  proposeQuestions(minimized_snapshot, allowed_question_schema, budget)

PatientKnowledgeRetriever
TriageLlmProvider
TriageOutputPolicy

DoctorDiscoveryQuery
  findBySpecialty(specialty_id, location?, filters)

AppointmentAvailabilityQuery
  getSlots(doctor_id, location_id, type_id, date_window)

BookingProposalService
  createExactProposal(patient_id, slot_identity, price_version)

ConfirmedBookingCommand
  book(patient_id, proposal_id, human_confirmation_proof, idempotency_key)
```

- **Single responsibility:** deterministic safety classification, dynamic questions, LLM composition, doctor search, proposal creation, and booking are independent handlers.
- **Open/closed:** new provider/rule storage adapters do not change booking or safety state machines.
- **Liskov substitution:** provider fakes and adapters obey the same typed refusal/timeout/invalid-output semantics.
- **Interface segregation:** AI has query/proposal ports only; the human-confirmed booking handler is unreachable without a core-issued proof.
- **Dependency inversion:** clinical rules and booking commands own interfaces; provider SDK, HTTP, persistence, and model code are adapters.

## Packages and runtime components

Versions are pinned under Phase 00.

### Laravel/PHP

- Existing Laravel, Sanctum, PostgreSQL/PostGIS, outbox, Horizon, Reverb, audit, rate limiting, idempotency, OpenTelemetry, and Sentry baseline.
- Domain state machine and rule evaluation use plain typed PHP/value objects and versioned immutable data; do not add a dynamic executable rules engine.
- Existing UUIDv7, clock, Cairo time, normalized specialty, money, and booking lock/constraint utilities.

### Python/FastAPI

- Phase 16 FastAPI/Pydantic/HTTPX/Qdrant/provider/telemetry stack.
- Pydantic discriminated unions for `ClarifyingQuestions`, `TriageSuggestion`, `SafeEmergencyMessage`, `OutOfScopeRefusal`, and `InvalidOutput`.
- `pytest`, `pytest-asyncio`, `respx`, and Hypothesis for state/schema/adversarial tests.
- No autonomous-agent or provider-hosted tool execution framework.

### Patient Flutter app

- Existing Riverpod, Dio, go_router, Freezed/JSON, Drift, secure storage, localization, accessibility, and generated OpenAPI client.
- Local persistence may hold only an encrypted resumable draft with explicit expiry/clear controls; final status remains server-authoritative.

## Persistent schemas, invariants, and indexes

```text
triage_rule_versions
  id UUIDv7 PK
  country_code char(2)
  locale string
  semantic_version string
  status enum DRAFT | APPROVED | ACTIVE | RETIRED
  rules_ciphertext/jsonb                 # immutable after approval
  content_hash bytea
  approved_by_clinical_reviewer_id UUID
  approved_at UTC nullable
  activated_at / retired_at UTC nullable

patient_triage_sessions
  id UUIDv7 PK
  patient_profile_id UUID FK
  conversation_id UUID nullable FK ai_conversations
  rule_version_id UUID FK
  prompt_version string
  status enum STARTED | FIXED_INTAKE | DYNAMIC_INTAKE |
              EMERGENCY_STOPPED | COMPLETED | ABANDONED | EXPIRED
  locale / country_code
  started_at / last_activity_at / completed_at UTC nullable
  result_version integer
  retention_policy_id

patient_triage_answers
  id UUIDv7 PK
  session_id UUID FK
  question_key string
  question_version integer
  answer_ciphertext text
  normalized_answer jsonb                 # bounded typed values only
  source enum PATIENT | MODEL_CLARIFICATION
  ordinal integer
  created_at UTC

triage_assessments
  id UUIDv7 PK
  session_id UUID FK
  input_version integer
  rule_version_id UUID FK
  rule_outcome enum CONTINUE | CLARIFY | EMERGENCY_STOP
  urgency_code string nullable
  recommended_specialty_id UUID nullable
  possible_causes_ciphertext text nullable
  red_flag_codes_ciphertext text nullable
  model_run_id UUID nullable
  status enum VALID | REJECTED | SUPERSEDED
  created_at UTC

booking_proposals
  id UUIDv7 PK
  patient_profile_id UUID FK
  triage_session_id UUID nullable FK
  doctor_id / location_id / appointment_type_id UUID FK
  slot_start / slot_end UTC
  schedule_version / price_version string
  price_minor bigint
  currency char(3)
  status enum PROPOSED | CONFIRMATION_DISPLAYED | CONFIRMED |
              BOOKED | EXPIRED | CONFLICTED | CANCELLED
  expires_at UTC
  confirmation_nonce_hash bytea
  created_at / confirmed_at UTC nullable
```

Indexes and constraints:

- `patient_triage_sessions(patient_profile_id, last_activity_at desc)` and `(status, last_activity_at)` for expiry work.
- unique `(session_id, ordinal)` and `(session_id, question_key, question_version)` as applicable.
- unique active rule per `(country_code, locale)` enforced with a partial unique index or activation transaction.
- unique `triage_assessments(session_id, input_version)`; stale answer versions cannot overwrite an assessment.
- `booking_proposals(patient_profile_id, created_at desc)` and `(status, expires_at)`.
- Proposal nonce is single-use, actor-bound, exact-payload-bound, short-lived, and stored only as a keyed hash.
- Conversation/answer/result content is encrypted; no symptom text enters logs, analytics, events, metrics, URLs, push bodies, or Qdrant chat memory.

### Hard invariants

1. Fixed intake always collects main complaint, duration, severity, basic relevant context, and associated symptoms before normal completion.
2. Deterministic rule evaluation runs after every accepted answer and before every LLM continuation/output.
3. `EMERGENCY_STOP` is terminal for normal questioning. Model output can escalate but can never downgrade/suppress a deterministic emergency outcome.
4. Uncertainty that affects safety produces bounded clarification or a conservative approved message; the model cannot invent missing facts.
5. Result uses canonical urgency and specialty IDs. Free text cannot become an authorization/filter/tool argument.
6. Wording says possible causes and recommended next step, never “you definitely have X.”
7. Dynamic question count, answer size, session duration, model calls, tokens, cost, retries, and parallelism are bounded.
8. Doctor ranking is earliest availability, then rating descending; distance is displayed only.
9. Booking uses the existing strongly consistent command/lock. The AI run or model cannot manufacture human confirmation.
10. A confirmation proof is valid only for the authenticated patient, exact doctor/location/type/slot/price proposal, one use, and a short expiry.
11. Triage session is not a medical record. Any later clinician use requires an explicit, separately authorized patient/clinical workflow and provenance; V1 performs no automatic import.

## Detailed control and data flows

### 1. Start and complete the fixed intake

1. Patient opens Medical AI and receives purpose, limitation, emergency-use, privacy/retention, and consent text in the selected locale.
2. Laravel authenticates the patient profile, checks feature cohort/rate/cost limits, and creates a session pinned to active rule/prompt versions.
3. The server returns one schema-defined fixed question at a time. Client never defines question keys or versions.
4. Each answer is size/type/range validated, normalized without changing meaning, encrypted, and appended with optimistic input version.
5. In the same transaction, the session version advances and an assessment command is queued or executed according to its bounded deterministic cost.
6. Deterministic rules evaluate the complete snapshot. A high-risk combination immediately transitions to `EMERGENCY_STOPPED`.
7. If fixed intake is complete and no stop applies, the state enters `DYNAMIC_INTAKE`.

### 2. Dynamic questioning

1. Laravel minimizes the snapshot and removes name, phone, national ID, address, device data, and unrelated profile facts.
2. FastAPI receives allowed question categories/schema, current answered keys, rule outcome, locale, version, budget, and correlation ID.
3. The model may propose a bounded set of typed questions; output validation rejects duplicates, diagnosis claims, medication/treatment advice, unknown keys, excessive options, or requests for prohibited data.
4. Laravel's question policy approves, rewrites only approved static wording, or rejects the proposal. The model cannot directly render a question.
5. The patient may answer, say unknown, skip only non-mandatory items, or stop/clear the session.
6. Deterministic rules run again after every answer. A later red flag stops the wizard immediately.
7. The loop terminates at sufficient approved information, question/call/time budget, patient stop, emergency stop, or safe inability to continue; no hidden replanning loop exists.

### 3. Produce a non-emergency result

1. Deterministic engine produces allowed urgency range and any hard specialty constraints.
2. Patient-safe KB retrieval uses only active patient knowledge and preserves source IDs.
3. LLM proposes possible causes, urgency explanation, specialty, and limitations in a strict schema.
4. Laravel validates specialty against canonical taxonomy and deterministic constraints, rejects definitive diagnosis/treatment language, and never lets the model lower urgency below the rule floor.
5. Before persistence/display, the latest input/session version is checked. A concurrent new answer supersedes the stale result.
6. The result is encrypted, traced, and displayed with clear uncertainty and instructions to seek qualified care.

### 4. Emergency stop

1. A deterministic rule matches a clinician-approved combination and stores only rule codes/evidence references needed for audit.
2. The session atomically transitions to `EMERGENCY_STOPPED`; normal follow-up and booking suggestion generation stop.
3. The patient sees fixed, medically/legal-approved emergency guidance for locale/country, not free-form model text.
4. The UI offers approved next actions such as leaving the AI flow and opening standard contact/directions behavior. It does not promise dispatch, monitoring, priority booking, or clinician response.
5. A high-priority internal safety metric/audit event contains pseudonymous session/rule/version/status only, never symptom text.

### 5. Doctor discovery and slot proposal

1. Patient explicitly taps “Find doctors for this specialty.”
2. Laravel reads the validated specialty ID from the current result; the client/model cannot replace it without returning to manual search.
3. `DoctorDiscoveryQuery` returns approved doctors with location/type/price/availability/rating/distance under normal authorization and pagination.
4. Ranking is earliest availability then rating descending. Distance is included only for display/tie-independent information.
5. Patient selects doctor, location, appointment type, and a slot returned by the server.
6. Availability is recomputed and a short-lived exact `booking_proposal` is stored with schedule/price version.
7. UI shows doctor, location/address, type, Cairo-local date/time, duration, EGP price, pay-at-clinic method, and cancellation information, then records `CONFIRMATION_DISPLAYED`.

### 6. Human-confirmed booking

1. Only the visible confirm control can request a one-time confirmation proof through the authenticated UI flow; model/FastAPI has no endpoint or credential for it.
2. Client sends proposal ID, proof, and a new `Idempotency-Key` to the normal booking endpoint.
3. Laravel verifies actor/proof/hash/expiry/status and reruns normal booking authorization/validation.
4. In one transaction it locks the slot/conflicting range, rechecks schedule/exception/appointment constraints, creates the appointment/payment-at-clinic state, marks proposal booked, writes audit/outbox/idempotency result, and commits.
5. Exactly one concurrent contender succeeds. A conflict/expired proposal returns current alternatives and never silently chooses another slot.
6. Notification delivery happens asynchronously from the committed outbox.

### 7. Failures, cancellation, and concurrency

- AI/Qdrant/provider unavailable: preserve current encrypted draft where policy permits and offer manual doctor search; core remains healthy.
- Invalid model question/result: reject it, use an approved safe message, and never persist/display the invalid claim.
- Duplicate answer/input version: return original outcome or `409`; do not append twice.
- Simultaneous devices: optimistic session/input version prevents lost or reordered answers; stale device refreshes.
- Session expiry/withdrawal: stop model work, invalidate proposals, apply retention/deletion policy, and prevent resume with stale authorization.
- Provider timeout/rate limit: bounded retry only within deadline for idempotent generation; no retry of booking without the same normal idempotency key/proof.
- Patient closes app during booking: subsequent same-key retry returns committed result; a new key cannot create a second appointment because DB constraints/locks still apply.

## API, event, tool, and job contracts

### Public Laravel API

```text
POST   /api/v1/patient-ai/triage-sessions
GET    /api/v1/patient-ai/triage-sessions/{session_id}
POST   /api/v1/patient-ai/triage-sessions/{session_id}/answers
POST   /api/v1/patient-ai/triage-sessions/{session_id}/stop
DELETE /api/v1/patient-ai/triage-sessions/{session_id}   # policy-aware clear request
GET    /api/v1/patient-ai/triage-sessions/{session_id}/result

GET    /api/v1/patient-ai/triage-sessions/{session_id}/doctors
GET    /api/v1/patient-ai/triage-sessions/{session_id}/doctors/{doctor_id}/slots
POST   /api/v1/patient-ai/triage-sessions/{session_id}/booking-proposals
POST   /api/v1/booking-proposals/{proposal_id}/confirmation-proof
POST   /api/v1/appointments                              # existing booking command
```

Stable errors include `TRIAGE_SESSION_EXPIRED`, `TRIAGE_VERSION_CONFLICT`, `TRIAGE_EMERGENCY_STOPPED`, `TRIAGE_INPUT_INVALID`, `TRIAGE_OUTPUT_INVALID`, `AI_UNAVAILABLE`, `SPECIALTY_NOT_RESOLVED`, `BOOKING_PROPOSAL_EXPIRED`, `BOOKING_CONFIRMATION_REQUIRED`, `BOOKING_CONFIRMATION_INVALID`, and `SLOT_CONFLICT`.

### AI tool boundary

FastAPI can propose only `find_doctors.v1` and `get_slots.v1` read requests using canonical specialty/selection references supplied by Laravel. Laravel replaces all actor/scope/location authorization context and executes normal queries. `book_slot.v1`, if represented in the AI protocol, is a **proposal handoff only** and cannot execute `ConfirmedBookingCommand`; the public human-confirmed appointment endpoint is the sole mutation path.

### Events and jobs

- `triage.session_emergency_stopped.v1`, `triage.session_completed.v1`, and `triage.session_expired.v1` contain session/rule/version/status IDs only.
- `booking.proposal_expired.v1` is optional operational state; `appointment.booked.v1` remains the authoritative normal appointment event.
- Jobs expire abandoned sessions/proposals, reconcile stuck AI runs, execute retention/clear requests, aggregate de-identified evaluation metrics, and send normal appointment notifications.
- Consumers are idempotent; no event contains symptoms, possible causes, raw answers, national ID, phone, or provider response.

## Patient mobile work

- Present consent/limitations before intake and always retain a visible manual doctor-search path.
- Use schema-driven fixed/dynamic questions with clear progress, back behavior governed by server version, “I don't know,” stop, and clear controls.
- Render emergency-stop content from approved server copy, prominently and accessibly; do not let streaming model text overwrite it.
- Show possible causes as uncertain, urgency and specialty as recommendations, and explain that only a clinician can diagnose.
- Discovery/booking UI must show exact server facts and a distinct final confirmation screen; AI prose never becomes the confirm control.
- Handle `401/403/409/422/429/5xx`, offline drafts, expiry, reconnect, and duplicate taps without unsafe automatic retries.
- Encrypt minimum resumable draft locally, omit it from notifications/screenshots where platform controls permit, and clear it on logout/revocation/expiry/user request.
- Test Arabic RTL, English, large text, screen readers, keyboard/switch navigation, color-independent urgency cues, and low-connectivity recovery.

## Security, privacy, and clinical safety controls

- Treat symptom data, triage answers/results, and AI conversations as sensitive clinical/personal data even though they are not the medical record.
- Authenticate/authorize every session/read/answer/result/discovery/proposal/proof/booking operation; hide other-session existence where appropriate.
- Use CSRF/device-token protection, request/answer size limits, schema allowlists, optimistic versions, aggressive AI/rate/cost limits, and abuse monitoring.
- Minimize and pseudonymize provider context. Never send name, phone, national ID, address, precise location, object keys, credentials, or unrelated history.
- Current location is used transiently for search and is not automatically persisted in the triage conversation/model trace.
- Deterministic rule and output policy versions are immutable, signed/hashed, clinically approved, audited, and rollback-capable. Prompts cannot override them.
- Render output as sanitized plain text/restricted Markdown; disable arbitrary HTML, scripts, remote images, and unvalidated links.
- Prevent prompt injection, fabricated emergencies, unsafe de-escalation, model/tool scope expansion, denial-of-wallet, output poisoning, and confirmation-token theft/replay.
- Provider-processing/residency/retention and patient consent wording require qualified privacy/legal validation. Clinical rules and wording require qualified medical validation; engineering evidence is not a legal or clinical approval.

## Test plan

### Unit tests

- Every fixed question/state transition, mandatory/optional answer, version conflict, stop/expire/clear path, and maximum-loop termination.
- Clinician rule fixtures for emergency stop, clarify, continue, urgency floor, rule priority, locale/version activation, and model inability to downgrade.
- Output policy rejects definitive diagnosis, treatment/prescription language, unsupported specialty, invalid urgency, hidden tool fields, unsafe markup, and oversized content.
- Ranking is availability then rating with distance excluded; booking proof binding, expiry, one-use, hash, and idempotency states.
- Redaction and property/fuzz tests cover Arabic/English, bidi/Unicode, malformed numbers/dates, long input, encoded injection, and contradictory answers.

### Integration tests

- Real PostgreSQL verifies answer/assessment atomicity, optimistic races, encrypted fields, rule activation uniqueness, expiry jobs, retention, and proposal/proof transitions.
- Real schedule/appointment tables verify recomputation, exception handling, slot locking, price/schedule version conflict, and exactly-one booking under concurrency.
- Real Qdrant proves patient-safe collection isolation and active-version retrieval.
- Provider/FastAPI tests cover timeout, cancellation, invalid/downgraded output, unavailable retrieval, and safe failure without core impact.

### Contract tests

- OpenAPI-generated Flutter client covers every question/result/emergency/proposal/confirmation/error schema.
- Rule-set schema/version/hash/activation contract rejects executable code, unknown actions, and missing clinical approval.
- AI provider contracts enforce structured output, deadline/cancellation, token/cost accounting, and no silent model fallback.
- Tool contracts prove read-only discovery and that booking mutation requires normal core confirmation proof.

### End-to-end tests

- Normal intake produces cautious possible causes, urgency, specialty, ranked doctors, exact proposal, explicit confirmation, and one booked appointment.
- A deterministic red flag immediately stops normal wizard/model output and shows approved emergency guidance.
- An uncertain case asks bounded clarification and never guesses missing severity/duration.
- Concurrent patient/device booking and retry produce one appointment; a stale/expired slot returns conflict without substitution.
- AI/Qdrant outage routes to manual doctor discovery/booking and leaves all core workflows healthy.
- Another patient cannot read/resume/clear a session or use its proposal/confirmation proof.

### System, load, security, and clinical evaluation tests

- Load scenario covers intake starts, answer bursts, model concurrency, doctor search, slot reads, and confirmed booking without breaching core SLOs.
- Saturation, provider outage, Redis restart, worker loss, network partition, cancellation, and app reconnect preserve safe states and bounded cost.
- Adversarial tests attempt prompt injection, urgency downgrade, fake specialty/tool calls, diagnosis/treatment solicitation, data exfiltration, unsafe links, proof theft/replay, and denial-of-wallet.
- Versioned datasets measure red-flag sensitivity, false-emergency rate, specialty-routing accuracy, clarification quality, groundedness, hallucination, out-of-scope refusal, latency, cost, and booking-tool correctness.
- Qualified medical experts review normal, ambiguous, high-risk, multilingual, culturally relevant, adversarial, and failure cases. Promotion uses their explicit per-metric thresholds and case-level critical-failure rule; model grading alone cannot approve release.

## Observability, migration, and rollout

### Observability

- Metrics: session starts/completions/abandonment/emergency stops, questions per session, clarification rate, rule/model disagreements, invalid outputs, specialty distribution at a safe aggregate level, discovery/proposal/confirmation conversion, booking conflicts, latency, token/cost, saturation, provider/Qdrant errors.
- Never use patient/session/question/symptom/cause/location/free text as metric labels or log/trace attributes.
- Alerts cover deterministic-rule bypass attempt, unsafe-output regression, red-flag evaluation regression, excessive false-stop reports, cost anomaly, provider/Qdrant outage, booking confirmation failures, and core SLO impact.
- Runbooks distinguish clinical-content incident, privacy incident, AI dependency incident, and normal booking incident; kill switches can disable dynamic questions, result generation, or all patient AI without disabling manual booking.

### Migration and rollout

1. Expand immutable rule/session/answer/assessment/proposal schemas and deploy APIs disabled.
2. Load only medically approved, hashed rule versions; run deterministic, security, privacy, and clinical evaluation suites with synthetic cases.
3. Enable internal clinical QA, then a small consented cohort under `patient_ai_intake`; keep AI booking handoff separately flagged.
4. Review every critical miss/unsafe downgrade before cohort expansion. Prompt/model/rule/retrieval versions promote independently through the full gate.
5. Enable read-only doctor/slot tools, then exact proposal, then confirmed booking after normal booking concurrency evidence passes.
6. Emergency kill switch stops new AI sessions and model calls while preserving manual discovery/booking. Rollback retains auditable versions and follows approved retention/clear policy.

## Acceptance and exit gate

- Deterministic red-flag rules execute before/after model interaction and cannot be suppressed, downgraded, or rewritten by prompts/retrieved content/model output.
- Fixed intake, bounded dynamic questioning, cautious output, uncertainty handling, emergency stop, abandonment, expiry, and clear flows pass all state/concurrency tests.
- Patient AI produces no definitive diagnosis, treatment, prescription, medical-record write, dispatch promise, or unconfirmed booking.
- Doctor ranking and exact proposal match core data; booking requires fresh human-bound proof and remains one-winner/idempotent under races/retries.
- Cross-patient, prompt/tool injection, proof replay/theft, unsafe output, denial-of-wallet, provider/Qdrant outage, and saturation tests fail safely with zero unauthorized disclosure or state mutation.
- Approved datasets meet clinician-signed red-flag, false-emergency, routing, grounding, refusal, latency, and booking-tool thresholds in Arabic and English.
- Manual doctor search/booking remains available and within SLO when every AI dependency is down.
- Privacy/legal/clinical approvals, retention/consent, dashboards/alerts/runbooks, migrations/rollback, accessibility/localization, and all test evidence are complete.
- Emergency chat, adherence, alternatives, reservation, imaging diagnosis, payment, and all other V1-excluded features remain disabled.
