# Phase 02 — Onboarding, Verification, Profiles, and Locations

## Objective

Deliver patient, doctor, clinic-staff, and pharmacy-organization profiles; secure doctor/pharmacy verification; admin approval without clinical-data access; and location/membership foundations. The phase must support patient profiles without user accounts and safely attach a newly verified account to one existing profile without creating a second medical record.

The observable outcome is that approved synthetic actors can reach only their profile/organization capabilities, pending or rejected actors receive no clinical/business privileges, and every verification document and decision is private, least-privileged, and auditable.

## Plan traceability

- Sections 6-9, lines 271-425: separate accounts/profiles, National ID, patient registration, and existing-profile attachment.
- Sections 10-11, lines 429-488: doctor and pharmacy self/admin registration and approval.
- Sections 12-14, lines 492-595: actor boundaries and no clinical visibility for admin/secretary.
- Section 19, lines 770-792: one doctor to many clinic locations with schedules, pricing, and staff delegated to later modules.
- Section 40-41, lines 1392-1448: private object storage and safe upload controls reused for verification documents.
- Section 48, lines 1607-1630: pharmacy organization and branches.
- Sections 109-111, lines 3117-3254: profile, verification, location, organization, branch, and index foundations.
- Sections 117-123, lines 3346-3493: encryption, MFA, rate limiting, audit, redaction, and privacy.
- Sections 145 and 149-151, lines 3936-3959 and 4027-4081: safe admin summaries, Egypt configuration, location, and maps boundary.
- Sections 157 and 165-169, lines 4216-4236 and 4367-4480: authorization tests, environment isolation, CI, migrations, and secrets.

## Entry criteria and dependencies

- Phase 01 account, phone verification, identity protection, sessions, capabilities, profile-link port, and privileged MFA gates pass.
- Private S3-compatible storage, signed upload, malware scanning adapter, outbox, audit, and redaction foundations from Phase 00 are available.
- Product/legal defines required doctor and pharmacy documents, allowed rejection reasons, retention, reviewer qualifications, and appeal/re-submission rules as configuration—not hard-coded assumptions.
- An ADR defines whether Egyptian National ID validation includes an official check algorithm; until approved, validate the agreed canonical 14-digit format without claiming government verification.

## Non-goals

- No appointment schedules, prices, availability, or booking; Phase 03 owns them.
- No medical record, diagnosis, prescription, lab, or clinical file.
- No pharmacy inventory, POS, medication catalog, or external integration.
- No complex admin-role designer, branch transfer, multi-country behavior, or public verification-document URL.
- No automated external government/syndicate/license verification unless separately contracted and approved.

## Module ownership and SOLID boundaries

### `Patients`

Owns patient demographic profiles, protected identity match handle, account attachment, demographic correction history, and profile lifecycle. It exposes clinical-neutral projections only.

```text
CreatePatientProfile
CreateUnlinkedPatientProfile
AttachVerifiedAccount
GetOwnPatientProfile
UpdateOwnDemographics
ResolvePatientHandle          # narrow server-to-server port
```

`Patients` never reads encounter, prescription, lab, or clinical tables. “Patient cannot edit clinical data” does not prohibit correction of approved demographic fields; every allowed field and provenance is explicit.

### `Doctors`

Owns doctor profile, specialty assignment, professional status, public directory-safe fields, and verification linkage. It does not own clinic schedules or encounters.

### `Pharmacies`

Owns pharmacy organizations, branches, memberships, public branch identity/location/payment-method capability metadata, and verification linkage. Inventory and financial modules later consume opaque organization/branch IDs.

### `Clinics`

Owns clinic locations and clinic-staff memberships. Phase 03 owns schedule/appointment type records associated with these locations.

### `Verification`

Owns verification cases, requirements, document references, reviewer assignment, decisions, reasons, re-submission, and audit projection. It depends on `VerificationDocumentStore`, `MalwareScanner`, and applicant ports; it cannot query clinical modules.

### `Admin`

Owns the verification work queue UI/query, but decision commands call `Verification`. Admin DTOs contain only application/profile/legal-document fields approved for verification. They must not contain medical-record joins or generic patient lookup.

Dependency rule:

```text
Patients/Doctors/Pharmacies/Clinics -> Identity/Access public ports
Verification -> applicant + document ports
Admin -> Verification application API
No module -> another module's Eloquent model/table
```

Ports are split by action (`StoreVerificationObject`, `ReadVerificationObject`, `ScanVerificationObject`, `PublishVerificationDecision`) so a reviewer cannot accidentally acquire object-administration or clinical permissions.

## Packages and platform capabilities

- Laravel validation, policies, Eloquent/PostgreSQL transactions, filesystem/S3 adapter, queues, and outbox conventions established in Phase 00.
- PostGIS `geography(Point, 4326)` and GiST indexes for clinic/branch locations.
- A maintained image/PDF metadata inspection library and malware-scanner adapter selected and pinned after a parser-risk review; document contents remain quarantined before scan completion.
- `brick/money` is not used here except for future-compatible public DTO types; location profile data contains no prices.
- React Hook Form + Zod + MUI for admin verification and organization/profile forms.
- Flutter Riverpod, Dio/generated API client, Freezed, secure file picker integration, and localization packages from Phase 00.
- Pest/PHPUnit, PostGIS integration tests, Playwright, Flutter integration tests, and OWASP ZAP authenticated authorization tests.

## Data model and migrations

### `patient_profiles`

```text
id UUIDv7 PK
user_id UUID nullable unique
national_id_ciphertext bytea
national_id_lookup_hmac bytea
national_id_key_version smallint
full_name_ciphertext bytea
gender enum per approved product vocabulary
date_of_birth date nullable               # derived/confirmed policy documented
height_cm numeric(5,2) nullable
weight_kg numeric(6,2) nullable
marital_status varchar(32) nullable
blood_type varchar(8) nullable
status enum(active,disputed,merged,restricted,archived)
created_by_type / created_by_id
version bigint
created_at / updated_at
```

- Unique `national_id_lookup_hmac` for non-merged profiles.
- Check positive/bounded height and weight using clinically approved limits; unusual values are rejected or explicitly reviewed, never silently clamped.
- `user_id` attaches once through the Phase 01 claim workflow; direct mass assignment is prohibited.
- Blood type remains optional and is self-reported until a future provenance model says otherwise; UI must not present it as lab-verified.

### `patient_demographic_revisions`

Append-only record of changed demographic fields, actor, reason/source, old/new protected references, profile version, time, and request ID. It contains no clinical changes.

### `doctor_profiles`

```text
id UUIDv7 PK
user_id UUID unique
national_id_ciphertext / lookup_hmac / key_version
syndicate_number_ciphertext / lookup_hmac nullable
specialty_id FK specialties
professional_display_name varchar(200)
verification_status enum(draft,pending_review,changes_requested,approved,rejected,suspended)
public_status enum(hidden,listed)
version bigint
approved_at / suspended_at nullable
created_at / updated_at
```

- A doctor is never `listed` or clinically capable unless approved and the account/MFA are active.
- Specialty changes after approval open a new verification decision or use an explicitly approved low-risk workflow; they do not silently alter scope.

### `specialties`

UUIDv7, stable code, Arabic/English label, active flag, sort order, timestamps. Unique stable code; seeded from approved reference data with versioned migrations.

### `pharmacy_organizations` and `pharmacy_branches`

```text
pharmacy_organizations:
  id, legal_name_ciphertext, public_name, registration_lookup_hmac,
  verification_status, status, version, created_at, updated_at

pharmacy_branches:
  id, organization_id FK, public_name, address_ciphertext,
  country_code default EG, geography_point, phone_ciphertext,
  status enum(draft,pending,active,suspended,closed), version, timestamps
```

- GiST index on `geography_point`; B-tree `(organization_id, status)`.
- Coordinates are validated to legal latitude/longitude and, for V1, an approved Egypt service-area check. The database remains country-ready without enabling multi-country behavior.

### `clinic_locations`

```text
id UUIDv7 PK
doctor_id FK doctor_profiles
public_name
address_ciphertext
country_code default EG
geography_point geography(Point,4326)
status enum(draft,pending,active,suspended,closed)
version bigint
created_at / updated_at
```

- Index `(doctor_id, status)` and GiST location.
- Activating a clinic requires an approved doctor plus minimum verified location/contact fields.

### Memberships

`clinic_staff_profiles`, `clinic_staff_memberships`, and `pharmacy_memberships` store user/profile, clinic/organization/branch scope, simple V1 role, status, invited/accepted/revoked timestamps, and inviter/revoker. Unique active membership constraints prevent duplicate grants.

V1 roles are fixed capability bundles—not a complex role builder:

```text
clinic: doctor, secretary
pharmacy: owner, branch_operator
admin: verifier
```

Later pharmacy phases may add narrowly approved fixed roles without introducing a generic permission editor.

### Verification records

```text
verification_cases
  id, applicant_type, applicant_id, case_type, status,
  submitted_at, assigned_reviewer_id, decided_at, version

verification_documents
  id, case_id, requirement_code, object_id, sha256,
  detected_mime, size_bytes, scan_status, status, uploaded_at

verification_decisions
  id, case_id, decision, reason_code, reviewer_id,
  reviewer_assurance_level, notes_ciphertext nullable, created_at
```

- Cases and decisions are append-oriented; re-submission creates a new case/version rather than erasing the old decision.
- Only `AVAILABLE` scanned documents may be reviewed.
- Index queue `(case_type, status, submitted_at)` and applicant history `(applicant_type, applicant_id, created_at)`.

## Core invariants

1. A `User` is not a profile; capabilities arise from an active verified profile/membership and server-owned policy context.
2. One non-merged patient profile exists per National ID blind index; one active user may attach to it.
3. Creating an unlinked patient requires an authorized doctor/secretary context, minimum identity fields, provenance, and non-enumerating exact match resolution.
4. Pending/rejected/suspended doctors and pharmacies receive no clinical, listing, inventory, or business capability.
5. Admin verifier may see only the applicant verification projection and approved documents; admin cannot navigate from it to clinical data.
6. An applicant cannot review/approve its own case. Reviewer identity, MFA assurance, reason, timestamp, IP/device/request ID, and before/after status are audited.
7. Verification documents remain quarantined until validation and scanning pass; rejected/failed objects cannot be downloaded by reviewers.
8. Branch and clinic membership scope is explicit. Organization ownership does not imply clinical access or cross-organization access.
9. Location edits use optimistic version checks and invalidate only derived public/distance caches after commit.
10. Plain legal identifiers/contact/address values never enter events, logs, cache keys, URLs, or analytics.

## Detailed workflows

### New patient onboarding

1. Phase 01 verifies phone and holds a pending user/registration intent.
2. Client submits demographics and National ID through a strict, idempotent command.
3. Server canonicalizes National ID and computes its blind index; it does not expose match state.
4. Lock the candidate index. If no profile exists, create one and attach the verified user in one transaction.
5. If an unlinked profile exists, invoke the Phase 01 identity-proofing/claim state machine; do not create a duplicate.
6. If another active account owns it or facts conflict, create a restricted manual-review case and return a generic pending result.
7. Audit the source of each demographic field and publish minimal profile-created/linked events after commit.

Failure/concurrency behavior:

- Unique constraints resolve simultaneous registration/walk-in creation; loser reloads and enters claim/review, never inserts another record.
- A timeout after commit returns the prior idempotent response on retry.
- Invalid height/weight/gender/status produces field-safe `422`; no partial profile remains.

### Doctor self-registration

1. Verified account creates a draft doctor profile and chooses an active specialty.
2. Client requests purpose-bound, short-lived uploads for required documents.
3. Objects land in a quarantine prefix; callbacks never make them reviewable directly.
4. Worker verifies object ownership, size, magic bytes/MIME, malware result, hash, and metadata before marking available.
5. Submit command checks all current requirements, locks profile/case, records immutable document references, and changes status to `pending_review`.
6. Admin claims the case, sees the minimum identity/professional projection, and approves, rejects, or requests changes with a configured reason.
7. Approval atomically updates profile status, activates approved capabilities/listing eligibility, audits the decision, and emits an outbox event.
8. Rejection/changes requested reveals safe reasons to the applicant without internal fraud signals or reviewer private notes.

### Admin-created doctor

- The same profile, requirement, decision, and audit rules apply. “Admin-created” is not a bypass; the verifier records the evidence source and cannot activate clinical access before approval/MFA.
- Bootstrap/exception cases require a separate high-assurance command and reason, not a boolean request field.

### Pharmacy organization and branch onboarding

1. Verified owner account creates one organization draft and initial branch draft.
2. Server normalizes legal registration identifier and prevents duplicate active/pending organizations without disclosing matches publicly.
3. Required organization/branch documents pass quarantine and scan.
4. Submit creates a verification case covering the immutable submitted snapshot.
5. Approval activates the organization, initial branch, and owner membership together; partial failure rolls back.
6. Additional branches inherit organization identity but require their own minimum-location/status checks and any configured branch verification.

### Unlinked walk-in profile creation contract

Phase 03 calls `CreateUnlinkedPatientProfile` only after an authorized exact-match lookup. The service returns a patient handle, not National ID or history. If a match exists, it returns the existing handle only to the authorized booking flow and logs the lookup; it never exposes clinical content.

### Membership invitation/revocation

- Inviter must hold the exact clinic/organization capability.
- Invite is single-use, expires, and binds the intended account/phone handle without exposing it in URLs.
- Acceptance creates an active membership only after account verification/MFA rules.
- Revocation is authoritative immediately, invalidates capability caches, disconnects scoped realtime sessions, and is audited.

## API contracts

```text
POST   /patients/onboarding
GET    /patients/me/profile
PATCH  /patients/me/demographics

POST   /doctors/onboarding
GET    /doctors/me/profile
POST   /doctors/me/verification-submissions
GET    /doctors/me/verification-status

POST   /pharmacy-organizations/onboarding
POST   /pharmacy-organizations/{id}/branches
GET    /pharmacy-organizations/{id}/verification-status

POST   /verification-uploads
POST   /verification-uploads/{id}/complete
GET    /admin/verification-cases
GET    /admin/verification-cases/{id}
POST   /admin/verification-cases/{id}/decisions

POST   /clinic-locations
PATCH  /clinic-locations/{id}
POST   /clinic-locations/{id}/staff-invitations
DELETE /clinic-locations/{id}/memberships/{membership_id}
```

- Collection endpoints use cursor pagination and projection-specific resources.
- Verification decision and onboarding submission require idempotency keys and optimistic case/profile version.
- Upload requests specify requirement code, expected size, and declared media type; server returns an opaque upload ID and signed target, never an object key.
- Patient exact-match lookup is not a public/general endpoint. It is an internal application port invoked only by approved registration/walk-in commands.

## Events and jobs

```text
PatientProfileCreated.v1 {patient_id, linked_user_id|null, source_type}
PatientAccountLinked.v1 {patient_id, user_id, assurance_level}
DoctorVerificationSubmitted.v1 {doctor_id, case_id}
DoctorVerificationDecided.v1 {doctor_id, case_id, decision, reason_code}
PharmacyVerificationDecided.v1 {organization_id, branch_ids, decision, reason_code}
ClinicLocationChanged.v1 {location_id, doctor_id, version, change_type}
MembershipChanged.v1 {membership_id, scope_type, scope_id, status}
```

Events contain no National ID, phone, address, document object key, reviewer note, or document body.

Jobs:

- Quarantine validation/malware scan with bounded CPU/time/memory and fail-closed availability.
- Abandoned upload/quarantine cleanup after approved retention.
- Verification SLA reminder/escalation without auto-approval.
- Cache/search-projection invalidation after approved profile/location changes.
- Periodic membership/profile consistency audit and orphan-document reconciliation.

## Client work

### Patient Flutter

- Multi-step onboarding preserves only minimum draft state; National ID is never persisted in Drift or analytics.
- Clearly distinguish self-reported fields and verification-pending/manual-review states.
- Demographic edit forms use server versions and explain conflicts; clinical fields are absent.

### Doctor Flutter desktop

- Draft profile, specialty selection, secure file selection/upload progress, scan status, submission, decision, and safe resubmission UX.
- Remove local selected verification files after confirmed upload or user cancellation; do not include them in app backups/crash bundles.
- Pending/rejected/suspended doctor sees no clinical navigation or cached clinical content.

### Pharmacy Flutter desktop

- Organization/branch onboarding and fixed-scope membership management.
- Map pin/address confirmation makes the saved public location explicit.
- No inventory/POS screen is enabled in this phase.

### React admin

- Dedicated verification queue and case projection with MFA/step-up enforcement.
- Document viewer uses a short-lived signed URL, safe content disposition/sandbox, and visible access audit indicator.
- No global patient search, medical-record link, raw object key, or unrestricted download/export.

## Security and privacy threats and controls

- **Profile takeover/duplicate record:** exact blind-index uniqueness, row locking, approved identity assurance, dispute state, no existence response, and linked-account notifications.
- **Fraudulent professional approval:** immutable submitted snapshot, reviewer separation, privileged MFA/step-up, reason codes, document provenance, optional dual approval for configured high-risk exceptions.
- **Malicious verification file:** quarantine, magic-byte validation, malware scan, sandboxed rendering, resource bounds, safe download headers, no inline executable formats, and fail closed.
- **Admin clinical overreach:** separate verification query model/database permissions, field allowlist, no clinical module dependency, BOLA tests, and audited reads/downloads.
- **Cross-tenant membership escalation:** server-derived organization/location scope, active-membership checks on every command/job, cache invalidation, unique constraints, and revoke propagation.
- **Sensitive identifier leakage:** envelope encryption, purpose-bound HMACs, masked UI, no telemetry/provider exposure, and KMS access auditing.
- **Location privacy:** only clinic/branch locations are persisted/public here; staff personal location is never collected. Address and coordinates require owner confirmation and change audit.
- **Insider document browsing:** case assignment/need-to-know policy, short URL TTL, access logs/alerts, no bulk export, and retention/deletion enforcement.

## Test plan

### Unit tests

- Patient/doctor/pharmacy/clinic profile state machines, verification requirement evaluation, decisions, resubmission, membership status, and public/private projections.
- Demographic value validation, source/provenance, location bounds, Egypt service-area rule, and optimistic version conflicts.
- Applicant/reviewer separation, pending capability denial, and field-level admin serialization.

### Integration tests

- PostgreSQL uniqueness/races for National ID, user link, doctor identity, organization registration, active memberships, and concurrent decisions.
- PostGIS insert/query/index plans for locations.
- S3 quarantine, signed upload completion, scanner timeout/failure/magic mismatch, hash recording, signed review URL expiry, and orphan cleanup.
- Transactional approval activates exactly the intended profile/membership and emits one outbox event.

### Contract tests

- OpenAPI/generated clients for onboarding, upload, decision, profile, location, and membership contracts.
- Every patient-registry, verification-document, scanner, applicant, geocoder/map-link, and event adapter passes owned port contracts.
- Current and previous compatible event versions deserialize without sensitive fields.

### End-to-end tests

- New patient profile creation and safe existing-profile claim; simultaneous attempts create one profile/link.
- Doctor self-registration → scanned documents → admin approval → capability activation; rejection/resubmission path.
- Admin-created doctor follows the same evidence/audit gate.
- Pharmacy organization + branch + owner activation; cross-organization access denied.
- Secretary invitation/acceptance/revoke; revoked user loses API/realtime access.

### System tests

- Scanner/S3/SMS/queue outage and recovery without exposing unscanned files or auto-approving cases.
- Large verification backlog remains bounded and observable; public/core endpoints remain responsive.
- Encryption-key rotation and mixed-version deployment preserve protected lookup and profile access.
- Location cache loss/rebuild and rolling migration do not publish a draft/suspended location.

### Security tests

- BOLA/BFLA/mass-assignment across profiles, cases, memberships, locations, and upload IDs.
- File-name/path traversal, MIME spoof/polyglot, malware, oversized/compressed-bomb, parser timeout, and stolen signed URL tests.
- Attempt to make pending/rejected doctor/pharmacy active through direct API/status fields, stale versions, replay, or event forgery.
- Search all telemetry/artifacts for National ID, phone, legal registration, address, document content/object key, and reviewer-note canaries.
- Verify admin endpoints cannot query clinical routes/tables and verification URLs are non-public and short-lived.

## Observability and runbooks

```text
onboarding_started_total{profile_type}
onboarding_completed_total{profile_type,result}
profile_claims_total{result,assurance_level}
verification_cases{type,status}
verification_age_seconds{type,status}
verification_decisions_total{type,decision,reason_code}
verification_scan_total{result,detected_type}
quarantine_objects{age_bucket}
membership_changes_total{scope_type,change}
location_changes_total{type,result}
```

- No applicant/document/profile IDs in metric labels.
- Alert on approval without required evidence, self-review attempt, unusual reviewer volume, scan bypass/failure surge, stale quarantine objects, duplicate-match conflicts, and membership-revoke latency.
- Runbooks cover disputed patient link, fraudulent practitioner/pharmacy application, compromised reviewer, malicious file, wrong branch/clinic location, profile merge restriction, and document/key exposure.

## Migration and rollout

1. Create specialties and profile tables with synthetic seed data; verify protected-field key versions and unique constraints.
2. Deploy profile read/write code before enabling public onboarding; admin verification remains staging-only until authorization and file-pipeline tests pass.
3. Enable patient new-profile creation, then existing-profile claim separately with monitored cohorts and a kill switch.
4. Enable doctor and pharmacy submission before approval; approval capabilities stay disabled until reviewer MFA, audit, and runbooks pass.
5. Publish clinic/branch locations only after explicit active status and cache invalidation verification.
6. Rollback disables new submissions/decisions while preserving submitted cases, protected documents, profiles, and links for forward recovery.

## Measurable exit gate

- Concurrent duplicate patient, doctor, organization, and membership attempts resolve to one authoritative record without data loss.
- 100% of pending/rejected/suspended professional profiles fail clinical/business capability tests.
- Admin verification projections and downloads expose zero seeded clinical or prohibited identity canaries.
- Every verification document remains unavailable until all validation/scan states pass; anonymous/stolen/expired URL access fails.
- Existing-profile claim meets the approved assurance policy and never returns candidate existence to the client.
- Cross-organization, cross-branch, cross-clinic, self-review, mass-assignment, and status-forgery suites pass.
- Profile/location API p95 targets are met on representative data and PostGIS queries use expected indexes.
- Threat-model, data-retention, document-requirement, reviewer-separation, and profile-correction policies have product/security/privacy/legal approval.
- No Critical or unaccepted exploitable High finding remains.

## Deliverables

- `Patients`, `Doctors`, `Pharmacies`, `Clinics`, `Verification`, and verification-focused `Admin` slices.
- Profile, organization, branch, location, membership, case, document, decision, and revision migrations.
- Quarantined verification upload pipeline and admin/Flutter workflows.
- OpenAPI/events, authorization matrix, synthetic fixtures, dashboards, alerts, runbooks, and approval evidence.
