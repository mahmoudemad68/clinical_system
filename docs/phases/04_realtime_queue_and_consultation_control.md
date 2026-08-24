# Phase 04 — Realtime Queue and Consultation Control

## Objective

Deliver server-authoritative check-in, waiting queues, current-patient control, delay projection, private realtime updates, consultation start/end orchestration, no-show handling, and unresolved-session detection.

This phase resolves the source-plan timing ambiguity conservatively: **check-in makes an appointment eligible for the queue but grants no cross-doctor medical-record access. Only an atomic, doctor-authorized `Start Consultation` transition creates the encounter anchor and active consultation-scoped access grant.** This decision requires an ADR plus clinical, product, privacy, and security approval before the endpoint is enabled.

The observable outcome is that queue state remains correct under concurrency/reconnects, only one current consultation exists per doctor/location context, patients see only their own queue projection and number ahead, and full-history access appears and disappears with the authoritative consultation transaction.

## Plan traceability

- Section 13, lines 510-567: contextual doctor access and post-visit revocation.
- Sections 16-18, lines 644-768: encounter fields/lifecycle, start/end side effects, and doctor current-patient dashboard.
- Sections 25-28, lines 938-1044: walk-in, queue inputs, delay projection, push trigger, and Reverb realtime.
- Section 47, lines 1569-1603: completed consultation opens a later 48-hour chat.
- Sections 99-104, lines 2879-3025: private channels, notifications, queue separation, Horizon, and transactional outbox.
- Section 107, lines 3081-3105: idempotent consultation completion.
- Sections 109, 113-114, lines 3117-3210 and 3275-3303: appointment/queue/event tables and Redis responsibilities/separation.
- Sections 132-136, lines 3640-3745: queue-event SLO, capacity, and Reverb scaling.
- Sections 141-146, lines 3857-3982: availability, health, monitoring, admin health, statuses, no-show, and unresolved appointments.
- Sections 152 and 155-162, lines 4085-4327: safe retry, transient offline limits, authorization/load/stress tests.
- Sections 173-176, lines 4559-4714: strong consistency, background work, implementation order, and production gate.

## Entry criteria and dependencies

- Phase 03 appointment/status/version/idempotency contracts, active doctor/location/membership policies, and outbox are operational.
- Phase 01 contextual grant port exists. The production `Start Consultation` feature remains disabled until Phase 05 supplies `EncounterLifecyclePort` and the cross-module atomicity test passes.
- Product/clinical approves queue order/tie-breaking, early/late check-in windows, no-show cutoff, doctor override reasons, maximum consultation/grant duration, and unresolved recovery.
- ADR-004 (or repository-equivalent) records: check-in eligibility only; start grants access; end revokes; timeout suspends access without fabricating clinical completion.

## Non-goals

- No medical-record fields, diagnoses, clinical notes, prescription, or lab workflow; Phase 05-07 own them.
- No offline queue mutation, offline consultation start/end, or client-authoritative current patient.
- No precise wait-time promise to patients; V1 shows number ahead and bounded status messages.
- No emergency specialist chat, teleconsultation, multi-doctor group encounter, automatic clinical completion, or AI queue decisions.
- No notification delivery implementation; emit durable events for Phase 09.

## Module ownership and SOLID boundaries

### `Queue`

Owns queue entries, ordering keys, checked-in eligibility, number-ahead projection, no-show/removal state, and delay projection inputs/results.

```text
CheckInAppointment
UndoErroneousCheckIn
MarkNoShow
GetPatientQueuePosition
GetClinicQueue
ReorderQueueWithReason       # restricted exception path
ProjectDelay
```

### `ConsultationControl`

Owns operational consultation sessions and coordinates start/end across module ports. It owns no clinical fields.

```text
StartConsultation
EndConsultation
SuspendStaleConsultationAccess
ResumeUnresolvedConsultation
GetCurrentConsultation
```

### Required ports

```text
AppointmentLifecyclePort     # Phase 03
EncounterLifecyclePort       # implemented by Phase 05
ContextualAccessPort         # Phase 01
MembershipPort               # Phase 02
RealtimePublisher
OutboxPublisher
Clock
TransactionRunner
```

The orchestration handler uses one PostgreSQL transaction because the modular monolith shares one database. Ports participate in the caller's unit of work and must not commit independently. Realtime/push/chat side effects occur only from outbox events after commit.

### Dependency rules

- `Queue` may read safe appointment scheduling snapshots through a port, never appointment Eloquent models.
- `ConsultationControl` passes opaque appointment/patient/doctor/location handles to `EncounterLifecyclePort`; it does not inspect clinical tables.
- Realtime adapters serialize projection DTOs defined by the owning application layer; they never publish domain models.
- Clients display state but cannot propose arbitrary queue positions, doctor IDs, patient IDs, access scopes, or status transitions.

## Packages and platform capabilities

- Laravel Reverb private channels and broadcasting, Redis Pub/Sub, Horizon queue workers, PostgreSQL row locks/advisory locks where justified, outbox, and idempotency foundations.
- Laravel authorization policies with Phase 01 typed actor/context.
- Carbon/time APIs behind the injected `Clock`.
- Flutter shared `realtime` package, Riverpod, Dio/generated API client, and connectivity status.
- React/TanStack Query only for safe operational admin-health projections where approved.
- Pest/PHPUnit with real PostgreSQL/Redis/Reverb integration harness, WebSocket client tests, k6 WebSocket/load scenarios, and controlled-clock state tests.

## Data model and migrations

### `queue_entries`

```text
id UUIDv7 PK
appointment_id UUID unique
patient_id UUID
doctor_id UUID
clinic_location_id UUID
service_date date
state enum(eligible,waiting,current,completed,no_show,removed)
checked_in_at timestamptz
waiting_at / current_at / completed_at nullable
ordering_key numeric(24,8)
manual_priority_reason_code nullable
version bigint
created_at / updated_at
```

- Unique appointment; check state/timestamp consistency.
- Index `(doctor_id, clinic_location_id, service_date, state, ordering_key)` and `(patient_id, state, checked_in_at desc)`.
- Ordering key uses deterministic scheduled time/check-in/tie-break policy. Arbitrary client values are rejected.

### `consultation_sessions`

```text
id UUIDv7 PK
appointment_id UUID unique
queue_entry_id UUID unique
encounter_id UUID unique nullable
patient_id / doctor_id / clinic_location_id UUID
state enum(starting,active,ending,completed,unresolved,cancelled_start)
started_at / ended_at / access_suspended_at nullable
started_by / ended_by UUID nullable
start_reason_code / end_reason_code nullable
version bigint
created_at / updated_at
```

- Partial unique index permits at most one `active|starting|ending` consultation per doctor and approved operational scope.
- `encounter_id` becomes non-null before an `active` state can commit.
- Patient/doctor/location must match authoritative appointment; enforce in application plus consistency trigger/check strategy where cross-row constraints warrant it.

### `queue_projection_versions`

One row per doctor/location/service date stores monotonic projection version and last committed event time. It supports reconnect gap detection; it is not queue truth.

### Access grants

Phase 01 `contextual_access_grants` receives a consultation-bound grant only at successful start. Policy validity requires all of:

```text
grant active and not suspended/revoked
consultation_session.state = active
appointment.status = in_consultation
actor doctor = appointment doctor
patient/resource matches appointment patient
location/context matches session
grant version matches session version
```

An orphan grant is denied and alerted; no cache may widen these conditions.

## Core invariants

1. `checked_in`/`waiting` means operational eligibility only and creates zero full-history grant.
2. Only the assigned approved doctor with sufficient MFA/session assurance can start that appointment's consultation.
3. Start is one transaction: lock appointment/queue/doctor-current state; validate; create encounter through Phase 05; create consultation; set appointment/queue current; create access grant; append audit/status/outbox; commit or roll back all.
4. At most one active consultation exists for a doctor in the approved scope; at most one session exists per appointment.
5. End is one transaction: finalize/validate encounter through Phase 05; complete appointment/session/queue; revoke full-history grant; clear current patient; append audit/outbox; commit or roll back all.
6. Realtime is a post-commit projection. A missed/duplicate/out-of-order event is repaired by fetching versioned server state.
7. Patients see their status and number ahead, never other patient identities, appointment reasons, or clinical details.
8. Secretary may check in, view operational queue, mark no-show under policy, and correct an erroneous check-in; secretary cannot start/end consultation or access clinical content.
9. Client disconnect, WebSocket loss, or worker failure never changes authoritative consultation state.
10. A stale/unresolved session never stays an unbounded access bypass. After the approved maximum, access is suspended and requires reauthentication/reasoned recovery; the encounter is not auto-completed.

## Detailed workflows

### Check-in

1. Authorized clinic staff resolves appointment by opaque ID in its active clinic-location scope.
2. Server validates booked state, service date/location, check-in window, doctor/location activity, and expected appointment version.
3. Begin transaction and lock appointment plus any existing queue entry.
4. Transition appointment to `checked_in`, create queue entry `eligible`, append status/audit events, then transition to `waiting` according to queue policy.
5. Increment projection version and insert `PatientCheckedIn`/`QueueChanged` outbox events.
6. Commit and return safe queue projection.

No encounter and no clinical grant is created. Duplicate same-intent check-in returns the prior result; a conflicting duplicate returns `409`.

### Queue ordering and number ahead

1. Load waiting/current entries for doctor/location/day using one indexed snapshot query.
2. Apply deterministic ordering: active current first, then approved priority class if any, scheduled intent, check-in time, stable UUID tie-break.
3. For patient projection, count eligible/waiting entries before the patient's key and return number plus projection version.
4. Manual reorder is a restricted command requiring reason, before/after audit, version check, and notification event; it cannot edit raw ordering keys.

### Start consultation — sole access-grant point

1. Doctor explicitly selects an eligible/waiting appointment from the server queue and submits idempotency key plus expected versions.
2. Reauthenticate/step up if the approved sensitive-action policy requires it; resolve assigned doctor, location, patient, and membership server-side.
3. Lock in canonical order: doctor-current guard, appointment, queue entry, existing session/grant.
4. Validate no active consultation, appointment/queue state, assigned doctor/location, non-suspended identities, and queue selection/override policy.
5. Call `EncounterLifecyclePort.start()` in the same database transaction; it creates the encounter anchor and returns its ID.
6. Create `consultation_session=active`, transition appointment to `in_consultation`, queue to `current`, and create one active contextual grant for cross-doctor history.
7. Append status/access/audit records and outbox `ConsultationStarted`/`QueueChanged` events.
8. Commit. Only then broadcast current-patient and patient-position projections.

If any clinical/access/queue write fails, all changes roll back and no access exists. A same-key retry returns the same session; a second appointment/doctor attempt conflicts.

### End consultation

1. Assigned doctor submits idempotency key and current consultation/encounter versions while online.
2. Lock session, appointment, queue, encounter, and grant.
3. Ask `EncounterLifecyclePort.canComplete/finalize`; missing required clinical decisions return `422` without changing operational state.
4. On success, finalize encounter, complete session/appointment/queue, revoke grant, clear current guard, and append status/access/audit/outbox records in one transaction.
5. After commit, publish minimal events for realtime, notifications, analytics, and Phase 09 chat opening.
6. Duplicate retry returns the completed result; different payload/version returns conflict.

### No-show and erroneous check-in

- A scheduled job may identify candidates but cannot mark them no-show without the approved policy/time threshold and a traceable transition. Staff correction uses versioned commands and never deletes status history.
- No-show/removal makes the queue entry terminal, invalidates projection, and never creates/revokes a clinical grant because none should exist.

### Unexpected disconnect/unresolved consultation

1. Disconnect changes only presence telemetry, not the session.
2. Jobs detect sessions beyond configured duration/end-of-day and mark an operational `unresolved` alert.
3. At the approved security maximum, policy suspends cross-history access atomically and records why; it does not invent encounter completion.
4. Doctor reauthenticates and uses a reasoned resume/end workflow. Admin/secretary may flag/escalate but cannot read/finalize clinical content.

### Realtime subscribe/reconnect

1. Authenticate current session and authorize the exact private channel at subscribe and reconnect.
2. Channel IDs are server-derived; knowing an ID is insufficient.
3. Client supplies last projection version. Server sends delta if retained or instructs full safe projection fetch.
4. Client ignores duplicates, detects gaps/out-of-order versions, and refetches; event payload never becomes local authority.
5. Session/membership/grant revoke disconnects affected channels within the approved SLO.

### Delay projection

- Use scheduled start, actual first start offset, planned vs actual durations, current queue, and controlled clock.
- Projection is advisory, versioned, and recalculated after start/end/order changes.
- Patient UI primarily receives number ahead and `approaching`/`delayed` states. Exact minutes, if retained internally, are not presented as a promise.

## API contracts

```text
POST /clinic-locations/{location_id}/appointments/{appointment_id}/check-in
POST /clinic-locations/{location_id}/queue-entries/{id}/undo-check-in
POST /clinic-locations/{location_id}/queue-entries/{id}/mark-no-show
POST /clinic-locations/{location_id}/queue/reorder
GET  /clinic-locations/{location_id}/queue
GET  /patients/me/queue-position
GET  /doctors/me/current-consultation
POST /appointments/{appointment_id}/consultation/start
POST /consultations/{consultation_id}/end
POST /consultations/{consultation_id}/resume
GET  /realtime/authorize
```

- Mutations require idempotency key and aggregate/projection versions.
- Patient queue response contains own appointment state, number ahead, safe delay state, and version—no queue list.
- Staff queue response contains operational name/basic booking fields allowed by §14, never diagnosis, medications, allergies, labs, prescription, notes, or history.
- `409 ACTIVE_CONSULTATION_EXISTS`, `409 STALE_QUEUE`, `422 NOT_CHECKIN_ELIGIBLE`, and `423 CONSULTATION_ACCESS_SUSPENDED` are stable safe codes.

## Realtime events, domain events, and jobs

Private channels follow source-plan shape:

```text
patient.{patient_id}
doctor.{doctor_id}
clinic.{location_id}
appointment.{appointment_id}
```

Event schemas:

```text
PatientCheckedIn.v1 {appointment_id, patient_id, doctor_id, location_id, checked_in_at}
QueueChanged.v1 {doctor_id, location_id, service_date, projection_version, reason}
ConsultationStarted.v1 {consultation_id, encounter_id, appointment_id, patient_id, doctor_id, location_id, started_at}
ConsultationCompleted.v1 {consultation_id, encounter_id, appointment_id, patient_id, doctor_id, location_id, completed_at}
ConsultationAccessSuspended.v1 {consultation_id, doctor_id, reason_code, suspended_at}
AppointmentNoShowMarked.v1 {appointment_id, patient_id, doctor_id, location_id, occurred_at}
DoctorDelayStateChanged.v1 {doctor_id, location_id, delay_bucket, projection_version}
```

Realtime serializers derive recipient-specific payloads from these internal events. They do not broadcast the full internal event to every channel.

Jobs:

- No-show candidate/evaluation job with idempotent transitions.
- Unresolved consultation/access-suspension detector.
- Delay recomputation and threshold-change event.
- Queue projection reconciliation against appointment/session truth.
- Outbox delivery/replay and stale-presence cleanup.

## Client work

### Doctor Flutter desktop

- Current Patient card, waiting/today/completed/upcoming lists, explicit Start/End actions, online-state requirement, and conflict recovery.
- Start button remains disabled until Phase 05 encounter/access integration is deployed and server capability enables it.
- Show authoritative sync/version state; WebSocket event triggers refetch on gap.
- Offline mode permits no queue/start/end mutation and never claims the consultation completed.

### Patient Flutter

- Own queue status, number ahead, approaching/delayed state, and reconnect/refetch behavior.
- No other patient's name, appointment type, reason, or exact position identifier.
- Generic push handling is deferred to Phase 09.

### Clinic staff surface

- Check-in, operational queue, no-show, and audited reorder/correction with reason.
- Clinical quick actions, full record, prescription, labs, allergies, and notes are absent.

### React admin

- Only de-identified/system-health counts and unresolved operational references approved by policy; no live patient queue content or clinical access.

## Security and privacy threats and controls

- **Premature/stale clinical access:** check-in never grants; start/end atomicity; policy joins active session, appointment, doctor, patient, location, grant; bounded suspension and revoke propagation.
- **Cross-patient/doctor BOLA:** server-derived context, object/action policies, safe `404`, no client scope fields, and exhaustive matrix tests.
- **Queue privacy leakage:** recipient-specific projections, private channels, minimal events, no identities in patient payloads, and generic notification contents.
- **Realtime hijack/stale subscription:** subscribe/reconnect authorization, short-lived session, revoke disconnect, projection versions, origin/TLS controls, connection/rate caps.
- **Race/double current patient:** database locks/partial unique constraints, canonical ordering, idempotency, and repeated concurrency tests.
- **Forged/out-of-order events:** events are hints after commit; clients refetch authoritative state, validate versions, and never execute clinical transitions from a broadcast.
- **Availability attack:** connection/message/rate limits, bounded channel subscriptions, backpressure, separate Redis queues, load shedding, and core API priority.
- **Insider reorder/no-show abuse:** fixed capabilities, reason codes, immutable audit, anomaly metrics, and no raw priority mutation.

## Test plan

### Unit tests

- Check-in eligibility, queue ordering/tie-breaks, number-ahead projection, manual reorder, delay calculation, no-show, and unresolved thresholds.
- Consultation/session/access state machines, including forbidden skipped/backward transitions.
- Role/context policy matrix and recipient-specific event serialization/redaction.
- Controlled-clock boundary tests for early/late check-in, end-of-day, DST, and maximum access duration.

### Integration tests

- Real PostgreSQL concurrent check-in/start/end/no-show/reorder; one current session and one grant only.
- Forced failures at each start/end write prove whole-transaction rollback and no orphan encounter/grant/status/outbox.
- Redis/Reverb private-channel auth, reconnect version gap, duplicate/out-of-order delivery, revoke disconnect, and Redis restart.
- Outbox worker crash/retry yields exactly-once business effects and repairable projections.

### Contract tests

- OpenAPI/generated clients for queue/consultation endpoints and stable errors/idempotency/version requirements.
- Event schemas plus recipient projection schemas; no clinical fields in operational events.
- Appointment, encounter, contextual-access, realtime, clock, and transaction adapters pass owned port contracts.

### End-to-end tests

- Booked → check-in → waiting proves no full-history access; doctor Start creates encounter/grant; End revokes it and advances queue.
- Two doctors/patients and two locations prove no cross-context visibility or current-session collision.
- Secretary checks in/marks no-show but cannot start/end/open record.
- Patient reconnect sees correct number ahead after missed events without seeing other patients.
- Disconnect during consultation leaves explicit active/unresolved state; reasoned recovery completes safely.

### System tests

- 10k connected-user and 20k WebSocket-headroom scenarios, queue event p95 at or below one second, controlled backlog, and node/Redis/Reverb failover.
- Worker/API kill during start/end; retry/recovery never duplicates encounter/grant/completion.
- Stress until degradation identifies breaking point and proves recovery/no corrupted queue or access state.
- AI/Qdrant outage has no effect on queue or consultation control.

### Security tests

- BOLA/BFLA, channel enumeration, forged authorization request, token/session revoke, origin abuse, event injection, version rollback, mass assignment, and rate/resource exhaustion.
- Direct check-in-to-record attempt, stale active-grant replay, orphan grant, cross-location doctor, suspended doctor, and concurrent-start race all deny.
- Seed clinical/identity canaries and verify none appears in queue APIs, broadcasts, metrics, logs, traces, Horizon, or patient UI.
- Octane alternating doctor/patient/location requests prove no current-context leakage.

## Observability and runbooks

```text
queue_entries{state}
queue_transitions_total{transition,result}
queue_projection_lag_seconds
queue_reconciliation_mismatches_total{type}
consultation_transitions_total{transition,result}
active_consultations
unresolved_consultations{age_bucket}
active_contextual_grants
grant_revocation_latency_seconds
reverb_connections / reverb_message_latency_seconds
realtime_authorization_total{result,channel_type}
outbox_age_seconds{event_group=queue}
```

- Alert on orphan/multiple grants, multiple current sessions, queue/appointment mismatch, unresolved duration, revoke SLO breach, realtime auth-denial spike, projection lag, and outbox backlog.
- Runbooks cover wrong patient started, failed end, stale grant, accidental check-in/reorder, unresolved session, Reverb/Redis outage, event gap, and queue reconciliation.

## Migration and rollout

1. Add queue/session schemas and constraints; backfill no historical queue state unless explicitly required.
2. Enable check-in/read-only queue in staging, then a controlled clinic cohort. Assert check-in creates no access grant.
3. Deploy Phase 05 encounter adapter and cross-module transaction tests before enabling Start/End.
4. Record and approve the check-in/start ADR; server flag defaults Start/End off until approvals and test evidence exist.
5. Enable private realtime after authorization/reconnect/load tests; clients retain polling/refetch fallback.
6. Enable unresolved suspension only after dry-run metrics and clinical/product approval of thresholds/recovery.
7. Rollback disables new transitions while preserving authoritative state; repair commands reconcile projections without rewriting audit history.

## Measurable exit gate

- The ADR explicitly states: check-in eligibility only; atomic Start grants; atomic End revokes; clinical/product/privacy/security approvals are recorded.
- Database assertions prove zero grants for checked-in/waiting appointments and exactly one active grant for an active consultation.
- Injected failure/concurrency suites leave no orphan encounter, session, grant, queue, status, or outbox record.
- Cross-role/location/patient/channel authorization and revoke-propagation tests pass.
- Queue realtime p95 is at or below one second at target load; 20k connection-headroom test has acceptable error/backlog/recovery evidence.
- Patient payloads/telemetry contain no other patient identity or clinical canary.
- Unresolved consultation detection, access suspension, resume/end, and operator runbooks are rehearsed.
- No Critical or unaccepted exploitable High finding remains.

## Deliverables

- `Queue` and `ConsultationControl` modules, migrations, policies, APIs, events, jobs, and reconciliation tooling.
- Doctor/patient/staff realtime workflows and private-channel projections.
- Approved access-timing ADR, concurrency/failure/load/security evidence, dashboards, alerts, and runbooks.
