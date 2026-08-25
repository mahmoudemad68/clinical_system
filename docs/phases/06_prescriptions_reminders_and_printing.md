# Phase 06 — Prescriptions, Reminders, and Printing

## Objective

Deliver structured encounter-scoped prescriptions, medication instructions, explicit reminder schedules, active-period calculation, immutable exposure/versioning, reasoned amendments, patient read views, and audited prescription print/export.

Prescriptions depend on an application-owned `MedicationReferencePort`, not a direct medication-catalog table or model. A minimal approved reference adapter may be used in this phase; the complete Egyptian medication catalog and inventory integration arrive later without changing the prescription domain. There is no unsafe fallback from an unresolved reference to an arbitrary unverified product.

The observable outcome is that a doctor can draft and finalize a prescription only in an authorized encounter, every finalized version is immediately immutable and patient-readable, external use records an additional exposure milestone, every correction preserves prior versions and raises the required notification event, and retries/concurrent edits never create duplicate or overwritten clinical instructions.

## Plan traceability

- Sections 15-18, lines 599-768: clinical source of truth, authorized encounter, consultation completion, and doctor actions.
- Sections 30-37, lines 1078-1329: prescription item structure, free notes, explicit reminders, active period, immutability, amendment, audit, and printing without QR.
- Section 43, lines 1482-1506: patient home later surfaces upcoming dose/new prescription.
- Sections 49-50, lines 1634-1695: later medication master/SKU/packaging shape, accessed only through a port.
- Sections 68-69, lines 2145-2193: later `Find My Prescription` exposure and current/previous behavior.
- Sections 87-89, lines 2578-2646: AI may recommend/copy but cannot prescribe or write automatically.
- Sections 100-104, lines 2898-3025: prescription/reminder/correction notifications, critical queue, and outbox.
- Sections 107 and 109, lines 3081-3105 and 3117-3210: finalize idempotency and prescription tables.
- Sections 117-123, lines 3346-3493: security, MFA, rates, audit, tamper evidence, logs, and privacy.
- Sections 132 and 158, lines 3640-3659 and 4240-4250: prescription-read SLO and mandatory mutation/amendment/concurrency tests.
- Sections 172-176, lines 4522-4714: medical truth, strong consistency, execution order, and release gate.

## Entry criteria and dependencies

- Phase 05 encounter, contextual write authorization, patient read projection, revision/audit conventions, and online completion coordination pass.
- Clinical/product/pharmacy experts approve medication-reference provenance, dose/frequency/duration/route vocabularies and bounds, reminder semantics, required finalize fields, correction wording, and print template.
- Phase 09 is not required for persistence/finalization. Prescription and reminder events accumulate safely in outbox/notification contracts until delivery is enabled.
- Phase 10 full medication catalog is not an entry criterion. Its adapter must later pass the same `MedicationReferencePort` contract.

## Non-goals

- No drug alternatives, reservation, fulfillment, pharmacy inventory lookup, adherence (`taken`/`skipped`), online pharmacy order, controlled-substance workflow, QR code, e-signature infrastructure, or automated prescribing.
- No AI prescription creation or direct AI write. Source-AI text can enter only through an explicit doctor action and provenance marker from Phase 05 rules.
- No medical report, sick leave, or referral generation; Phase 07 owns those documents.
- No patient editing, renewal, repeat prescription, dosage inference, or automatic conversion of frequency to times without doctor confirmation.

## Module ownership and SOLID boundaries

### `Prescriptions`

Owns prescription aggregate, item instructions, versions, state/exposure, amendments, active period, access/exposure events, and authorized projections.

```text
CreatePrescriptionDraft
AddOrUpdateDraftItem
RemoveDraftItem
FinalizePrescription
RecordPrescriptionExposure
AmendPrescription
GetPatientPrescription
GetDoctorPrescription
```

### `MedicationReminders`

Owns doctor-confirmed timing rules and occurrence calculation. It emits delivery intents but does not own FCM or adherence.

```text
ConfigureReminder
ConfirmGeneratedReminderTimes
CalculateReminderOccurrences
CancelFutureReminderOccurrences
```

### `PrescriptionDocuments`

Owns prescription render model, template version, render/print/export intent, private artifact reference, and exposure coordination. Rendering is behind `PrescriptionRendererPort`; OS printing remains a client adapter implemented in the Electron main process for the doctor desktop.

### `MedicationReferencePort`

Owned by Prescriptions:

```text
resolve(reference_id) -> MedicationReferenceSnapshot
search(query, locale, limit) -> list<MedicationReferenceSummary>

MedicationReferenceSnapshot:
  reference_id UUID
  display_name_ar / display_name_en
  generic_name nullable
  strength nullable
  dosage_form nullable
  status active|inactive
  source_version
```

- Prescription stores `reference_id` plus an immutable display/strength/form snapshot. Historical text never changes when a catalog entry changes.
- The Phase 06 adapter is a small read-only, clinically approved reference registry. It is not the full medication master: no barcode, manufacturer, packaging, inventory, price, aliases administration, or alternatives.
- Phase 10 implements the full catalog adapter. It may map old references but cannot rewrite historical prescription snapshots.
- If an approved active reference cannot be resolved, finalization fails safely. Do not silently create an arbitrary medication from free text.

### Dependency direction

```text
Prescription HTTP -> Application -> Domain
Application -> EncounterAuthorizationPort / MedicationReferencePort / RendererPort
Infrastructure -> PostgreSQL / S3 / outbox / PDF renderer
Medication catalog later -> implements MedicationReferencePort
```

The aggregate calculates state and active period; controllers, Eloquent observers, renderers, reminder workers, Flutter patient-mobile widgets, and Electron React views contain no prescription mutation rules.

## Packages and platform capabilities

- Laravel/PostgreSQL transactions, policies, idempotency, outbox, private S3, clock, and audit foundations.
- `brick/money` only where a future print/legal fee appears; prescription instructions never use floating-point quantities.
- A reviewed server-side PDF renderer behind `PrescriptionRendererPort` (for example a pinned `dompdf/dompdf` adapter if compatibility/security tests pass). Templates accept escaped typed fields, not arbitrary HTML.
- Flutter patient mobile uses the generated Dart client, Riverpod, Freezed, form validation, and `intl` for read-only prescription/reminder views.
- Electron doctor desktop uses React, TypeScript, TanStack Query, React Hook Form, Zod, MUI, i18next, the generated TypeScript client, and a main-process print/download adapter. The client never renders a legally authoritative prescription from untrusted local state.
- Pest/PHPUnit, property tests for schedules/state machines, snapshot/PDF structural tests, real PostgreSQL concurrency tests, Flutter patient-mobile integration tests, Electron main/preload/renderer tests, and WebdriverIO with `@wdio/electron-service` packaged-app print/E2E tests.

## Data model and migrations

### `prescriptions`

```text
id UUIDv7 PK
patient_id UUID
doctor_id UUID
encounter_id UUID unique
appointment_id UUID
state enum(draft,finalized,exposed,amended)
current_version_id UUID nullable
active_until date nullable
finalized_at / first_exposed_at / amended_at nullable
version bigint
created_at / updated_at
```

- One prescription aggregate per encounter in V1; an ADR is required to allow more.
- Index `(patient_id, state, finalized_at desc)`, `(doctor_id, patient_id, finalized_at desc)`, and `(active_until, state)`.

### `prescription_versions`

```text
id UUIDv7 PK
prescription_id UUID
version_number integer
kind enum(draft_snapshot,final,pre_exposure_correction,amendment)
supersedes_version_id UUID nullable
created_by_doctor_id UUID
reason_code / reason_text nullable
source_ai boolean default false
created_at / finalized_at nullable
content_hash bytea
unique(prescription_id, version_number)
```

- Final/corrected/amendment versions are append-only. Draft working rows may change only through versioned commands until finalization.

### `prescription_items`

```text
id UUIDv7 PK
prescription_version_id UUID
sequence smallint
medication_reference_id UUID
medication_display_name_snapshot varchar(300)
generic_name_snapshot varchar(300) nullable
strength_snapshot varchar(120) nullable
dosage_form_snapshot varchar(120) nullable
dose_value varchar(120)
frequency_code / frequency_text
duration_value integer
duration_unit enum(days,weeks,months)
start_date / end_date date
route nullable
doctor_free_note text nullable
source_reference_version varchar
created_at
```

- Unique sequence per version; check positive duration and `end_date >= start_date`.
- Dose/frequency/note lengths and control characters are bounded; no float-derived arithmetic.
- `active_until = max(item.end_date)` for the current finalized/exposed/amended version.

### `medication_reference_entries`

Temporary Phase 06 adapter storage: stable UUID, bilingual names, generic/strength/form, approved source/version, status, review metadata, timestamps. Only an approved seed/import command changes it. Phase 10 may replace the adapter without dropping historical entries until all mappings are verified.

### `medication_reminder_rules`

```text
id UUIDv7 PK
prescription_item_id UUID
mode enum(exact_times,interval,generated_confirmed)
timezone varchar default Africa/Cairo
exact_local_times jsonb nullable             # bounded validated array of HH:mm
interval_minutes integer nullable
generated_by varchar nullable
confirmed_by_doctor_id UUID
confirmed_at timestamptz
starts_on / ends_on date
status enum(active,cancelled,completed)
version bigint
```

- Check mutually exclusive mode fields. Generated times cannot become active without explicit doctor confirmation.
- JSONB is allowed only for a small bounded ordered list; validate count/format/uniqueness.

### `prescription_access_events`

Append-only event for patient view, `find_medicines`, print, export, pharmacy-use reference if later approved: actor, prescription/version, purpose, occurred time, request/device/IP refs. `find_medicines`, successful print, and successful export are exposure triggers; ordinary authorized patient display is logged but follows the product-approved exposure definition from the source plan.

### `prescription_amendments`

Links original exposed version to corrected version, mandatory reason, doctor, timestamp, changed-field summary/hash, patient-notification outbox ID, and status. Never deletes the original.

### `prescription_documents`

Stores prescription/version, template version, render state, private object ID/hash, created actor/time, exposure-recorded time, and expiry/retention classification. It never stores a public URL.

## State machine and invariants

```text
DRAFT -> FINALIZED -> EXPOSED -> AMENDED
                     ^            |
                     +-- new exposed corrected version
```

1. Only the encounter's authorized assigned doctor may create/edit/finalize; patient/admin/secretary/pharmacy/AI cannot mutate.
2. Draft items may change until finalization. Finalization validates all references/instructions, snapshots them, appends a final version, computes `active_until`, and makes it patient-readable.
3. `FINALIZED` is already immutable and patient-readable. Finalized version/items can never update or delete; every pre- or post-exposure correction appends a new version with audit reason and preserves all prior evidence.
4. `EXPOSED` occurs before the first successful external release through `find_medicines`, print, or export. It is an audit/external-release milestone, never the point at which immutability begins. The state change and access/exposure event are strongly consistent.
5. A post-exposure correction creates an amendment plus a new immutable version; reason/doctor/time/changed fields are mandatory. A pre-exposure correction uses the same append-only safety principle even if its notification/display policy differs.
6. Patient correction notification is inserted into the critical outbox in the amendment transaction. Delivery may be eventual, but intent cannot be lost.
7. Medication reference must be active at draft/finalize according to policy; the stored snapshot is immutable afterward.
8. Reminder times are exact, interval-based, or generated and doctor-confirmed. No automatic schedule is activated from `three times/day` alone.
9. V1 emits notifications only; no adherence state or implicit medical claim.
10. Printed/exported artifact corresponds to one immutable version/hash and never contains hidden stale/latest ambiguity.
11. Prescription text, PDF, dose, notes, and reminder times never enter logs, metrics labels, generic events, or unprotected caches.

## Detailed workflows

### Create and edit draft

1. Doctor opens the current authorized encounter and creates/reuses one draft with idempotency key.
2. Search calls the `MedicationReferencePort` with bounded query/limit and receives safe approved references.
3. Add item resolves reference server-side, snapshots display data, validates dose/frequency/duration/dates/route/note, and appends a draft revision under aggregate lock/version.
4. Update/remove uses expected prescription version. Stale requests return `409`, preserving both client intent and server state for explicit refresh.
5. Draft autosave may use Phase 05 local encrypted outbox, bound to prescription/encounter/patient and reauthorized on sync.

### Configure reminder

- Exact times: validate unique local `HH:mm` values and date window.
- Interval: validate bounded interval and anchor semantics approved by clinical product; do not invent an anchor.
- Generated: application may propose times, but stores them inactive until doctor explicitly confirms exact values.
- Calculate occurrences using `Africa/Cairo` timezone and DST ADR, persist/deliver only within medication start/end. Editing a draft replaces future draft calculations; final changes follow correction/amendment rules.

### Finalize

1. Doctor submits expected aggregate/version, idempotency key, and explicit confirmation.
2. Revalidate active encounter write authorization and every medication reference; lock prescription.
3. Validate at least one item and all required instructions/reminder confirmations.
4. Append immutable final version/items, compute hash and `active_until`, set `FINALIZED`, record audit, and insert `PrescriptionFinalized` outbox in one transaction.
5. Commit; patient may now read it. Same-key retry returns the same version.
6. Phase 04 may complete consultation only when prescription draft policy is satisfied—finalize, explicitly discard empty draft, or leave according to approved completion rules.

### Print/export and exposure

1. Doctor requests render for a specific current version; server reauthorizes own encounter/prescription access.
2. Build a typed escaped render model containing required doctor, clinic, patient, date, medication, dose, frequency, duration, instructions, and signature/stamp area; no QR.
3. Render in a bounded isolated process, verify PDF type/hash/page/size, store in private/quarantine object state.
4. In a transaction, lock prescription/version, record `PRINT|EXPORT` exposure/access event, transition to `EXPOSED` if first use, mark artifact releasable, and audit.
5. Only after commit issue a short-lived actor-bound signed download or stream. If the transaction fails, artifact remains unreachable and is cleaned up.
6. The renderer requests print through a narrow preload operation. Doctor Electron main then verifies the actor-bound artifact metadata, receives bytes only after the exposure commit, and invokes the OS print flow. Renderer-supplied arbitrary paths, URLs, printer options, or bytes are rejected. Cancellation after download cannot undo exposure because the document already left the controlled boundary.

### `Find My Medicines` exposure port

Phase 14 calls `RecordPrescriptionExposure` before returning fulfillment search. It supplies authenticated patient and current prescription/version handles; the port locks, validates ownership/active period, records exposure idempotently, and returns the immutable version snapshot. It never accepts a client-supplied patient ID or marks previous prescriptions active.

### Correct before exposure

- Authorized doctor supplies mandatory reason and expected current version.
- Append a corrected version/items and changed-field summary, retain prior final version, recompute active period/reminders, and audit.
- If patient has already viewed it, product/clinical policy determines notification priority; view access remains recorded.

### Amend after exposure

1. Doctor selects exposed prescription, supplies corrected complete content, reason, expected version, and idempotency key.
2. Lock aggregate and confirm exposed state/doctor ownership; never require renewed full cross-doctor history.
3. Append amendment and corrected version/items, preserve original, cancel superseded future reminder intents, compute new schedule/active period.
4. Set `AMENDED`, insert critical correction notification/event, access/audit records, and commit atomically.
5. Patient views a prominent corrected banner and latest version, with original retrievable through controlled history.

Failure/concurrency behavior:

- Concurrent finalize: one final version; replay returns it, competing payload conflicts.
- Print versus amendment/final correction locks aggregate; artifact always names/hash-binds its exact version.
- Notification provider outage cannot roll back amendment; outbox remains critical and observable.
- Renderer failure never records exposure or returns an artifact.

## API contracts

```text
POST   /encounters/{encounter_id}/prescription
GET    /prescriptions/{id}
GET    /patients/me/prescriptions
GET    /doctors/me/prescriptions/{id}
POST   /prescriptions/{id}/items
PATCH  /prescriptions/{id}/items/{item_id}
DELETE /prescriptions/{id}/items/{item_id}
PUT    /prescriptions/{id}/items/{item_id}/reminder
POST   /prescriptions/{id}/finalize
POST   /prescriptions/{id}/corrections
POST   /prescriptions/{id}/amendments
POST   /prescriptions/{id}/print-artifacts
GET    /prescription-documents/{document_id}/download
GET    /medication-references/search
```

- Mutation contracts require expected version and idempotency key for create/finalize/correct/amend/render.
- Patient DTO marks `current_version`, `original_version_available`, `corrected`, `active_until`, and reminder schedule; it never exposes audit IP/device.
- Medication search result is a reference DTO, not inventory availability or medical advice.
- Print download reauthorizes each request; object keys/unsigned URLs are never returned.

## Events and jobs

```text
PrescriptionFinalized.v1 {prescription_id, version_id, patient_id, doctor_id, encounter_id, active_until}
PrescriptionExposed.v1 {prescription_id, version_id, exposure_type, actor_type}
PrescriptionCorrected.v1 {prescription_id, old_version_id, new_version_id, patient_id, doctor_id, correction_kind}
PrescriptionReminderScheduleChanged.v1 {prescription_id, item_id, rule_id, status, starts_on, ends_on}
PrescriptionPrintArtifactReady.v1 {document_id, prescription_id, version_id, actor_id}
```

Events omit medication names, dose, frequency, notes, patient identity details, PDF/object key, and reminder exact times unless a tightly scoped reminder consumer fetches them after reauthorization.

Jobs:

- Generate bounded PDF artifact, verify/store, then await exposure transaction before release.
- Materialize/dispatch due reminder intents through Phase 09 port, idempotent by rule/occurrence; provider delivery is not owned here.
- Cancel superseded future reminder intents after correction/amendment.
- Orphan render cleanup and prescription/audit/version integrity reconciliation.
- High-priority correction notification retry/dead-letter monitoring through critical queue.

## Client work

### Doctor Electron desktop

- Structured multi-item editor with reference search, immutable medication snapshot display, dose/frequency/duration/dates/route/free note, reminder mode, validation, and explicit finalize confirmation.
- Clearly show Draft, Finalized, Exposed, Amended, current/original versions, sync conflicts, and why editing is locked.
- Correction/amendment requires reason and full review; no one-click destructive replacement.
- Print preview uses server artifact/version/hash; no client-only authoritative PDF.
- The sandboxed React renderer requests preview/print by opaque prescription artifact/version. Electron main owns credentialed download, bounded temporary storage, type/hash verification, print invocation, and prompt cleanup; preload never exposes filesystem, shell, token, or generic print APIs.

### Patient Flutter

- Current/previous prescriptions, corrected banner, latest version default, controlled original history, active period, and reminder schedule.
- No edit/delete/renew/adherence controls in V1.
- Upcoming reminder/home integration exposes minimum lock-screen-safe data through Phase 08/09.

### Other clients

- Secretary/admin have no prescription content route or generated DTO use.
- Pharmacy fulfillment integration is later and must use a separately authorized minimum prescription projection.

## Security, privacy, and clinical-safety controls

- **Unauthorized prescribing/BOLA:** active encounter write policy, doctor assignment/profile status/MFA, server-derived patient/doctor, no client state/author authority.
- **Medication confusion:** stable approved reference, immutable snapshot, inactive-reference rejection, strength/form display, clinical review, no arbitrary unresolved fallback.
- **Silent mutation/repudiation:** append-only versions, hashes, amendment reasons, audit chain/anchor, exact print version, immutable originals.
- **Race/replay:** aggregate locks/version, idempotency request hash, atomic outbox, print/amendment serialization.
- **Unsafe automatic schedule:** mutually exclusive reminder modes, explicit doctor confirmation, bounds/timezone, no adherence inference.
- **Template/PDF injection:** plain-text bounded fields, contextual escaping, no arbitrary HTML/URLs, isolated renderer, file verification, safe disposition, private storage.
- **PHI leakage:** no sensitive event/telemetry/cache content, short-lived authorized artifacts, generic push, printer/spooler risk guidance, access logs.
- **AI overreach:** no AI principal may call finalize/amend; any copied suggestion is a doctor-authored action with `source_ai=true` and human responsibility.

## Test plan

### Unit tests

- Prescription state transitions, version/content hash, active-until maximum, item/date/duration/frequency/route/note bounds, reference snapshots, and amendment diff.
- Reminder exact/interval/generated-confirmed rules, DST/date boundaries, supersession, and occurrence idempotency.
- Authorization matrix and patient/doctor/admin/secretary/pharmacy/AI projections.
- Render-model escaping, template-version selection, and exposure decision logic.

### Integration tests

- Real PostgreSQL concurrent create/edit/finalize/correct/amend/print; unique versions and immutable originals.
- Medication reference adapter active/inactive/version changes and later full-catalog contract fixture.
- S3/private PDF render, hash, exposure-before-release transaction, signed URL expiry, renderer timeout/failure/orphan cleanup.
- Outbox duplicate/retry/dead-letter for correction and reminder intents; no duplicate occurrence.

### Contract tests

- OpenAPI/generated Dart patient-mobile and TypeScript Electron clients for draft/items/reminders/finalize/amend/read/print/reference search.
- Electron print bridge contracts reject forged senders, arbitrary path/URL/content, stale artifact/version, unsupported options, cancellation races, and responses containing tokens or local paths.
- `MedicationReferencePort`, encounter authorization, renderer, object store, reminder delivery, clock, audit, and outbox adapters pass owned contracts.
- Event schemas reject prescription text/medication/dose/note/PDF identity content.

### End-to-end tests

- Authorized encounter → structured draft → doctor-confirmed reminders → finalize → patient read.
- Successful print marks exact version exposed; subsequent mutation is denied and amendment preserves original.
- Correction produces patient high-priority intent and prominent latest-version UI; original remains retrievable.
- Concurrent finalize/update/print/amend paths resolve without overwrite or version ambiguity.
- Patient/admin/secretary/unrelated doctor/AI mutation attempts fail.
- Packaged doctor Electron builds on every supported OS preview and print the exact server hash/version, handle cancel/failure without repeating exposure or mutation, and remove bounded temporary artifacts.

### System tests

- Prescription read p95 at or below 300 ms and write/render queues remain bounded under representative load.
- Renderer/S3/FCM/Redis/worker outage and recovery; finalized prescription remains available from PostgreSQL and no duplicate notifications/exposures.
- Backup/restore preserves versions, amendments, access events, hashes, reminder rules, and private artifacts/rebuild references.
- Rolling medication-reference adapter migration retains historical snapshots.

### Security tests

- BOLA/BFLA/mass assignment, state/version tampering, replay, ID substitution, forged `source_ai`, direct object-key access, signed-URL theft/expiry, and renderer injection.
- Fuzz medication/dose/note Unicode/control characters, huge item counts, reminder arrays/intervals, dates, template inputs, and malformed PDFs.
- Seed prescription/identity canaries and verify absence from logs, traces, Sentry, Horizon, Redis, events, analytics, crash reports, and unauthorized screens.
- Attempt renderer-to-print/filesystem/shell escalation, forged artifact/version/IPC sender, malicious PDF/path, navigation, and token extraction; Electron boundaries fail closed.
- Verify `sqlcipher_flutter_libs` remains absent from the patient-mobile lockfile and no prescription local draft weakens Phase 05 Electron encrypted-storage rules.

## Observability and runbooks

```text
prescription_transitions_total{transition,result}
prescription_conflicts_total{operation}
prescription_reads_total{actor_class,result}
prescription_render_total{result,template_version}
prescription_render_latency_seconds
prescription_exposure_total{type,result}
prescription_correction_delivery_age_seconds
reminder_occurrences_total{result}
medication_reference_resolution_total{result}
```

- No IDs, medication names, doses, notes, or exact reminder times in metric labels.
- Alert on illegal mutation attempts, version/hash integrity mismatch, correction delivery backlog, render failure spike, unexpected exposure, inactive-reference use, and reminder duplication.
- Runbooks cover wrong prescription/patient, medication-reference recall/inactivation, failed finalize, exposed-error amendment, correction-notification outage, wrong print version, artifact leak, and reminder scheduling error.

## Migration and rollout

1. Add prescription/reference/reminder/access/amendment/document schemas and synthetic fixtures; enable read-only reference search first.
2. Populate the minimal reference adapter only from an approved versioned source. If approval is absent, keep prescription finalization disabled rather than accept free text.
3. Enable draft/editor for a clinical pilot, then finalize/patient read after audit and concurrency tests.
4. Enable PDF print/export only after renderer isolation, template clinical/legal approval, and exposure transaction tests.
5. Enable reminder intents before delivery; Phase 09 activation consumes backlog only within valid future occurrence windows.
6. Phase 10 introduces its adapter in shadow comparison, verifies stable mappings/snapshots, then switches the port. Historical rows remain untouched.
7. Rollback disables new mutations/render/reminders while preserving every version/amendment/artifact/audit row for forward recovery.

## Measurable exit gate

- Cannot mutate/delete an exposed prescription; amendments keep original/current versions, reason, actor, time, diff, and patient critical notification intent.
- Concurrent update/finalize/print/amend tests produce deterministic single versions and no overwrite/duplicate side effects.
- `active_until`, all reminder modes, DST/date boundaries, and no-adherence behavior pass clinician-approved cases.
- Full catalog is not required: Phase 06 uses only `MedicationReferencePort`; adapter contract and future catalog compatibility test pass.
- Print artifacts are private, exact-version bound, injection-safe, audited, and mark exposure before release.
- Patient/admin/secretary/pharmacy/AI/unrelated-doctor authorization and telemetry-canary suites pass.
- Prescription read p95 is at or below 300 ms on the agreed dataset; critical correction queue age stays within the approved SLO.
- Clinical/product/security/privacy/legal approve reference provenance, instruction bounds, correction messaging, print template, and threat delta.
- No Critical or unaccepted exploitable High finding remains.

## Deliverables

- `Prescriptions`, `MedicationReminders`, and `PrescriptionDocuments` modules with `MedicationReferencePort`.
- Schemas, minimal approved reference adapter, OpenAPI/events/jobs, doctor editor/print flow, and patient read/reminder views.
- Immutable-version/concurrency/security evidence, template/reference ADRs, dashboards, alerts, and runbooks.
