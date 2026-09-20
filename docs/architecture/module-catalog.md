# Module catalog

Required by Phase 00 §1.3. Every Laravel module declares its owner, its public
surface, the tables it owns, the highest data classification it handles, and the
dependencies forbidden to it.

**Ownership rule.** A module owns its tables. No other module reads or writes
them directly. Access happens through a public module service or a published
event (ADR 0001, ADR 0004).

**Status.** `Platform` was implemented in Phase 00. Phase 01 implements `Auth`,
`Identity` (except patient registry), `Access` (self-service capabilities), and
`Audit` append. Phase 02 chunk 01 implements `Patients` demographic profiles.
Phase 02 chunk 02 implements the `Doctors` profile foundation. Phase 02 chunk 03
implements the `Verification` case/document-metadata/decision foundation.
Phase 02 chunk 04 implements doctor verification secure-file ingestion.
Phase 02 chunk 07 implements the canonical `Pharmacies` organization, initial
branch, and founding owner-membership foundation. Phase 10 later extends that
same module with catalog, operating mode, payment methods, and business
capability tenancy. Clinic locations and remaining Phase 02 slices remain
later work.

**Classification levels** are defined in
[`docs/data-classification/classification-policy.md`](../data-classification/classification-policy.md):
`public`, `internal`, `personal`, `sensitive` (sensitive personal / clinical),
`credential`.

## Summary

| Module | Built in | Owner | Peak classification |
| --- | --- | --- | --- |
| `Platform` | 00 | Platform architecture | internal |
| `Auth` | 01 | Backend + security | credential |
| `Identity` | 01–02 | Backend + security | sensitive |
| `Patients` | 02 | Backend + clinical | sensitive |
| `Doctors` | 02 | Backend + clinical | sensitive |
| `Verification` | 02 | Backend + security | sensitive |
| `Clinics` | 02 | Backend | personal |
| `Appointments` | 03 | Backend + clinical | personal |
| `Queue` | 04 | Backend + clinical | personal |
| `Clinical` | 05 | Clinical domain | sensitive |
| `Prescriptions` | 06 | Clinical domain | sensitive |
| `Labs` | 07 | Clinical domain | sensitive |
| `Pharmacies` | 02, 10 | Pharmacy domain | sensitive |
| `MedicationCatalog` | 10 | Pharmacy + clinical | public |
| `Inventory` | 11–12 | Pharmacy domain | internal |
| `Purchasing` | 12 | Pharmacy domain | internal |
| `POS` | 13 | Pharmacy domain + finance | personal |
| `Integrations` | 15 | Pharmacy integrations | internal |
| `Chat` | 09 | Realtime/jobs + clinical | sensitive |
| `Notifications` | 09 | Realtime/jobs | personal |
| `AI` | 16–19 | AI platform + AI safety | sensitive |
| `KnowledgeBase` | 16 | AI platform + AI safety | internal |
| `Admin` | 02, 20 | Backend | internal |
| `Audit` | 01 | Backend + security | sensitive |
| `Analytics` | 20 | Backend | internal |

## Universal prohibitions

These apply to every module and are not repeated per entry:

- No import of another module's persistence types or migrations.
- No `Domain`, `Application`, or `Infrastructure` directory trees.
- No write to a table owned by another module.
- No unbounded work inside an HTTP request (`plan.md` section 174).
- No raw national ID, credential, clinical text, or object key in a log, metric
  label, trace attribute, cache key, URL, or event payload.

---

## `Platform` — shared kernel

**Built in:** 00. **Owner:** platform architecture.

The only module Phase 00 implements. It holds the primitives every other module
depends on, and it is the one module others may import — because it contains no
business rule.

| Provides | Detail |
| --- | --- |
| `IdentityGenerator` | UUIDv7 generation (ADR 0005) |
| `Clock` | UTC instants; `Africa/Cairo` conversion at the edge |
| `TransactionRunner` | Bounded transaction boundary, used by coordinators |
| `Money` | Integer minor units + currency, never floating point |
| `Quantity` | Value + explicit unit identifier |
| `PaginationCursor` | Opaque, signed, size-bounded, scoped to filter/order/actor |
| `IdempotencyStore` | Records described in the phase "Idempotency contract" |
| `OutboxRecorder` | Writes outbox rows inside the caller's transaction |
| `CorrelationContext` | Request ID, correlation ID, causation ID propagation |
| `Redaction` | Centralized redaction applied before any telemetry export |
| `SafeIdentifier` | National ID, barcode, and opaque identifier normalization that never locale-lowercases |
| `ErrorMapper` | Stable machine codes and safe human messages |
| `StoreObject` | Private S3-compatible object storage: opaque locators, short-lived upload grants, server-side observe/stream/delete |
| `ScanObject` | Malware scan of a caller-opened bounded stream (`scanStream`). Production binds `ClamdScanObject`; empty host binds `DisabledScanObject` |

**Tables:** `outbox_events`, `idempotency_keys`.
**Classification:** internal. Outbox payloads carry identifiers and non-sensitive
facts only; the recorder rejects payloads that exceed the declared bound.
**Prohibited:** any business rule, any dependency on a business module. If a
concept only makes sense for one module, it does not belong here.

---

## `Auth` — authentication and devices

**Built in:** 01. **Owner:** backend + security (CODEOWNERS).
**Public services:** `RegisterAccount`, `RequestOtp`, `VerifyOtp`, `AuthenticatePassword`,
`CompleteMfaChallenge`, `RefreshDeviceSession`, `ListOwnSessions`, `RevokeOwnSession`,
`RevokeAllSessions`, `ChangePassword`, `BeginAccountRecovery`, `CompleteAccountRecovery`.
**Events:** `auth.otp_delivery_requested`, `auth.session_revoked`,
`auth.credential_version_changed`.
**Tables:** `user_devices`, `otp_requests`, `mfa_factors`, `mfa_recovery_codes`,
`mfa_challenges`, `auth_sessions`.
**Classification:** credential.
**Prohibited:** deciding authorization. Auth proves identity; Access decides
capabilities. Auth never reads a clinical table. OTP codes never appear in
events or logs.
**Status (Phase 01):** implemented behind Sanctum-style device tokens and admin
cookies. TOTP enrolment HTTP is not exposed; bootstrap inserts a verified factor.

## `Identity` — central user and National ID protection

**Built in:** 01–02. **Owner:** backend + security.
**Public services:** `ResolveActorContext`, `NationalIdProtector`,
`AuditedSensitiveDecryptor`, `RotateIdentityKeysService` (`identity:rotate-keys`),
`PatientIdentityRegistry` (Patients adapter; claim still off), `PatientSubjectPrivacy`
(Identity contract; Patients adapter only), `DoctorSubjectPrivacy`
(Identity contract; Doctors adapter only), `LinkVerifiedPatientAccount`
(not enabled), `DisableIdentity`, `EraseSubject`, `ExportSubjectData`.
Identity never queries Patients or Doctors tables.
**Events:** `identity.account_registered`, `identity.phone_verified`,
`identity.profile_linked`, `identity.status_changed`. Audit also records
`identity.subject_erased` (append-only; not an outbox event type).
**Tables:** `users`, `identity_national_ids`, `identity_profile_links`.
**Classification:** sensitive. National IDs are encrypted for recovery and stored
as keyed HMACs for exact matching; raw values never appear anywhere else
(`docs/phases/README.md` invariant 5).
**Prohibited:** creating a second medical record for the same normalized national
ID (invariant 4); exposing a match result that enables enumeration.
**Status (Phase 01):** identity + HMAC/envelope protection live. Profile claim
flag-gated off (ADR 0011).

## `Access` — deny-by-default capabilities

**Built in:** 01. **Owner:** backend + security.
**Public services:** `Authorize`, `ListEffectiveCapabilities`, `GrantContextualAccess`,
`RevokeContextualAccess`. Consultation grants are Phase 04/05; the write ports
persist rows and stay unused by HTTP in Phase 01. `access:prune-expired` deletes
obsolete grants using an ENGINEERING_DEFAULT TTL.
**Events:** none.
**Tables:** `contextual_access_grants`.
**Classification:** internal.
**Prohibited:** inferring permission from a client-supplied `account_type`;
importing another module's persistence models. Unknown actions deny.

## `Patients` — patient profiles

**Built in:** 02 (chunk 01: Patients vertical slice). **Owner:** backend + clinical.
**Public services:** `CreatePatientProfile`, `GetOwnPatientProfile`,
`UpdateOwnDemographics`, `CreateUnlinkedPatientProfile`, `ResolvePatientHandle`,
`PatientSubjectPrivacy` (Identity erasure/export port).
**Events:** `patient.profile_created`, `patient.account_linked`.
**Tables:** `patient_profiles`, `patient_demographic_revisions`.
**Classification:** sensitive.
**Prohibited:** returning clinical history; public National ID lookup; exposing
ciphertext, HMAC, or key versions on HTTP projections; Platform encoding of
Patients replay types. A patient summary is demographic; clinical content belongs
to `Clinical` and requires an access grant. Onboarding HTTP is compact (`status`,
`patient_id`, `version`); `GET /patients/me/profile` is the canonical projection.
Collisions return generic `manual_review_required`. Unlinked create/resolve
are Access-gated and default-denied. `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.

## `Doctors` — clinician profiles and specialties

**Built in:** 02 (chunk 02: Doctors profile foundation). **Owner:** backend + clinical.
**Public services:** `RegisterDoctor`, `GetDoctorProfile`, `ListSpecialties`,
`DoctorApplicantService` (narrow Verification-facing applicant projection and
status transition; no National ID/HMAC/key-version fields),
`DoctorReviewerService` (narrow reviewer-facing professional display, specialty
labels, and verification/public status; no National ID/HMAC/key-version/phone
fields),
`DoctorSubjectPrivacy` (Identity erasure/export adapter).
**Events:** `doctor.profile_created` (personal identifier-only).
`doctor.verification_submitted` and `doctor.verification_decided` are owned by
`Verification`.
**Tables:** `doctor_profiles`, `specialties`.
**Classification:** sensitive. Peak is protected National ID and optional
syndicate identifiers stored with Identity protection services. `specialties`
catalogue fields remain public/internal. `doctor.profile_created` remains a
personal identifier-only projection. HTTP projections never return National ID,
syndicate number, ciphertext, HMAC, or key versions.
**Prohibited:** owning verification cases, verification documents, reviewer
assignment, or decisions (`Verification` owns that pipeline). Granting clinical
access. Making a doctor `listed` or clinically capable from profile creation.
`verification_status` is not an access grant. Direct access to Identity/Access
tables. Fabricating a medical-specialty seed catalogue without an approved
reference dataset. Onboarding HTTP is compact (`status`, `doctor_id`,
`version`); `GET /doctors/me/profile` is the canonical projection. Collisions
return generic `manual_review_required`. `ListSpecialties` is an in-process
public service in this slice (no HTTP catalogue endpoint).

## `Verification` — cases, documents, decisions, and secure upload intents

**Built in:** 02 (chunk 03 foundation, chunk 04 secure verification files,
chunk 05 Admin verification review backend, chunk 06 React Admin review UI). **Owner:** backend + security.
**Public services:** `VerificationService`, `VerificationDocumentService`,
`VerificationUploadService`, `VerificationUploadProcessor`.
Platform owns generic `StoreObject` / `ScanObject` adapters. Verification owns
doctor-verification upload workflow, requirement codes, case linkage, reviewer
queue, claim, decision, and canonical document-access grants.
`ProcessingTrustedDocumentEvidenceIssuer` is bound as a concrete class and is
reachable only from the trusted processing path. The default
`TrustedDocumentEvidenceIssuer` remains `DisabledTrustedDocumentEvidenceIssuer`.
Admin HTTP controllers call `VerificationService` / `VerificationDocumentService`
rather than writing these tables. Phase 02 chunk 06 adds the React Admin
verification review workspace in `apps/admin-web` (queue, claim, explicit
document access, decision). That UI consumes Admin HTTP only; it does not
own Verification tables or recreate authorization rules.
**Events:** `doctor.verification_submitted`, `doctor.verification_decided`,
`verification.upload_completed` (`upload_id` only).
`pharmacy.verification_decided` is not implemented in this slice.
**Tables:** `verification_cases`, `verification_documents`,
`verification_decisions`, `verification_upload_intents`.
**Classification:** sensitive. Peak is professional-identity and verification
document metadata (hashes, MIME, opaque object identifiers, encrypted reviewer
notes, classified internal storage locators that never leave the module).
Document bytes live in private S3-compatible quarantine until the trusted
processor promotes them. HTTP and event payloads never include National ID,
syndicate identifiers, HMAC, key versions, object storage keys, reviewer notes,
or clinical data.
**Policy catalogues:** document requirements and rejection reasons are
`ENGINEERING_DEFAULT` config (`professional_id`; `approved`,
`evidence_incomplete`, `identity_mismatch`, `documents_illegible`). Upload
limits are also `ENGINEERING_DEFAULT` (20 MiB, PDF/JPEG/PNG, 900s grant expiry,
max 3 active uploads per requirement). Unknown case types, requirement codes,
decisions, and reason codes deny. This is not an approved product/security
catalogue.
**Prohibited:** querying Doctors/Patients/Pharmacies/clinical tables directly
(Doctors is reached only through `DoctorApplicantService` and
`DoctorReviewerService`); exposing object keys
or document bodies on public URLs, events, logs, or DTOs; letting Doctors or
Pharmacies own the verification pipeline; granting clinical capabilities or
auto-listing a doctor on approval; public APIs that mark documents scanned or
`AVAILABLE`. Admin work-queue UI calls `VerificationService` rather than writing
these tables. Admin review HTTP is a thin facade over those services. Submit HTTP is compact (`status`, `doctor_id`, `case_id`,
`case_status`, `case_version`, `profile_version`, `profile_verification_status`);
`GET /doctors/me/verification-status` is the canonical projection.
Production HTTP cannot mint `TrustedDocumentEvidence`.
`ProcessingTrustedDocumentEvidenceIssuer` is production-wirable only through
`VerificationUploadProcessor`. Submitted `verification_documents` are frozen after submission. Reviewer document evidence is assignment-gated.

Earlier catalog drafts listed `doctor_verification_documents` under `Doctors`.
Phase 02 module ownership is authoritative: verification documents belong here.

## `Clinics` — locations and staff

**Built in:** 02. **Owner:** backend.
**Public ports:** `CreateLocation`, `AssignStaff`, `GetLocation`,
`SearchLocationsByGeography`.
**Events:** `clinic.location_created`, `clinic.staff_assigned`.
**Tables:** `clinic_locations`, `clinic_staff`.
**Classification:** personal. Location geometry is a PostGIS `geography(POINT)`
with a GiST index.
**Prohibited:** storing patient location for search convenience
(`plan.md` section 150).

## `Appointments` — schedules and booking

**Built in:** 03. **Owner:** backend + clinical.
**Public ports:** `BookAppointment`, `CancelAppointment`, `RescheduleAppointment`,
`GetAvailability`, `RecordWalkIn`.
**Events:** `appointment.booked`, `appointment.cancelled`,
`appointment.rescheduled`, `appointment.completed`.
**Tables:** `doctor_schedules`, `schedule_exceptions`, `appointment_types`,
`appointments`, `appointment_status_events`.
**Classification:** personal.
**Prohibited:** booking outside `BookAppointmentService` when the workflow
spans modules; double-booking a slot without a database-level constraint.
Strong consistency required (`plan.md` section 173).

## `Queue` — check-in and queue ordering

**Built in:** 04. **Owner:** backend + clinical.
**Public ports:** `CheckIn`, `AdvanceQueue`, `ProjectDelay`,
`GetQueuePosition`.
**Events:** `queue.checked_in`, `queue.advanced`, `queue.delay_projected`.
**Tables:** `queue_entries`.
**Classification:** personal.
**Prohibited:** granting clinical-record access. Check-in establishes queue
eligibility only; the access grant is created by `StartConsultationService`
(invariant 7).

## `Clinical` — encounters and medical records

**Built in:** 05. **Owner:** clinical domain.
**Public ports:** `StartEncounter`, `CompleteEncounter`, `AbortEncounter`,
`RecordClinicalNote`, `GrantRecordAccess`, `RevokeRecordAccess`,
`GetRecordForAuthorizedEncounter`.
**Events:** `clinical.encounter_started`, `clinical.encounter_completed`,
`clinical.access_granted`, `clinical.access_revoked`.
**Tables:** `encounters`, `encounter_history`, `diagnoses`, `clinical_notes`,
`allergies`, `chronic_conditions`, `current_medications`.
**Classification:** sensitive.
**Prohibited:** access without an active encounter grant; admin or secretary
read paths; deleting or overwriting history (invariants 7, 9).

## `Prescriptions`

**Built in:** 06. **Owner:** clinical domain.
**Public ports:** `CreateDraftPrescription`, `FinalizePrescription`,
`AmendPrescription`, `RecordExposure`, `GetPrescription`.
**Events:** `prescription.finalized`, `prescription.amended`,
`prescription.exposed`.
**Tables:** `prescriptions`, `prescription_versions`, `prescription_items`,
`prescription_access_events`, `prescription_amendments`.
**Classification:** sensitive.
**Consumes:** a `MedicationReference` port whose production adapter arrives in
Phase 10.
**Prohibited:** mutating a finalized version. Corrections are linked amendments
(invariant 9).

## `Labs` — lab requests, results, files, reports, referrals

**Built in:** 07. **Owner:** clinical domain.
**Public ports:** `RequestLab`, `RecordLabResult`, `AttachMedicalFile`,
`IssueReport`, `CreateReferral`.
**Events:** `lab.requested`, `lab.result_recorded`, `lab.file_released`,
`report.issued`, `referral.created`.
**Tables:** `lab_catalog`, `lab_requests`, `lab_results`, `medical_files`,
`file_access_logs`, `medical_reports`, `referrals`.
**Classification:** sensitive.
**Prohibited:** serving a file before quarantine release (invariant 14);
anonymous object access.

## `Pharmacies` — organizations and branches

**Built in:** 02 (chunk 07: organization/branch/owner-membership foundation);
10 (operating mode, payment methods, and business capability tenancy).
**Owner:** pharmacy domain.
**Public services:** `RegisterPharmacyOrganization`, `GetOwnPharmacyOrganization`,
`PharmacyApplicantService` (narrow Verification-facing applicant projection;
find-only in chunk 07, no status transition and no National-ID-equivalent
fields), `PharmacyReviewerService` (narrow reviewer-facing public name,
verification/lifecycle status, and initial-branch public identity; no legal
registration, legal name, address, phone, or coordinates),
`PharmacySubjectPrivacy` (Identity erasure/export adapter).
Phase 10 later adds `CreateBranch`, `AssignEmployeeRole`, `GetBranch`, and
branch-authorization/operating-mode services on this same module. There is no
separate `PharmacyOrganizations` module.
**Events:** `pharmacy.organization_created` (personal identifier-only).
`pharmacy.verification_decided`, `pharmacy.branch_created`, and
`pharmacy.role_assigned` are not implemented in this slice.
**Tables:** `pharmacy_organizations`, `pharmacy_branches`,
`pharmacy_memberships`. Phase 10 later adds `branch_payment_methods` and
branch-role capability rows to this module; it does not create a second
organization aggregate.
**Classification:** sensitive. Peak is the protected legal registration
identifier and legal-name ciphertext stored with Identity protection services.
`pharmacy.organization_created` remains a personal identifier-only projection.
HTTP projections never return legal registration, legal name, address, phone,
coordinates, ciphertext, HMAC, or key versions.
**Prohibited:** cross-tenant reads. Every query is organization- or
membership-scoped by a server-owned predicate. Granting inventory, purchasing,
POS, catalog-administration, clinical, or public-activation capability from
draft/pending/unverified onboarding. Owning verification cases, documents, or
decisions (`Verification` owns that pipeline). Direct access to Identity/Access
tables. A generic role designer or the Phase-10 OWNER/PHARMACIST/CASHIER
capability matrix in Phase 02. Onboarding HTTP is compact (`status`,
`organization_id`, `branch_id`, `membership_id`, `version`);
`GET /pharmacy-organizations/me` is the canonical own-organization projection.
Collisions return generic `manual_review_required`. Phase 02 chunk 07
establishes the canonical identity Phase 10 later extends.

## `MedicationCatalog`

**Built in:** 10. **Owner:** pharmacy domain + clinical.
**Public ports:** `SearchMedications`, `GetMedication`, `MedicationReference`
(consumed by `Prescriptions`), `ProposeCatalogChange`, `ApproveCatalogChange`.
**Events:** `catalog.medication_published`, `catalog.change_approved`.
**Tables:** `medications`, `active_ingredients`, `medication_aliases`,
`medication_packaging`.
**Classification:** public.
**Prohibited:** an engineer approving a clinical catalog change. Source,
provenance, versioning, approvers, and controlled-medication rules require
qualified clinical owners (`docs/phases/README.md` open decisions).

## `Inventory` — batches, FEFO, stock ledger

**Built in:** 11–12. **Owner:** pharmacy domain.
**Public ports:** `AllocateFefo`, `AppendMovement`, `GetBalance`,
`RaiseStockAlert`.
**Events:** `stock.movement_appended`, `stock.low_detected`,
`stock.expiry_approaching`.
**Tables:** `stock_batches`, `stock_movements`, `stock_balances`,
`stock_alerts`.
**Classification:** internal.
**Prohibited:** mutating or deleting a movement. The ledger is append-only
(invariant 9). Quantities use an explicit smallest tracked unit; no floating
point (invariant 16).

## `Purchasing`

**Built in:** 12. **Owner:** pharmacy domain.
**Public ports:** `CreatePurchaseOrder`, `ReceiveGoods`, `GetPurchaseOrder`.
**Events:** `purchase.order_created`, `purchase.goods_received`.
**Tables:** `suppliers`, `purchase_orders`, `purchase_order_items`,
`goods_receipts`.
**Classification:** internal.
**Prohibited:** posting to the stock ledger directly. Receipt goes through
`Inventory`'s port inside one transaction, idempotently.

## `POS` — sales, invoices, returns, refunds

**Built in:** 13. **Owner:** pharmacy domain + finance.
**Public ports:** `CompleteSale`, `CancelInvoice`, `RecordReturn`,
`IssueRefund`.
**Events:** `pos.sale_completed`, `pos.invoice_cancelled`, `pos.refund_issued`.
**Tables:** `invoices`, `invoice_items`, `payments`, `returns`, `refunds`.
**Classification:** personal.
**Prohibited:** storing or processing a PAN or CVV. If no approved terminal
provider is selected, V1 records only an external terminal reference and status
(`docs/phases/README.md` open decisions). Sale runs through
`CompleteSaleService`.

## `Integrations` — external pharmacy connectors

**Built in:** 15. **Owner:** pharmacy integrations.
**Public ports:** `RegisterConnector`, `MapProduct`, `RunSync`,
`GetMirrorFreshness`.
**Events:** `integration.sync_started`, `integration.sync_completed`,
`integration.mirror_stale`.
**Tables:** `integration_connectors`, `integration_product_mappings`,
`integration_sync_runs`.
**Classification:** internal; connector credentials are `credential`.
**Prohibited:** writing to a partner system; treating a mirror as native
inventory; SSRF-unsafe outbound calls.

## `Chat` — post-visit encounter-scoped chat

**Built in:** 09. **Owner:** realtime/jobs + clinical.
**Public ports:** `OpenThreadForEncounter`, `PostMessage`, `CloseThread`.
**Events:** `chat.message_posted`, `chat.window_closed`.
**Tables:** `chat_threads`, `chat_messages`.
**Classification:** sensitive.
**Prohibited:** a write outside the encounter-scoped window; a channel whose
name is treated as authorization (invariant 13).

## `Notifications`

**Built in:** 09. **Owner:** realtime/jobs.
**Public ports:** `QueueNotification`, `RecordDeliveryAttempt`,
`GetDeliveryStatus`.
**Events:** `notification.queued`, `notification.sent`,
`notification.failed`.
**Tables:** `notifications`, `notification_deliveries`.
**Classification:** personal.
**Prohibited:** clinical content in a push payload or an SMS body. SMS carries
registration OTP only (`plan.md` section 100).

## `AI` — Laravel-side AI orchestration

**Built in:** 16–19. **Owner:** backend + AI safety.
**Public ports:** `StartAiRun`, `RecordAiResult`, `AuthorizeAiTool`,
`GetConversation`.
**Events:** `ai.run_started`, `ai.run_completed`, `ai.tool_denied`.
**Tables:** `ai_conversations`, `ai_messages`, `ai_usage_logs`.
**Classification:** sensitive.
**Prohibited:** letting model output perform a state transition. Deterministic
code owns permissions, red flags, tool allowlists, budgets, and final writes
(invariant 15). No core write originates from the AI service.

## `KnowledgeBase`

**Built in:** 16. **Owner:** AI platform + AI safety.
**Public ports:** `RegisterDocument`, `PublishVersion`, `StartIngestion`,
`GetIngestionStatus`.
**Events:** `kb.version_published`, `kb.ingestion_completed`.
**Tables:** `knowledge_documents`, `knowledge_versions`, `knowledge_ingestions`.
**Classification:** internal; source files inherit their own classification.
**Prohibited:** treating Qdrant as the source of truth (ADR 0007); cross-tenant
retrieval leakage.

## `Admin`

**Built in:** 02 (chunk 05 verification review HTTP, chunk 06 React Admin
review UI) and 20. **Owner:** backend.
**Public services:** `AdminVerificationReviewService` (HTTP facade for the
verification queue, case detail, claim, decision, and document-access grant).
Controllers map transport input/output only and call `VerificationService` and
`VerificationDocumentService`. Safe professional fields come from
`DoctorReviewerService` through Verification; Admin does not query Doctors or
Verification persistence.
**Events:** none. Admin does not emit `admin.verification_decided`. The
authoritative business event remains Verification-owned
`doctor.verification_decided`.
**Tables:** none in this slice. Queue reads and decisions are owned by
Verification.
**Classification:** internal.
**Prohibited:** any clinical-record read path. "Admin" never implies PHI access;
verification, catalog approval, support, security, and operations capabilities
stay separate internally even if V1 presents one admin persona
(`docs/phases/README.md` open decisions). Admin verification must not import
Clinical, Appointments, Prescriptions, Labs, Patients, or Doctors persistence.
Phase 02 chunk 06 delivers the React Admin verification review workspace
(`apps/admin-web`: session bootstrap, `verification.case.review` gate, queue,
claim, explicit document access, decision). It is presentation only.

## `Audit`

**Built in:** 01. **Owner:** backend + security.
**Public ports:** `AppendAuditEvent`, `QueryAuditTrail`, `VerifyAuditChain`.
**Events:** none; `Audit` is a sink.
**Tables:** `audit_events`.
**Classification:** sensitive (records reference clinical actions).
**Prohibited:** update or delete. Append-only with tamper evidence; a partitioning
decision waits for measured volume (`plan.md` section 112). Per-row hashes are
database-owned. External Ed25519 checkpoints bind `chain_sequence` and
`row_hash` outside PostgreSQL (ADR 0015). A local test disk is not a production
immutable store.

## `Analytics`

**Built in:** 20. **Owner:** backend.
**Public ports:** `GetAggregate`, `RebuildAggregate`.
**Events:** none produced; consumes events from the outbox.
**Tables:** `daily_analytics`.
**Classification:** internal, de-identified.
**Prohibited:** re-identifying a patient; being treated as a source of truth.
Analytics is derived and re-aggregatable (ADR 0007).
