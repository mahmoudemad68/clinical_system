# Phase 03 — Scheduling, Availability, and Booking

## Objective

Deliver location-specific doctor schedules, exceptions, appointment types, computed availability, atomic patient booking, cancellation/rescheduling, and authorized walk-in creation. The phase must prevent double booking under concurrent load and preserve schedule intent across `Africa/Cairo` daylight-saving changes.

The observable outcome is that one and only one actor can acquire a contested slot, retries return the original result, schedule changes cannot silently invalidate appointments, and no booking action grants medical-record access.

## Plan traceability

- Sections 19-24, lines 770-936: doctor locations, schedules/exceptions, appointment types, computed availability, atomic booking, and pay-at-clinic status.
- Section 25, lines 938-958: authorized walk-in patient find/create, availability, appointment, and later check-in.
- Sections 26-27, lines 960-1023: queue inputs and future delay projection data produced by appointments.
- Section 45, lines 1532-1547: deterministic discovery order later consumes availability.
- Section 100, lines 2898-2924: appointment reminder/change events consumed by Phase 09.
- Sections 104-107, lines 2992-3105: outbox, API conventions, and booking idempotency.
- Sections 109 and 111, lines 3117-3254: schedule, exception, appointment-type, appointment/status-event, and indexes.
- Sections 113-115, lines 3275-3319: locks/cache and avoidance of authoritative state in Redis.
- Sections 132-133, lines 3640-3690: availability/API latency and capacity targets.
- Sections 146, 149, and 152, lines 3962-3982, 4027-4049, and 4085-4109: appointment statuses, Egypt/time rules, and safe retries.
- Sections 156-157 and 160-162, lines 4182-4236 and 4268-4327: test pyramid, authorization, load, and stress testing.
- Sections 173-176, lines 4559-4714: strong consistency, background work, implementation order, and readiness.

## Entry criteria and dependencies

- Phase 01 authenticated actor/policy/idempotency contracts and Phase 02 approved doctors, active clinic locations, patient handles, and authorized secretary memberships pass.
- Product approves cancellation/reschedule cutoffs, booking horizon, minimum lead time, slot granularity, walk-in policy, and whether overlapping appointment types are ever allowed. Defaults fail closed until configured.
- A time-model ADR defines weekly local schedule intent, UTC instants, DST ambiguity/non-existence handling, and clock ownership.

## Non-goals

- No online payment; V1 records payment-at-clinic metadata only.
- No check-in, queue order, consultation start, medical-record access, or encounter creation; Phase 04-05 own those transitions.
- No recurring patient appointment series, waitlist, overbooking, telemedicine, insurance, multi-country schedule, or AI booking.
- No review creation; Phase 08 consumes completed appointments for review eligibility.
- No reminder delivery; Phase 09 consumes durable events.

## Laravel module ownership and services

### `Scheduling`

Owns weekly schedule rules, schedule exceptions, appointment-type offerings per location, time-zone conversion, and availability calculation.

```text
DefineWeeklySchedule
AddScheduleException
ConfigureAppointmentType
ComputeAvailability
ValidateSlotAgainstSchedule
```

### `Appointments`

Owns appointments, booking/cancellation/reschedule state transitions, status history, slot occupancy, patient/doctor/location references, price snapshot, and payment-at-clinic metadata.

```text
BookAppointment
CancelAppointment
RescheduleAppointment
CreateWalkInAppointment
GetPatientAppointments
GetDoctorDayAppointments
```

### Module services and external integrations

```text
DoctorDirectoryService       # approved doctor/location/visibility from Phase 02
PatientRegistryService       # patient handle and authorized exact-match/create command
MembershipService            # secretary scope
Clock
TransactionRunner
AvailabilityCache
OutboxPublisher
```

Scheduling returns available intervals and explanations; Appointments owns the transaction that claims one. Controllers never compose schedule subtraction or locking logic. Redis can cache computed days but cannot approve a booking.

## Packages and platform capabilities

- PostgreSQL/PostGIS with `tstzrange`, GiST indexes, exclusion constraints where applicable, row locks, and transaction retry policy.
- Laravel cache/Redis for short-TTL availability results and stampede protection only.
- A small `AvailabilityService` recurrence implementation for the limited V1 weekly rules; adopt an RFC recurrence package only after an ADR proves the need and DST behavior.
- `brick/money` for immutable EGP price snapshots using minor units.
- Carbon/Laravel time APIs behind an injected `Clock`; service tests do not call system time directly.
- Flutter patient mobile uses the generated Dart client, Riverpod, `intl`, calendar/date controls, and secure idempotency-key persistence for an in-flight booking intent.
- Electron doctor/clinic-staff desktop uses React, TypeScript, TanStack Query, React Hook Form, Zod, MUI, i18next, and the generated TypeScript client. Its sandboxed renderer calls typed schedule/appointment capabilities through preload; main owns credentials and transport but no scheduling rule.
- Pest/PHPUnit, PostgreSQL concurrency harness, Hypothesis/property tests where a language boundary benefits, browser-admin Playwright, Flutter patient-mobile integration tests, Electron main/preload/renderer tests plus WebdriverIO with `@wdio/electron-service` packaged-app E2E, and k6.

## Data model and migrations

### `doctor_schedules`

```text
id UUIDv7 PK
doctor_id UUID
clinic_location_id UUID
weekday smallint check 1..7
local_start_time time
local_end_time time
timezone varchar default 'Africa/Cairo'
effective_from date
effective_until date nullable
status enum(active,inactive)
version bigint
created_at / updated_at
```

- Check start before end; overnight schedules are not supported in V1 unless an ADR defines splitting.
- Prevent overlapping active rules for the same doctor/location/effective range using validated application logic plus a normalized persistence constraint/materialized interval strategy.
- Index `(doctor_id, clinic_location_id, weekday, status, effective_from)`.

### `schedule_exceptions`

```text
id UUIDv7 PK
doctor_id UUID
clinic_location_id UUID
type enum(vacation,holiday,blocked,emergency_closure,special_working_day)
starts_at / ends_at timestamptz
reason_code varchar(64)
status enum(active,cancelled)
version bigint
created_by / created_at
```

- Check `starts_at < ends_at`; GiST index on a generated `tstzrange` plus doctor/location.
- A special working day adds an interval; blocking types subtract. Conflicting exceptions use an explicit precedence table tested in the domain.

### `appointment_types`

```text
id UUIDv7 PK
doctor_id UUID
clinic_location_id UUID
code enum(physical,follow_up,consultation)
name_ar / name_en
duration_minutes smallint
price_amount_minor bigint
currency char(3) default EGP
status enum(active,inactive)
booking_horizon_days smallint
version bigint
created_at / updated_at
```

- Duration and price use configured safe bounds; no float.
- Unique active `(doctor_id, clinic_location_id, code)` unless product explicitly allows multiple offerings.

### `appointments`

```text
id UUIDv7 PK
patient_id UUID
doctor_id UUID
clinic_location_id UUID
appointment_type_id UUID
source enum(patient,secretary,doctor,admin_system)
kind enum(scheduled,walk_in)
starts_at / ends_at timestamptz
timezone varchar
status enum(booked,checked_in,waiting,in_consultation,completed,cancelled,no_show)
price_amount_minor bigint
currency char(3)
payment_status enum(pay_at_clinic,paid,waived,refunded) default pay_at_clinic
payment_method nullable
transaction_reference nullable
cancelled_at / cancellation_reason_code nullable
version bigint
created_at / updated_at
```

- Phase 03 permits creation and transition to `cancelled`; Phase 04 owns later status transitions.
- Store immutable appointment type/duration/price/currency/location-timezone snapshots so later configuration changes do not rewrite history.
- Exclusion constraint prevents overlapping non-cancelled/non-no-show intervals for a doctor/location according to approved overlap policy.
- Index `(doctor_id, starts_at, status)`, `(clinic_location_id, starts_at, status)`, `(patient_id, created_at desc)`, and `(status, starts_at)`.

### `appointment_status_events`

Append-only: appointment, from/to status, actor, reason, source, occurred time, expected record version, request/correlation IDs. Unique `(appointment_id, resulting_version)`.

### Optional `appointment_slot_claims`

Use only if exclusion constraints cannot model product semantics cleanly. It stores a canonical doctor/location interval claim with a unique/exclusion constraint and appointment owner. Do not use Redis locks as the final defense.

## Core invariants

1. Availability is derived from active schedule rules plus special working periods minus blocking exceptions and occupied appointment intervals; it is never pre-generated years ahead.
2. A slot is bookable only for an approved active doctor, active location, active appointment type belonging to that doctor/location, within horizon/lead-time, and wholly inside an allowed interval.
3. A patient cannot hold overlapping active appointments if product adopts that rule; the exact policy must be explicit and tested.
4. At most one appointment occupies a protected doctor interval. Database constraints, not cache/UI, decide the winner.
5. Every booking/reschedule stores UTC instants plus the originating IANA timezone and immutable offering snapshot.
6. Schedule or appointment-type edits do not mutate existing appointments. A conflicting change is rejected or enters an explicit impact-confirmation workflow with affected appointment IDs and notifications later.
7. Booking, rescheduling, cancellation, and walk-in creation are strongly consistent, versioned, audited, outboxed, and idempotent.
8. Payment is `pay_at_clinic` metadata only. No PAN, CVV, gateway token, or online charge is accepted.
9. Booking/check-in eligibility does not grant clinical access. No contextual medical-record grant is created in this phase.
10. Patient, doctor, secretary, and admin queries are projection-specific and tenant/context scoped.

## Detailed workflows

### Compute availability

1. Authenticate optional/public discovery context and validate doctor, location, appointment type, date range, and bounded horizon.
2. Resolve approved/public doctor/location/offering server-side.
3. Convert weekly local rules for requested dates into UTC intervals using the stored IANA timezone and explicit DST policy.
4. Apply special working-day additions, then subtract vacation/holiday/blocked/emergency intervals.
5. Load overlapping non-terminal appointments from PostgreSQL and subtract occupied intervals using the appointment duration.
6. Align results to the configured granularity without creating slots outside allowed intervals.
7. Return opaque slot tokens bound to doctor, location, offering, start/end, calculation version, expiry, and a server signature; tokens are hints, not reservations.
8. Cache only the public/safe computed result for a short TTL keyed by doctor/location/type/date/rule version. Invalidate after relevant committed changes.

Failure behavior:

- Ambiguous/nonexistent DST local time follows the ADR and is surfaced safely; never guess silently.
- Cache/Redis failure computes from PostgreSQL with bounded load or returns a controlled retry; it never returns stale occupancy as authoritative.
- Excessive date ranges, invalid tokens, inactive resources, and malformed time zones fail before expensive queries.

### Atomic patient booking

1. Authenticated patient submits slot token and idempotency key; patient ID comes from server actor context.
2. Verify token signature/expiry and reload doctor/location/offering; never trust token snapshots alone.
3. Begin transaction, lock/canonicalize the target occupancy interval, recompute schedule/exception/occupancy validity, and validate patient rules.
4. Insert appointment, status event, audit record, and `AppointmentBooked` outbox event.
5. Commit; finalize idempotency response with appointment reference.
6. Invalidate affected availability cache after commit.

Concurrent requests for the same interval race at the database constraint/lock. One commits; the other returns `409 SLOT_UNAVAILABLE` with no appointment/event. A network retry using the same key returns the original appointment.

### Cancellation

1. Resolve actor-specific cancellation policy and current appointment version.
2. Lock appointment; reject already started/completed/cancelled/no-show states and cutoff violations.
3. Transition to cancelled, append status event/reason, outbox the change, and commit.
4. Invalidate availability and let Phase 09 notify asynchronously.
5. Duplicate cancel is idempotent when intent matches; a different reason/version conflict is explicit.

### Rescheduling

1. Treat reschedule as one atomic operation, not cancel followed by an unprotected booking.
2. Lock old appointment and new target occupancy in canonical order to avoid deadlocks.
3. Revalidate new schedule and actor policy, update immutable-current appointment fields with a status/history event or create a linked replacement according to an ADR.
4. Preserve old/new snapshots in history, emit one rescheduled event, and invalidate both days.
5. If new slot is unavailable, keep the original appointment unchanged.

### Schedule/exception change impact

1. Validate new rule/version and query affected future appointments.
2. If none, commit and invalidate caches.
3. If affected, require an explicit product-approved action: reject, keep existing appointments as exceptions, or create an operational conflict list. Never auto-cancel patients.
4. Emergency closure may mark appointments operationally affected only through an audited bulk command with idempotent per-appointment results and Phase 09 notification events.

### Walk-in appointment

1. Authorized secretary is scoped to one active clinic location.
2. Search patient through the narrow exact identity workflow; create an unlinked profile through Phase 02 only when no match exists and required proof is captured.
3. Resolve an active doctor/offering and validate current walk-in availability/policy.
4. Create `kind=walk_in`, source secretary, pay-at-clinic appointment atomically.
5. Do not check in automatically unless the actor performs the separate Phase 04 check-in command.

## API contracts

```text
GET    /doctors/{doctor_id}/locations/{location_id}/availability
GET    /doctors/me/schedules
POST   /doctors/me/schedules
PATCH  /doctors/me/schedules/{schedule_id}
POST   /doctors/me/schedule-exceptions
PATCH  /doctors/me/schedule-exceptions/{exception_id}
GET    /doctors/me/appointment-types
POST   /doctors/me/appointment-types
PATCH  /doctors/me/appointment-types/{type_id}

POST   /appointments
GET    /patients/me/appointments
GET    /doctors/me/appointments
POST   /appointments/{id}/cancel
POST   /appointments/{id}/reschedule
POST   /clinic-locations/{location_id}/walk-in-appointments
```

- Mutations require record version where stale updates matter and idempotency keys for booking/reschedule/cancel/walk-in.
- Availability accepts a bounded local date/date range and returns UTC/RFC3339 instants, display timezone, expiry, and signed opaque slot token.
- Patient cannot supply `patient_id`; doctor/secretary cannot supply a clinic outside active membership.
- Safe `404` hides inaccessible appointment/resource existence. `409 SLOT_UNAVAILABLE`, `409 VERSION_CONFLICT`, and `422 SCHEDULE_CONFLICT` are stable codes.

## Events and jobs

```text
ScheduleChanged.v1 {doctor_id, location_id, schedule_version, effective_range}
ScheduleExceptionChanged.v1 {doctor_id, location_id, exception_id, change_type}
AppointmentBooked.v1 {appointment_id, patient_id, doctor_id, location_id, starts_at}
AppointmentCancelled.v1 {appointment_id, patient_id, doctor_id, location_id, reason_code}
AppointmentRescheduled.v1 {appointment_id, old_starts_at, new_starts_at, doctor_id, location_id}
AppointmentOperationalConflictDetected.v1 {appointment_id, conflict_type}
```

Events omit patient names, phone, National ID, address, price unless a consumer contract proves need, and all clinical data.

Jobs:

- Short-horizon availability pre-warm as an optimization; correctness never depends on it.
- Expired idempotency/slot-token cleanup.
- Future appointment conflict detector after timezone/rule changes.
- No-show transition is Phase 04 because it depends on check-in/operational time.

## Client work

### Patient Flutter

- Doctor → location → appointment type → date/slot flow with Cairo-local display and explicit price/duration/pay-at-clinic summary.
- Preserve one idempotency key from confirmation until terminal response; disable duplicate taps without assuming that is server protection.
- On `409 SLOT_UNAVAILABLE`, retain selections, refresh availability, and require a new explicit choice.
- Appointment list distinguishes booked/cancelled and later operational statuses; no medical-record access is implied.

### Doctor Electron desktop

- Weekly schedule editor, exceptions, appointment types, price/duration, impacted-appointment warning, and optimistic conflict UX.
- Never silently overwrite a schedule changed on another device.
- TanStack Query owns server projections; validated form/view state never becomes availability or booking authority. Main/preload exposes only generated operations and safe cancellation/error results, not arbitrary URLs or tokens.

### Clinic staff Electron desktop surface

- This is a capability-gated route in the doctor Electron application, not a fifth standalone client.
- Location-scoped day list and walk-in creation use server-derived scope and the exact patient find/create flow.
- Clinical fields and history are absent from the booking projection.

### React browser admin

- Operational support may view safe appointment identifiers/status/timestamps only through an approved projection; no clinical record or unrestricted mutation.

## Security and privacy threats and controls

- **Double booking/race:** exclusion/unique constraints, canonical lock ordering, transaction recomputation, idempotency, and concurrency tests.
- **BOLA/tenant escape:** server-owned patient/doctor/location/membership resolution, safe `404`, field allowlists, and no client account/price/status authority.
- **Slot-token tampering/replay:** signed, actor/resource-bound, short-lived token; full server revalidation; token is never a reservation.
- **Schedule abuse/DoS:** bounded horizon/range, rate limits, indexed range queries, cache stampede control, and per-actor/global query budgets.
- **Price manipulation:** immutable server-side offering snapshot in minor units; client-sent price ignored/rejected.
- **Patient enumeration through walk-in:** narrow exact-match service, authorized staff scope, generic outcomes, audit/anomaly detection, and no clinical projection.
- **Time manipulation:** injected server clock, UTC storage, IANA timezone, signed tokens, no device-clock authority, DST regression suite.
- **Cache poisoning/staleness:** versioned tenant-aware keys, short TTL, post-commit invalidation, no PHI payload, and database revalidation on mutation.

## Test plan

### Unit tests

- Weekly rule expansion, exception precedence, interval subtraction, granularity, boundaries, lead/horizon rules, and DST ambiguity/non-existence.
- Appointment type, booking, cancellation, reschedule, walk-in, payment metadata, status/version, and price-snapshot invariants.
- Authorization matrix for patient, doctor, secretary, unrelated doctor/location, pending/suspended profiles, and admin.
- Slot-token signing/binding/expiry and cache-key derivation.

### Integration tests

- Real PostgreSQL exclusion/locking under dozens of simultaneous same-slot and overlapping-duration requests; exactly one allowed occupancy.
- Deadlock retry with canonical lock order; transaction rollback leaves no status event/outbox/idempotency partial state.
- PostGIS/location ownership, Redis cache loss/stampede, cache invalidation, and indexed query plans on representative volume.
- Outbox duplicate delivery has exactly-once business effect.

### Contract tests

- OpenAPI/generated Dart patient-mobile and TypeScript Electron/admin client compatibility for schedules, availability, booking, cancellation, reschedule, and walk-in.
- Time/money/slot-token/error schemas; event compatibility and sensitive-field denial.
- Clock, cache, directory, patient-registry, membership, and outbox integrations pass their focused service or provider-interface contracts.

### End-to-end tests

- Doctor configures two location-specific schedules/types; patient books one available slot and sees correct Cairo time/price.
- Two patients contest one slot; one succeeds, one safely reselects.
- Cancellation reopens availability; reschedule preserves original if the target loses a race.
- Secretary creates an unlinked walk-in for the authorized location; unrelated secretary is denied.
- Schedule emergency closure creates an operational conflict without auto-cancelling or exposing patient data.
- Packaged doctor Electron builds on each supported OS preserve typed money/time/version semantics, keyboard/focus behavior, and safe conflict recovery without exposing credentials or permitting renderer-supplied scope.

### System tests

- k6 availability/search/booking sustained and burst scenarios meet plan p95/error/DB stability targets.
- Redis failure, application node restart, delayed outbox, rolling schema deployment, and Cairo DST boundary.
- Stress to breaking point demonstrates controlled `429/503`, recovery, no duplicate booking, and no lost committed event.

### Security tests

- BOLA/BFLA/mass assignment, patient/doctor/location/type ID substitution, forged price/status, stale version, replay, slot-token mutation, and race tooling.
- Electron tests attempt forged preload operations/senders, arbitrary endpoint/headers, token access, navigation, and renderer mutation of doctor/location/capability context; the bridge and server both deny them.
- Fuzz date ranges, time zones, signed tokens, cursor/filter values, Unicode, huge durations, and nested JSON with bounded resources.
- Verify appointment queries/events/logs/caches contain no National ID, phone, clinical content, or unauthorized patient names.
- Attempt booking through inactive doctor/location/type and direct transition to checked-in/completed; all fail.

## Observability and runbooks

```text
availability_requests_total{result}
availability_latency_seconds
availability_cache_total{result}
booking_attempts_total{result,source}
booking_conflicts_total{reason}
booking_transaction_seconds
appointment_transitions_total{transition,result}
schedule_conflicts{type}
outbox_age_seconds{event_group=appointments}
```

- No patient/doctor/location IDs in metric labels; traces use identifiers only under approved access and redaction.
- Alert on booking conflict anomalies, transaction/deadlock spikes, unavailable cache causing DB saturation, stale outbox, unexpected status transitions, and mass walk-in lookup.
- Runbooks cover DST/time configuration error, double-booking suspicion, schedule change impacting appointments, emergency closure, Redis loss, database contention, and stuck idempotency records.

## Migration and rollout

1. Add schedule/appointment schemas and constraints with synthetic representative data; prove index plans and concurrency before API enablement.
2. Enable doctor schedule/type management, then read-only availability, then booking for an internal cohort.
3. Enable cancellation/reschedule only after status history and notification event consumers tolerate events; delivery itself may wait for Phase 09.
4. Enable walk-ins per clinic after secretary scope and exact patient-match audit pass.
5. Version cache keys during rollout; old code may read old keys but every mutation revalidates PostgreSQL.
6. Rollback disables new mutations while preserving appointments/events; forward recovery repairs projections/caches without deleting appointments.

## Measurable exit gate

- Repeated high-concurrency contested-slot tests produce one active appointment and zero partial/duplicate events.
- Availability p95 is at or below 300 ms and normal booking write p95 at or below 400 ms under the agreed representative load, or an approved measured exception exists.
- All schedule/booking mutation retries are idempotent and same-key/different-payload returns `409`.
- Cairo DST, exception precedence, overlapping durations, cancellation cutoff, and schedule-impact suites pass.
- Cross-patient, cross-doctor, cross-location, inactive-profile, price/status-forgery, and walk-in enumeration tests pass.
- No booking/check-in path creates clinical access; database and audit assertions prove zero contextual clinical grants.
- Dashboards, alerts, operational-conflict and double-booking runbooks are exercised.
- Product approves time, cancellation, reschedule, walk-in, and impact policies; security/privacy approves the phase threat delta.
- No Critical or unaccepted exploitable High finding remains.

## Deliverables

- `Scheduling` and `Appointments` modules with controllers, Form Requests, services, models, optional backed enums, migrations, policies, OpenAPI, and events.
- Doctor schedule/type editors, patient availability/booking flow, and staff walk-in flow.
- Concurrency/load/security suites, time-model ADR, dashboards, alerts, and runbooks.
