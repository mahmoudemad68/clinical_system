# Phase 05 — Clinical Records, Encounters, and Local Resilience

## Objective

Deliver the PostgreSQL-authoritative patient medical record, encounter lifecycle implementation required by Phase 04, doctor-authored clinical entries, contextual full-history authorization, patient read-only views, and an encrypted conflict-safe local draft/outbox for transient doctor-desktop outages.

The observable outcome is that a doctor gains cross-doctor history only through the active consultation started atomically in Phase 04, can write only within the assigned active encounter, retains read access only to the doctor's own contributions after completion, and cannot use offline state to bypass a revoked/suspended authorization or overwrite newer server data.

## Plan traceability

- Sections 12-18, lines 492-768: role boundaries, contextual access, patient record, encounter model/lifecycle, and current-patient view.
- Section 29, lines 1046-1074: encrypted local-first clinical draft versus online server-authoritative queue.
- Sections 30 and 38, lines 1078-1103 and 1331-1357: later prescription/lab records attach to encounters through ports.
- Sections 40 and 42, lines 1392-1478: medical-file metadata and future AI-readable reports, implemented later.
- Sections 87-89, lines 2578-2646: later AI uses authorized current context but cannot write records autonomously.
- Sections 104-110, lines 2992-3225: transactional outbox, API rules, idempotency, PostgreSQL truth, clinical tables, and UUIDs.
- Sections 111-123, lines 3229-3493: clinical indexes, Redis/caching constraints, auth, audit, tamper evidence, redaction, and privacy.
- Sections 126-131, lines 3536-3636: backup/recovery and Qdrant not being clinical truth.
- Sections 147 and 152-155, lines 3986-4001 and 4085-4178: later reports, safe retry, local outbox, online pharmacy, and transient doctor offline UX.
- Sections 156-158, lines 4182-4250: test layers, critical authorization, and later prescription tests.
- Sections 172-176, lines 4522-4714: source-of-truth, strong consistency, background work, sequence, and final gate.

## Entry criteria and dependencies

- Phase 01 actor, profile-link, contextual grant, session-revocation, encryption/key-management, audit, and policy foundations pass.
- Phase 02 patient/doctor/location handles and Phase 03 appointment ownership exist.
- Phase 04 queue/consultation schemas and the approved access-timing ADR exist: check-in is eligibility only; atomic Start creates encounter/grant; atomic End finalizes/revokes.
- Clinical/product owners approve minimum encounter-completion requirements, correction semantics, allergy/chronic/current-medication vocabularies, and offline retention/conflict policy.
- A desktop storage spike proves encrypted database support on every target OS before local PHI is enabled.

## Non-goals

- No prescription, reminder, lab, file, report, referral, chat, AI, coding/ICD enforcement, medical-image interpretation, or patient clinical edit.
- No full offline consultation finalization, queue mutation, medical-history cache, or server truth replacement.
- No automated merge of divergent free-text clinical notes.
- No caregiver/proxy access or emergency break-glass until separately approved.
- No Elasticsearch/Qdrant copy of structured medical truth.

## Module ownership and SOLID boundaries

### `Clinical`

Owns encounters, encounter sections, diagnoses, clinical notes, longitudinal allergies/chronic conditions/current medications, authorship/provenance, completion/correction state, and clinical projections.

Public ports:

```text
EncounterLifecyclePort
  start(appointmentContext) -> EncounterHandle
  canComplete(encounterId, expectedVersion) -> CompletionAssessment
  finalize(encounterId, actor, expectedVersion)

MedicalRecordQueryPort
  getCurrentVisitContext(actor, consultationId)
  getAuthorizedLongitudinalRecord(actor, patientId, context)
  getOwnHistoricalContributions(actor, patientId)

ClinicalDraftPort
  saveServerDraft
  applyDraftPatch
  resolveDraftConflict
```

### Policy ownership

`ClinicalRecordPolicy` receives a server-built context and permits:

```text
patient -> read own approved clinical projection; no clinical mutation
assigned doctor + active consultation/grant -> read full authorized history and write current encounter
doctor after completion -> read only entries authored/owned through that doctor's encounters
admin/secretary/pharmacy -> no clinical content
```

The policy does not accept `doctor_id`, `patient_id`, appointment state, or grant scope from a request body. It joins/validates Phase 01 grant, Phase 03 appointment, Phase 04 session, and Clinical encounter state through typed ports.

### Client/local boundaries

Doctor Flutter owns presentation and a local draft repository, not clinical business rules. The local repository implements a narrow `DoctorDraftStore` interface. The API repository reauthorizes every read/write and owns conflict mapping. Local outbox records commands, not arbitrary server state or authorization claims.

### Dependency enforcement

```text
Clinical HTTP/Infrastructure -> Clinical Application -> Clinical Domain
ConsultationControl -> EncounterLifecyclePort <- Clinical Application
Clinical -> Identity/Access/Appointment context ports
Prescriptions/Labs/Files later -> ClinicalEncounterReferencePort
```

Use `deptrac/deptrac` plus architecture tests to reject framework imports in Domain and direct table/model access across modules.

## Packages and platform capabilities

- Laravel/PostgreSQL transactions, policies, outbox, idempotency, UUIDv7, audit, and OpenTelemetry/Sentry redaction from Phase 00.
- `deptrac/deptrac` for PHP module dependency enforcement.
- Flutter Drift for local repositories.
- **Encrypted local database:** Drift over `sqlite3` v3 native hooks configured for either SQLCipher or SQLite3MultipleCiphers after the compatibility ADR. `sqlcipher_flutter_libs` is EOL and must not be added.
- Platform secure storage for a wrapped database key; the key is never stored in the database, preferences, logs, crash reports, or source.
- Freezed/json serialization only at typed boundaries; clinical draft serialization has explicit schema versions and size limits.
- Pest/PHPUnit, real PostgreSQL concurrency tests, Flutter unit/widget/integration tests, native target-OS packaging tests, and k6 clinical-read/write scenarios.

The encrypted SQLite choice must pass Windows, macOS, and Linux packaging/runtime tests for the actual supported doctor-desktop matrix, including key creation, reopen, wrong-key failure, migration, rotation, recovery/failure, and uninstall/backup behavior.

## Data model and migrations

### `encounters`

```text
id UUIDv7 PK
patient_id UUID
doctor_id UUID
appointment_id UUID unique
clinic_location_id UUID
status enum(active,completion_pending,completed,corrected,voided_with_reason)
started_at / completed_at timestamptz nullable
started_by / completed_by UUID nullable
version bigint
created_at / updated_at
```

- Partial/normal indexes `(patient_id, started_at desc)`, `(doctor_id, patient_id, started_at desc)`, `(doctor_id, status, started_at)`, and unique appointment.
- Patient/doctor/location are immutable authoritative snapshots from the appointment at start.
- “Voided” never deletes content; it requires an append-only reason/correction path approved by clinical/legal policy.

### `encounter_history`

```text
id UUIDv7 PK
encounter_id UUID
section_type enum(chief_complaint,history,symptoms,examination,follow_up)
content text
content_format enum(plain_text)
author_doctor_id UUID
revision bigint
supersedes_id UUID nullable
status enum(draft,final,corrected)
created_at / finalized_at nullable
```

- Unique `(encounter_id, section_type, revision)`.
- Text is protected by database/volume encryption and strict row/column authorization; it is not duplicated in logs, events, Redis, or analytics.

### `diagnoses`

```text
id, encounter_id, patient_id, doctor_id
diagnosis_text text
status enum(provisional,final,corrected)
revision, supersedes_id nullable
created_at / finalized_at
```

Diagnosis is free text in V1; no mandatory ICD field. Corrections append a revision and preserve the original.

### `clinical_notes`

Separate private doctor note/encounter note types only if product/legal explicitly defines their visibility. Default V1 assumes patient-readable clinical record content unless a documented lawful purpose and access policy says otherwise. Store author, encounter, revision, supersedes, status, timestamps, and classification.

### Longitudinal clinical facts

`allergies`, `chronic_conditions`, and `current_medications` contain patient, source encounter/doctor, free/structured name as approved, status (`active`, `inactive`, `entered_in_error`), onset/start/end dates where known, provenance, revision/supersedes, and timestamps.

- No destructive update. Status/revision changes preserve the prior assertion.
- Index active facts by `(patient_id, status)` and source by `(encounter_id, created_at)`.
- Patient may report facts during intake in a future phase, but only an authorized doctor promotes them to clinical truth.

### `clinical_entry_audit_refs`

Links sensitive audit events to entity/revision, actor, encounter, action, request/device/IP references, and before/after hashes without duplicating text.

### Server drafts

`encounter_drafts` store encounter/doctor, schema version, encrypted or access-controlled bounded draft payload/section revisions, base encounter version, draft version, updated time, and expiry. Prefer normalized draft rows if patch/query behavior becomes complex; never use unbounded opaque JSON.

### Doctor local database

```text
clinical_drafts
  local_id, encounter_id, appointment_id, patient_pseudonymous_label,
  schema_version, base_server_version, local_version,
  encrypted section payloads, sync_state, updated_at, expires_at

local_outbox
  id, operation enum(save_draft_section), aggregate_id,
  payload_schema_version, encrypted_payload,
  idempotency_key, base_server_version, attempts,
  next_attempt_at, last_safe_error_code, created_at
```

- Do not store full cross-doctor history, National ID, phone, address, unrelated encounters, bearer tokens, or server capability/grant data locally.
- Local records are deleted after acknowledged sync plus approved short recovery window, on logout/revoke where feasible, and at hard expiry.

## Core invariants

1. PostgreSQL is the only medical truth; local SQLite is an encrypted temporary draft/outbox.
2. Check-in alone permits no full record read. Full cross-doctor history requires active consultation, matching grant/session/appointment/encounter/doctor/patient/location, and current actor session.
3. Start creates exactly one encounter and grant in the Phase 04 atomic transaction. Failure creates neither active state nor partial clinical row.
4. Doctor writes only the current assigned active encounter. After completion, ordinary mutation is denied; corrections append revisions under a reasoned workflow.
5. After completion/revocation, doctor reads only that doctor's own encounter contributions for the patient, not other doctors' history.
6. Patient reads only the patient's approved clinical projection and never writes diagnoses, notes, allergies, chronic conditions, current medications, or encounter status.
7. Admin, secretary, pharmacy, unrelated doctor, pending/suspended doctor, and background job without a scoped service identity cannot read clinical content.
8. Every entry has patient, encounter, author, provenance, revision, timestamp, and audit reference; no orphan/unattributed clinical content.
9. Encounter completion and access revocation are one transaction with Phase 04 operational completion.
10. Offline sync reauthenticates and revalidates current server state/version. It never trusts local actor/patient/grant, auto-finalizes, or silently overwrites a conflict.
11. Free-text payloads are bounded, plain-text normalized, safely rendered, and excluded from telemetry/events/cache.
12. Access denial, dependency uncertainty, expired local data, wrong encryption key, or schema mismatch fails closed while preserving recoverable local ciphertext when safe.

## Detailed workflows

### Atomic encounter start

1. Phase 04 locks appointment, queue, current-session guard, and grant state.
2. `EncounterLifecyclePort.start` validates authoritative patient/doctor/location/appointment handles and absence of an encounter.
3. Create `encounter=active` with immutable context; no clinical content is required yet.
4. Phase 04 creates session/current state and matching contextual grant in the same transaction.
5. Audit and outbox rows commit once. A duplicate idempotency key returns the same encounter.
6. Only after commit can doctor request the current-visit projection.

### Open full medical record

1. Doctor requests by active consultation/encounter handle, not arbitrary National ID.
2. Policy resolves actor and joins the active grant/session/appointment/encounter context.
3. Query service loads a bounded, paginated longitudinal projection: demographics, active allergies/conditions/medications, prior diagnoses/visits, and later prescription/lab/file references.
4. Record a `MEDICAL_RECORD_VIEWED` audit event with actor/patient/encounter/purpose/request/device references, never the content.
5. Return projection with field classifications and source/provenance; no Redis PHI cache.

Failure behavior:

- Before start, after end/suspension, wrong doctor/patient/location, stale grant, or unclosed-but-suspended session returns a safe denial/`404`.
- Audit-write failure for a legally required access event fails the read closed or uses an approved durable synchronous audit path; it never silently serves unlogged PHI.

### Save an online clinical draft

1. Client sends one typed section patch, base server version, local draft version, and idempotency key.
2. Server reauthenticates, validates active write context, field/size/schema, and locks encounter/draft section.
3. Same base version applies a new draft revision and audit metadata; outbox is used only for necessary derived effects.
4. Stale base version returns `409 CLINICAL_DRAFT_CONFLICT` with safe current version/section metadata, not an automatic merge.
5. Retry with same key/hash returns the prior revision.

### Transient offline draft

1. When connectivity is lost during an already-started consultation, UI shows `Offline — clinical draft saved locally` prominently.
2. Save typed sections to encrypted local DB using a locally monotonic version and persist one idempotent outbox operation per coalesced section change.
3. Do not fetch/cache new full history offline and do not allow queue/start/end/finalize actions.
4. On reconnect, refresh session and consultation authorization before sending oldest pending operation.
5. Server applies only if encounter remains active/writable and base version matches.
6. On acknowledgement, mark operation complete and remove/archive ciphertext according to recovery retention.
7. On revoked/suspended/completed/conflict response, stop automatic sync, retain safely for the approved window, and require explicit doctor recovery/conflict UI. Never send to another patient/encounter.

### Conflict resolution

- Show server and local versions/last-safe timestamps to the assigned doctor without exposing another encounter.
- For independent structured fields, offer an explicit reviewed selection if product approves.
- For free text, require doctor to choose/copy into a new revision; no character-level auto-merge.
- Resolution is a new idempotent command with both expected versions and audit reason.

### Complete encounter

1. Phase 04 End asks `canComplete` with current versions.
2. Clinical validates required encounter state/content according to clinical/product rules; warnings do not become undocumented hard blockers.
3. Convert approved draft sections to final immutable revisions, mark encounter completed, and append audit.
4. Phase 04 completes appointment/session/queue and revokes full-history grant in the same transaction.
5. Commit outboxes minimal `EncounterCompleted`; local client then deletes acknowledged drafts after recovery retention.
6. If any step fails, consultation stays active and grant/state remain coherent; retry uses the same idempotency key.

### Post-completion read and correction

- Doctor's history query filters by `encounter.doctor_id=current_doctor` and returns only owned contributions.
- Correction requires reason, expected latest revision, new append-only revision, author, timestamp, and audit. It cannot reopen broad cross-doctor history.
- Patient sees corrected/current versions with provenance according to product/legal display policy.

## API contracts

```text
GET  /consultations/{consultation_id}/medical-record
GET  /encounters/{encounter_id}
GET  /patients/me/medical-record
GET  /doctors/me/patients/{patient_id}/own-history
PUT  /encounters/{encounter_id}/draft-sections/{section_type}
POST /encounters/{encounter_id}/draft-conflicts/{conflict_id}/resolve
POST /encounters/{encounter_id}/diagnoses
POST /encounters/{encounter_id}/allergies
POST /encounters/{encounter_id}/chronic-conditions
POST /encounters/{encounter_id}/current-medications
POST /clinical-entries/{entry_id}/corrections
```

- Full-record endpoint requires consultation handle/context; there is no `GET /patients/by-national-id/{id}/medical-record`.
- Writes require idempotency key, encounter/base version, typed bounded plain text, and server-derived doctor/patient.
- Patient endpoint is read-only and projection-specific; unsupported mutations are absent and return method-denied/authorization-safe responses.
- Pagination cursors are actor/scope/filter bound; clinical content never appears in cursors.

## Events and jobs

```text
EncounterStarted.v1 {encounter_id, appointment_id, patient_id, doctor_id, location_id, started_at}
ClinicalDraftSaved.v1 {encounter_id, section_type, revision, author_doctor_id}
ClinicalFactChanged.v1 {patient_id, fact_type, fact_id, status, source_encounter_id}
EncounterCompleted.v1 {encounter_id, appointment_id, patient_id, doctor_id, completed_at}
ClinicalEntryCorrected.v1 {entry_type, entry_id, new_revision_id, doctor_id, reason_code}
```

Events carry no free text, diagnosis, allergy name, medication name, National ID, patient name, phone, or full record.

Jobs:

- Expired server/local-draft metadata cleanup under retention policy; local cleanup is client-driven with server revoke signals.
- Encounter/grant/session consistency reconciliation and high-priority alerting; repair requires explicit safe command.
- Draft conflict/staleness notification to owning doctor without PHI in push.
- Clinical audit completeness verifier and append-only hash-chain/anchor consumer.
- No AI indexing in this phase.

## Client work

### Doctor Flutter desktop

- Current encounter workspace with history, complaint, symptoms, examination, diagnosis, notes, allergies, chronic conditions, current medications, and follow-up sections.
- Riverpod controller separates server projection, editable draft, local sync queue, conflict state, and authorization state.
- Drift database opens only after authenticated OS/app session and key unwrap; wrong-key/open failure never creates a blank replacement over recoverable ciphertext.
- Lock screen/inactivity behavior clears decrypted memory and hides window previews where supported. Clipboard/export/screenshot policy is documented per OS.
- Persistent banner shows `Saved locally`, `Syncing`, `Synced`, `Conflict`, `Access suspended`, or `Completion failed`; never imply server persistence falsely.

### Patient Flutter

- Read-only longitudinal timeline with provenance/correction markers and bounded pagination.
- No edit affordances or hidden mutation calls for clinical content.
- Download/offline caching of clinical history is disabled unless a later reviewed requirement adds it.

### Admin, secretary, pharmacy clients

- No clinical routes, DTOs, caches, search indexes, or generated client conveniences beyond generic `403/404` handling.

## Security and privacy threats and controls

- **Broken contextual authorization:** policy joins all server-owned context; deny by default; active-grant/session/appointment/encounter cross-check; audit every view/write.
- **Stale/offline bypass:** sync reauth/re-authorize/version-check; no offline finalize/start/end; bounded local retention; revoke signal; no cached full history.
- **Lost/compromised desktop:** platform-wrapped encryption key, app inactivity lock, session revoke, minimal local data, no backups where configurable, clear decrypted memory, OS hardening guidance.
- **Cross-patient draft confusion:** immutable encounter/patient binding, signed/authenticated API context, one DB namespace per environment/user or securely scoped rows, prominent identity banner, mismatch hard failure.
- **Silent overwrite/race:** aggregate/section versions, row locks, idempotency, explicit conflict workflow, append-only revisions.
- **PHI leakage:** no PHI events/Redis/metrics/traces/crash reports; serializer allowlists; generic errors; clipboard/export controls; synthetic-only tests.
- **Octane request leakage:** no clinical/actor state in static/singleton memory, terminate/reset hooks, alternating-context tests.
- **Insider/bulk access:** purpose-specific endpoints, pagination/rate/anomaly limits, no National-ID search, query audit, restricted DB/service identities, periodic access review.

## Test plan

### Unit tests

- Encounter, section, diagnosis, fact, completion, correction, and draft/conflict state machines.
- Full authorization matrix before check-in, waiting, active start, completion, suspended/unresolved, own-history, unrelated doctor, patient, admin, secretary, and pharmacy.
- DTO projection/redaction, content bounds/render safety, provenance, revision, retention, and local-outbox retry classification.
- Property tests for patch ordering, duplicate operations, stale versions, clocks, Unicode/control characters, and schema-version rejection.

### Integration tests

- Real PostgreSQL Phase 04+05 atomic Start/End with failure injection at every write; no orphan encounter/grant/session/status/outbox.
- Concurrent section saves/corrections/completion and audit-chain completeness.
- Policy queries against real profile/appointment/session/grant/encounter rows; indexes/query plans at representative history volume.
- Drift + `sqlite3` v3 encrypted backend on each target OS: create/reopen, wrong key, corrupt header, migrations, key rotation, interrupted rotation, restore/forward recovery, uninstall/backup behavior.
- Local outbox reconnect, duplicate retry, conflict, revoke, expired session, completed encounter, and network flap.

### Contract tests

- OpenAPI/generated client compatibility for record, encounter, section, fact, correction, conflict, and own-history projections.
- `EncounterLifecyclePort`, contextual access, patient/doctor directory, audit, local draft, encryption, and clock adapters pass owned contracts.
- Event schemas reject clinical text/identity fields and remain compatible.

### End-to-end tests

- Check-in yields no record access; Start atomically creates encounter/grant and shows full history; End completes/revokes and leaves only own history.
- Patient reads own record and cannot mutate; admin/secretary/pharmacy/unrelated doctor cannot read.
- Doctor edits during network loss, sees truthful local status, reconnects/syncs once, and completes online.
- Conflicting server/local edits require explicit resolution and preserve both evidence versions.
- Lost/revoked doctor device cannot sync or reopen PHI; retained ciphertext follows recovery/cleanup policy.

### System tests

- 500 RPS representative mix includes authorized/denied medical reads and draft writes while maintaining target p95 or approved exception.
- PostgreSQL failover, Redis loss, worker/API kill during start/end/sync, rolling migrations, backup/restore, and audit-anchor recovery.
- Large patient history remains bounded/paginated and does not exhaust Octane workers/client memory.
- AI/Qdrant/S3 outage does not block structured clinical work that does not require files.

### Security tests

- BOLA/BFLA, National-ID search attempts, mass assignment of patient/doctor/encounter/status, stale/revoked grant, orphan grant, forged event, cursor tampering, replay, and race tests.
- Local DB/key extraction review, wrong-user/OS-account access, backup leakage, temp-file/core-dump/crash-log inspection, clipboard/window-preview checklist.
- Seed PHI canaries and search logs, traces, Sentry, Horizon, Redis, events, caches, client analytics/crash artifacts, and generated snapshots.
- Octane alternating patients/doctors and high-volume denied scans prove no cross-request leakage or denial side channel.

## Observability and runbooks

```text
clinical_record_access_total{actor_class,result,reason_code}
clinical_record_latency_seconds{projection}
encounter_transitions_total{transition,result}
clinical_draft_saves_total{result}
clinical_draft_conflicts_total{section_type}
local_sync_results_total{result,error_class}
contextual_grant_mismatch_total{type}
clinical_audit_completeness_total{result}
```

- IDs and content never become metric labels; traces retain safe operation/entity-class metadata only.
- Alert on access-denial anomalies, orphan/mismatched grants, bulk record views, conflict spikes, audit write/anchor failures, old local/server drafts, completion failures, and redaction canaries.
- Runbooks cover wrong patient/doctor encounter, suspected PHI exposure, lost device, failed/corrupt encrypted local DB, key rotation, sync conflict, stuck completion, orphan grant, and audit outage.

## Migration and rollout

1. Add clinical schemas, indexes, policies, and synthetic histories; validate query plans and backup/restore before feature enablement.
2. Deploy `EncounterLifecyclePort` disabled, run Phase 04 atomic integration tests, then enable Start/End for a controlled clinic.
3. Enable read-only current-record view before clinical writes; monitor access/audit correctness.
4. Enable online drafts/entries, then encrypted local drafts per target OS only after the compatibility/key-rotation ADR and native tests pass.
5. Use expand/deploy/backfill/switch for every clinical schema change; never destructive rollback. Disable writes and forward-recover on incompatibility.
6. Retain client schema compatibility for at least the supported offline window; server rejects unsupported stale draft versions safely.

## Measurable exit gate

- Before Start and after End/suspension, cross-doctor full-history access is denied; during active matching consultation it is allowed and audited.
- Phase 04+05 failure-injection/concurrency tests produce exactly one coherent encounter/session/grant and atomic completion/revocation.
- Patient read-only, doctor-own-history, admin/secretary/pharmacy denial, and cross-patient/doctor/location suites pass at API and database-query layers.
- No seeded PHI/identity/credential canary appears in telemetry, events, Redis, client artifacts, or unauthorized projections.
- Drift + `sqlite3` v3 SQLCipher/SQLite3MultipleCiphers compatibility, key rotation, migration, wrong-key, corruption, and recovery tests pass on every supported doctor OS; EOL `sqlcipher_flutter_libs` is absent from lockfiles/SBOM.
- Offline sync never auto-finalizes or overwrites a conflict and rejects revoked/completed/mismatched context.
- Clinical read p95 is at or below 250 ms and normal draft write p95 at or below 400 ms on the agreed representative dataset, or an approved measured exception exists.
- Clinical/product/security/privacy approve completion rules, correction policy, local retention, and threat-model delta.
- No Critical or unaccepted exploitable High finding remains.

## Deliverables

- `Clinical` module, encounter lifecycle port, schemas, policies, APIs, events, audit/reconciliation jobs, and patient/doctor projections.
- Doctor encrypted local-draft/outbox implementation and conflict UI; patient read-only record UI.
- Access matrix, native compatibility/key ADR, migration/retention evidence, layered tests, dashboards, alerts, and runbooks.
