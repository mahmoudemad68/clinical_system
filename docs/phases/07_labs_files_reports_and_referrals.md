# Phase 07 — Labs, Medical Files, Reports, and Referrals

## Objective

Deliver encounter-scoped lab requests, patient result upload or delivered-without-upload workflows, doctor review/receipt confirmation, private medical-file storage, medical reports, sick leave, referrals, audited access, and secure rendering/printing.

The observable outcome is that every file remains quarantined until validation and malware scanning succeed, every download is reauthorized and logged, patients cannot close their own lab requests, and a doctor can create/review documents only for an authorized or doctor-owned encounter without gaining broad historical access.

## Plan traceability

- Sections 13-18, lines 510-768: contextual record access, patient read-only clinical data, encounter ownership, lifecycle, and doctor actions.
- Sections 37-42, lines 1300-1478: printing, independent lab entity, lab lifecycle, private files, upload pipeline, OCR fallback later, and no medical-image AI.
- Section 43, lines 1482-1506: patient home later surfaces pending labs.
- Sections 78 and 86-89, lines 2387-2400 and 2542-2646: later clinical-document indexing/AI context, ingestion, and AI read-only behavior.
- Sections 100-104, lines 2898-3025: lab notifications, file/critical queues, Horizon, and outbox.
- Sections 105-110, lines 3029-3225: API/ID rules and lab/file/report/referral tables.
- Sections 117 and 120-123, lines 3346-3493: private storage, audit events, tamper evidence, log redaction, and privacy.
- Sections 126-131, lines 3536-3636: S3 versioning/encryption/backups and rebuildable AI data.
- Section 147, lines 3986-4001: encounter-authorized medical report, sick leave, referral, and editable templates.
- Sections 152, 156-157, 165-169, lines 4085-4109, 4182-4236, and 4367-4480: safe retries, layered/authorization tests, environment isolation, CI, migrations, and secrets.
- Sections 172-176, lines 4522-4714: file/medical truth, consistency, implementation sequence, and final gate.

## Entry criteria and dependencies

- Phase 05 encounter ownership, clinical policies, patient read projection, correction/audit rules, and contextual grant behavior pass.
- Phase 06 versioned document-rendering patterns may be reused, but lab/file persistence does not depend on prescription functionality.
- Private S3-compatible storage has versioning, encryption, no-public-access policy, separate credentials, lifecycle/backup controls, and signed URL support.
- Security approves malware-scanner and parser isolation; clinical/product approves lab lifecycle, catalog/free-text policy, report/referral required fields, document visibility, and correction semantics.
- Privacy/legal defines file/report retention, access-event retention, patient-supplied-content handling, and deletion/legal-hold behavior.

## Non-goals

- No laboratory-system API, machine-verified result feed, structured analyte interpretation, AI reading, OCR indexing, medical-image diagnosis, DICOM/PACS, or pixel interpretation.
- No public bucket/object URL, email attachment delivery, QR code, external electronic signature, insurance form, or patient closure of a request.
- No AI-generated medical report/referral and no admin access to patient document contents.
- No arbitrary office document, archive, executable, audio, or video upload; V1 supports approved PDF and image formats only.

## Module ownership and SOLID boundaries

### `Labs`

Owns lab catalog/reference items, encounter lab requests, requested items/free text, result mode, lifecycle, patient submission handle, doctor review/receipt confirmation, and safe projections.

```text
CreateLabRequest
SubmitLabResultFile
MarkLabDelivered
ConfirmLabReceived
ReviewLabResult
GetPatientLabRequests
GetDoctorLabRequests
```

### `MedicalFiles`

Owns file metadata, purpose/owner binding, quarantine-to-available state machine, object-store indirection, access authorization/logs, integrity, retention state, and download issuance.

```text
RequestMedicalUpload
CompleteMedicalUpload
AttachAvailableFile
IssueAuthorizedDownload
QuarantineFile
RetireFile
```

It depends on narrow ports: `ObjectStore`, `ObjectInspector`, `MalwareScanner`, `FileHasher`, `Clock`, `Audit`, and `Outbox`. No caller receives an S3 object key.

### `ClinicalDocuments`

Owns medical reports, sick leave, referrals, templates, immutable versions/corrections, render artifacts, and doctor/patient projections.

```text
CreateMedicalReport
CreateSickLeave
CreateReferral
CorrectClinicalDocument
RenderClinicalDocument
```

### Boundary rules

- Labs and ClinicalDocuments reference `encounter_id` through Phase 05 ports and never infer authority from a client patient ID.
- MedicalFiles does not decide clinical visibility itself; the owning resource supplies a typed `FileAccessPurpose` and MedicalFiles revalidates through an owner policy port before download.
- Future AI ingestion consumes only an `AvailableClinicalDocumentPort` with current authorization/version/status; Phase 07 never pushes automatically to Qdrant.
- Rendering is an adapter. Domain/application code owns document content/version/exposure rules.

## Packages and platform capabilities

- Laravel filesystem/S3 adapter, queues/Horizon, policies, PostgreSQL, outbox, idempotency, audit, and OpenTelemetry from Phase 00.
- A maintained libmagic-compatible signature detector behind `ObjectInspector`; declared MIME alone is never trusted.
- A network or local malware scanner behind `MalwareScanner` (for example ClamAV in isolated deployment) with explicit timeout/error classes and fail-closed policy.
- Bounded PDF/image metadata parsers selected/pinned after security review; no general-purpose conversion inside API workers.
- The Phase 06 reviewed server-side PDF rendering port/template controls for report/sick-leave/referral artifacts.
- Flutter secure file/image picker and generated API client. Selected local files are removed from application temp storage promptly after completion/cancel.
- Pest/PHPUnit, S3-emulator integration tests, malicious-file corpus, Flutter/Playwright E2E, and storage-policy/DAST tests.

## Data model and migrations

### `lab_catalog`

```text
id UUIDv7 PK
stable_code varchar unique
name_ar / name_en
status enum(active,inactive)
version bigint
created_at / updated_at
```

Seed approved common tests such as CBC/HbA1c; inactive items remain resolvable historically. Doctor may add a bounded custom test label per source plan.

### `lab_requests`

```text
id UUIDv7 PK
encounter_id UUID
patient_id / doctor_id UUID
status enum(requested,uploaded,patient_marked_delivered,doctor_confirmed_received,reviewed,cancelled_with_reason)
result_mode enum(pending,file_upload,physical_delivery) default pending
requested_at
uploaded_at / patient_marked_delivered_at / received_at / reviewed_at nullable
reviewed_by_doctor_id nullable
version bigint
created_at / updated_at
```

- Index `(patient_id, status, requested_at desc)`, `(doctor_id, status, requested_at desc)`, `(encounter_id, requested_at)`.
- Only authorized doctor may cancel with reason before evidence/review; cancellation never deletes history.

### `lab_request_items`

```text
id, lab_request_id, sequence
lab_catalog_id nullable
catalog_name_snapshot nullable
custom_test_text nullable
instructions_text nullable
created_at
```

- Exactly one of catalog reference or custom test is present. Unique `(lab_request_id, sequence)`; bounded plain text.

### `lab_results`

```text
id UUIDv7 PK
lab_request_id UUID
medical_file_id UUID nullable
submission_type enum(file,physical_delivery)
submitted_by_patient_id UUID
status enum(pending_file,available,received,reviewed,rejected_with_reason)
version bigint
created_at / updated_at
```

- One current result submission per request in V1 unless product approves resubmission/versioning. Corrections/replacements append a new version/link, never overwrite the file.

### `medical_files`

```text
id UUIDv7 PK
owner_patient_id UUID
purpose enum(lab_result,medical_attachment,verification,clinical_document_artifact)
owner_resource_type / owner_resource_id
object_handle UUID unique
storage_version_id varchar nullable
declared_mime / detected_mime varchar
original_name_ciphertext bytea nullable
size_bytes bigint
sha256 bytea
uploaded_by_type / uploaded_by_id
state enum(requested,uploading,quarantined,validating,scanning,available,rejected,retired)
rejection_reason_code nullable
retention_class varchar
version bigint
uploaded_at / available_at / retired_at nullable
created_at / updated_at
```

- Unique hash is not global deduplication across patients; cross-patient hash equality must not reveal existence or reuse authorization.
- Index `(owner_patient_id, created_at desc)`, `(owner_resource_type, owner_resource_id, state)`, `(state, created_at)`, and hash only for internal integrity/reconciliation with restricted access.

### `file_access_logs`

Append-only: file/resource/patient, actor/service identity, purpose, decision, request/device/IP references, issued time, download token ID/expiry, completion if observable. No object key or file body. Index file and actor/time for audit.

### `medical_reports`, `referrals`, and document versions

```text
clinical_documents:
  id UUIDv7, type enum(medical_report,sick_leave,referral),
  patient_id, doctor_id, encounter_id, state enum(draft,finalized,corrected),
  current_version_id, version, finalized_at, created_at, updated_at

clinical_document_versions:
  id, document_id, version_number, template_version_id,
  structured_content, author_doctor_id, reason_code nullable,
  supersedes_version_id nullable, content_hash, finalized_at

clinical_document_templates:
  id, type, language, version_number, status, structured_schema,
  render_template_handle, approved_by, approved_at
```

- `structured_content` is bounded, schema-validated JSONB only where fields vary by document type; searchable core identifiers/dates remain relational.
- Sick leave includes approved start/end dates and bounded reason/statement fields. Referral includes specialty/recipient text, reason, clinical summary drawn/confirmed by the doctor, urgency vocabulary, and no automatic booking.
- Final versions are append-only. Correction preserves prior version and reason.

## Core invariants

1. A lab request is independent of prescription even when both share an encounter.
2. Only the encounter's authorized doctor creates a request. Patient may upload/mark delivered but cannot mark reviewed/closed.
3. File path state and lab state are coordinated: a result cannot become `uploaded/available` until its file is `available` after all checks.
4. Physical-delivery path requires patient mark, then doctor confirmation, then doctor review; patient action alone is never terminal.
5. Objects are private, randomly addressed behind opaque handles, encrypted, versioned, and never directly public.
6. Declared type, extension, and browser MIME are untrusted. Detected magic/type, size, scan, hash, and owner binding must all pass.
7. Scanner/inspector timeout, outage, unknown result, mismatch, or callback replay leaves file unavailable.
8. Every doctor view/download reauthorizes current context or doctor-owned encounter access and writes a durable access log.
9. Patient accesses only own available files/documents. Admin, secretary, pharmacy, unrelated doctor, and AI without an explicit later scoped contract are denied.
10. Doctor creates reports/sick leave/referrals only during or after that doctor's own encounter; post-visit creation does not restore other doctors' history.
11. Final clinical documents and file versions are not overwritten/deleted; correction/retirement preserves audit/evidence under retention policy.
12. Medical images may be stored/viewed, but no V1 service interprets pixels or claims diagnosis.

## Detailed workflows

### Create lab request

1. Doctor in active authorized encounter selects approved catalog items and/or bounded custom tests.
2. Server resolves encounter patient/doctor, validates each item/instruction, expected encounter/request version, and idempotency key.
3. Create request/items/audit/outbox in one transaction; status `requested`.
4. Commit and expose to patient. Phase 09 later sends the notification.

### Request and complete upload

1. Authenticated patient requests upload for a specific own lab request; server validates request state, purpose, declared type, expected size, quota, and idempotency.
2. Create `medical_file=requested` and an object handle bound to patient/resource/purpose. Generate short-lived single-purpose upload credentials for a random quarantine object; return no permanent object key.
3. Client uploads with enforced content-length/checksum where supported, then calls complete with upload ID and expected checksum.
4. Server HEADs object using service credentials, verifies exact binding/size, moves state to `quarantined`, and enqueues validation.
5. Worker detects magic/MIME, enforces type/size/dimensions/page/count/resource limits, scans malware in isolation, computes SHA-256, and verifies object did not change during processing.
6. On success, transaction marks file available, creates/updates lab result, transitions lab request to `uploaded`, audits, and outboxes events.
7. On any failure, mark rejected with safe reason, keep inaccessible for approved forensic/cleanup retention, and allow a new versioned attempt under rate/quota rules.

Concurrency/failure controls:

- Complete callback is idempotent and locks upload/file. Duplicate callbacks do not attach twice.
- Scanner crash leaves `quarantined/scanning`; watchdog retries boundedly or dead-letters visibly.
- Object mutation/version mismatch fails; validation reads one immutable storage version.
- API never waits synchronously for scan/OCR/render.

### Mark physical delivery and doctor confirmation

1. Patient chooses `Mark Delivered`; server validates ownership/requested state and records `patient_marked_delivered` idempotently.
2. This state clearly says patient-reported, not verified.
3. Assigned doctor confirms receipt from own lab queue, transitioning to `doctor_confirmed_received`.
4. Doctor reviews and transitions to `reviewed`; review time/actor/audit are mandatory.
5. Patient cannot call either doctor transition; admin/secretary cannot impersonate it.

### Doctor review uploaded result

1. Doctor opens request through active consultation full-history context or doctor-owned prior encounter relationship.
2. Server reauthorizes request/file and records `file_access_log` before issuing a short-lived download/stream.
3. UI renders only approved PDF/image types in a sandboxed viewer or trusted OS viewer with safe disposition.
4. Doctor explicitly marks reviewed; transaction validates file available/current, records reviewer/time, status/audit/outbox.

### File download

1. Resolve opaque file ID and owner policy; do not accept object key/path.
2. Deny unavailable/retired/quarantined/rejected files and unauthorized actors with safe response.
3. Record access decision and issue a short-lived, purpose/actor/session-bound token or proxy stream.
4. Set safe content type, `nosniff`, restrictive disposition/file name, cache controls, and CSP/sandbox for browser rendering.
5. Signed URL expiry does not replace authorization; every issuance is rechecked/audited.

### Medical report, sick leave, or referral

1. Doctor selects one of the doctor's encounters and authorized active template version.
2. Server permits active encounter or post-completion own-encounter context only; it does not reopen full history.
3. Create/edit structured draft with expected version; all patient/doctor/encounter identity comes from server.
4. Doctor explicitly finalizes. Append immutable version/hash, audit, and outbox.
5. Render through typed escaped template, validate/store private artifact, then authorize print/download as in Phase 06.
6. Correction appends a new version with mandatory reason and preserves prior artifact/version; patient sees current/correction marker.

### Future AI handoff boundary

Phase 16 may fetch available textual reports/lab PDFs through a service identity plus patient/doctor/encounter authorization and data-minimization policy. It must not access quarantine, infer authorization from Qdrant, or interpret radiology pixels. Phase 07 emits only safe readiness IDs; it does not enqueue AI automatically without the future approved ingestion command.

## API contracts

```text
GET    /lab-catalog
POST   /encounters/{encounter_id}/lab-requests
GET    /patients/me/lab-requests
GET    /doctors/me/lab-requests
POST   /lab-requests/{id}/upload-intents
POST   /medical-upload-intents/{id}/complete
POST   /lab-requests/{id}/mark-delivered
POST   /lab-requests/{id}/confirm-received
POST   /lab-requests/{id}/review
GET    /medical-files/{id}/download

POST   /encounters/{encounter_id}/clinical-documents
PATCH  /clinical-documents/{id}
POST   /clinical-documents/{id}/finalize
POST   /clinical-documents/{id}/corrections
POST   /clinical-documents/{id}/render-artifacts
GET    /patients/me/clinical-documents
```

- Mutations use expected version and idempotency where replayable.
- Upload intent binds expected bytes/type/checksum/resource and expires quickly; complete does not accept a new patient/resource binding.
- List/download resources are projection/purpose-specific. No generic `/files?patient_id=` or object-key endpoint.
- Processing responses expose safe states/reasons (`QUARANTINED`, `PROCESSING`, `AVAILABLE`, `REJECTED_TYPE`, `REJECTED_SIZE`, `SCAN_FAILED_RETRYABLE`) without malware signatures/internal paths.

## Events and jobs

```text
LabRequested.v1 {lab_request_id, encounter_id, patient_id, doctor_id, requested_at}
LabResultUploadCompleted.v1 {lab_request_id, medical_file_id, upload_id}
MedicalFileAvailable.v1 {medical_file_id, owner_resource_type, owner_resource_id, detected_type, sha256_ref}
MedicalFileRejected.v1 {medical_file_id, reason_code}
LabDeliveryMarked.v1 {lab_request_id, patient_id, marked_at}
LabReceivedConfirmed.v1 {lab_request_id, doctor_id, confirmed_at}
LabReviewed.v1 {lab_request_id, doctor_id, reviewed_at}
ClinicalDocumentFinalized.v1 {document_id, document_type, version_id, patient_id, doctor_id, encounter_id}
ClinicalDocumentCorrected.v1 {document_id, old_version_id, new_version_id, reason_code}
```

Events contain no lab content, result value, report/referral text, patient identifiers, object key, URL, or malware detail.

Jobs:

- Quarantine inspector/scanner/hasher with deadlines, resource budgets, immutable object-version binding, and dead-letter state.
- Abandoned upload, rejected/quarantined retention, and orphan artifact cleanup.
- Private document renderer/validator and artifact lifecycle.
- File metadata/object reconciliation, hash integrity sampling, and access-log/audit completeness verification.
- Lab pending/unreviewed reminder intent to Phase 09 without patient content.

## Client work

### Patient Flutter

- Lab request list with requested/upload processing/uploaded/delivered/received/reviewed states and clear provenance.
- Secure PDF/image picker, declared size/type precheck, progress, cancel/retry, asynchronous scan status, and safe rejection messages.
- `Mark Delivered` warns that only the doctor can confirm/review.
- Read finalized reports/sick leave/referrals through authorized ephemeral download; do not persist into Drift by default.

### Doctor Flutter desktop

- Current encounter lab request editor, pending results queue, secure viewer, explicit confirm/review actions, and result-state version conflicts.
- Medical report/sick-leave/referral structured editor, template version, finalize/correct/print flow, and patient/current-version identity banner.
- No AI interpretation button until later feature/clinical gates.

### React admin and staff clients

- Admin may manage non-clinical template definitions only if capability approved; preview uses synthetic placeholders. No patient document/result content.
- Secretary may see a safe operational “pending lab” indicator only if product policy requires it, never test names/results/files.

## Security and privacy threats and controls

- **Malware/parser exploit:** quarantine, magic-byte validation, type allowlist, immutable version, malware scan, sandboxed bounded workers, no API-worker parsing, fail closed.
- **Path/object/BOLA:** random opaque handles, no user path/object key, owner-resource binding, policy on every issue/download, short token, private bucket/network.
- **Stolen signed URL:** minimum TTL, actor/session/purpose binding or proxy stream where supported, safe headers, access audit, revoke-aware issuance, no public caching.
- **File replacement/TOCTOU:** storage version/checksum binding and immutable processing source; reverify before availability.
- **Cross-patient dedup leak:** no global dedup response or reused authorization; hash access restricted.
- **Clinical status forgery:** server state machine, doctor-only confirm/review/finalize, expected versions, audit, idempotency.
- **Document/template injection:** typed schema, bounded plain text, contextual escaping, no arbitrary HTML/script/remote resources, isolated renderer.
- **Sensitive telemetry/provider leakage:** no file/report content in errors/logs/traces/events; scanner receives only object bytes/opaque ID under processor contract; synthetic staging only.

## Test plan

### Unit tests

- Lab request/result/file/document/template state machines and every denied skipped/backward transition.
- Catalog/custom-item exclusivity, role/context policies, file purpose/owner binding, content limits, render schema, correction/version/hash rules.
- Safe file-name/content-disposition generation and event/log redaction.

### Integration tests

- Real PostgreSQL concurrent upload completion, delivered/received/review transitions, document finalize/correct, and outbox atomicity.
- S3 emulator private policy, versioning/encryption metadata, upload TTL/size/checksum, anonymous denial, signed download expiry, and backup reference.
- Scanner/inspector fixtures for valid PDF/JPEG/PNG, declared/detected mismatch, truncated/polyglot/encrypted PDF, malware, oversized dimensions/pages, decompression/resource bomb, timeout/crash/retry.
- Renderer injection/remote-resource denial, hash/version binding, and orphan cleanup.

### Contract tests

- OpenAPI/generated clients for lab/file/document workflows, processing states, safe errors, and download behavior.
- Object store, inspector, scanner, hasher, encounter authorization, renderer, audit, and future AI document ports pass owned contracts.
- Event schemas reject clinical/file content and remain compatible.

### End-to-end tests

- Doctor requests lab → patient uploads → quarantine/scan → doctor authorized view/review → patient sees reviewed.
- Patient marks physical delivery → only doctor confirms/marks reviewed.
- Malicious/invalid upload remains unavailable and retry creates a separate safe attempt.
- Doctor creates/finalizes/prints/corrects report/sick leave/referral for own encounter; unrelated actor/admin/secretary denied.
- Expired/stolen upload/download token, wrong patient/resource binding, and retired file all fail.

### System tests

- Scanner/S3/renderer/queue outage/backlog/recovery; API stays responsive, no unscanned release, no duplicate attachment/status.
- Upload concurrency/large permitted files under configured quotas and bounded worker memory/CPU/disk.
- Database/S3 backup and isolated restore reconcile every available file/version/hash and preserve access logs.
- AI/Qdrant outage has no effect; no medical-image interpretation service is invoked.

### Security tests

- BOLA/BFLA/mass assignment, object-key/path traversal, URL replay, MIME spoof/polyglot, malware, archive/compression bomb, parser RCE regression corpus, SSRF/remote font/image template attempts, and stored XSS in names/text.
- Attempt patient/admin/secretary review/finalize, doctor cross-patient access, quarantine download, callback forgery, object swap, and hash/dedup enumeration.
- Seed file/report/lab/identity canaries and verify absence from telemetry, queues, Redis, events, analytics, admin/staff projections, temp files, and crash artifacts.
- Storage-policy audit proves no public ACL/policy, encryption/versioning enabled, and service credentials least privileged.

## Observability and runbooks

```text
lab_transitions_total{transition,result}
lab_pending_age_seconds{state}
upload_intents_total{result,purpose}
medical_file_processing_total{stage,result,detected_type}
medical_file_processing_seconds{stage}
quarantine_objects{age_bucket,state}
file_download_total{actor_class,result,purpose}
clinical_document_transitions_total{type,transition,result}
renderer_jobs_total{type,result}
file_reconciliation_mismatches_total{type}
```

- IDs, names, file names, hashes, lab/test/report content, and object keys never become metric labels.
- Alert on scan bypass/unknown, quarantine age, malware spike, anonymous/public access, access-denial anomaly, bulk downloads, object/metadata mismatch, unreviewed SLA, renderer failures, and audit gaps.
- Runbooks cover malicious file, public-bucket exposure, stolen URL, wrong-patient attachment, scanner outage/backlog, object loss/corruption, failed review transition, incorrect clinical document, and S3 restore.

## Migration and rollout

1. Create lab/file/document schemas and private storage prefixes/policies using synthetic files; validate policy from public/internal network positions.
2. Enable lab request creation, then upload intents with processing but no release; inspect scanner metrics/corpus results.
3. Enable availability/download for a controlled cohort only after fail-closed and access-log tests pass.
4. Enable physical-delivery and review transitions, then clinical documents/rendering after template clinical/legal approval.
5. Keep future AI ingestion flag off; no existing file is indexed automatically when later phases deploy without explicit migration/job approval.
6. Rollback disables new uploads/renders/transitions, preserves objects/metadata/audit, and forward-recovers processing jobs. Never make quarantine public to work around an outage.

## Measurable exit gate

- 100% of valid files pass type/size/signature/scan/hash before availability; every injected failure leaves them unavailable.
- Anonymous, cross-patient, cross-doctor, admin, secretary, pharmacy, expired-token, object-swap, and quarantine download tests pass.
- Patient cannot close/review a lab request; doctor confirm/review state and audit are mandatory and race-safe.
- Every medical-file download creates a durable access record and exposes no object key/public URL.
- Report/sick-leave/referral final/corrected versions are immutable, exact-template/hash bound, and limited to the doctor's own encounter context.
- Scanner/S3/renderer outages recover without duplicate attachments, lost committed state, or core clinical outage.
- Restored S3/PostgreSQL data reconcile available files and version hashes within the approved RPO/RTO evidence.
- Clinical/security/privacy/legal approve file types/limits, retention, templates, correction/display rules, and threat delta.
- No Critical or unaccepted exploitable High finding remains.

## Deliverables

- `Labs`, `MedicalFiles`, and `ClinicalDocuments` modules, schemas, APIs, events, jobs, policies, and reconciliation tools.
- Patient upload/lab/document flows, doctor request/review/document workflows, and secure render/view/print adapters.
- Malicious-file corpus results, storage policy/backup evidence, dashboards, alerts, runbooks, and approval records.
