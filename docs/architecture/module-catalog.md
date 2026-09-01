# Module catalog

Required by Phase 00 §1.3. Every Laravel module declares its owner, its public
surface, the tables it owns, the highest data classification it handles, and the
dependencies forbidden to it.

**Ownership rule.** A module owns its tables. No other module reads or writes
them directly. Access happens through a public module service or a published
event (ADR 0001, ADR 0004).

**Status.** `Platform` was implemented in Phase 00. Phase 01 implements `Auth`,
`Identity` (except patient registry), `Access` (self-service capabilities), and
`Audit` append. Every other module remains a declared boundary.

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
| `Doctors` | 02 | Backend + clinical | personal |
| `Clinics` | 02 | Backend | personal |
| `Appointments` | 03 | Backend + clinical | personal |
| `Queue` | 04 | Backend + clinical | personal |
| `Clinical` | 05 | Clinical domain | sensitive |
| `Prescriptions` | 06 | Clinical domain | sensitive |
| `Labs` | 07 | Clinical domain | sensitive |
| `Pharmacies` | 10 | Pharmacy domain | personal |
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
`PatientIdentityRegistry` (unavailable stub until Phase 02), `LinkVerifiedPatientAccount`
(not enabled), `DisableIdentity`, `EraseSubject`, `ExportSubjectData`.
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
`UpdateOwnDemographics`, `CreateUnlinkedPatientProfile`, `ResolvePatientHandle`.
**Events:** `patient.profile_created`, `patient.account_linked`.
**Tables:** `patient_profiles`, `patient_demographic_revisions`.
**Classification:** sensitive.
**Prohibited:** returning clinical history; public National ID lookup; exposing
ciphertext, HMAC, or key versions on HTTP projections. A patient summary is
demographic; clinical content belongs to `Clinical` and requires an access
grant. `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.

## `Doctors` — clinician profiles and verification

**Built in:** 02. **Owner:** backend + clinical.
**Public ports:** `RegisterDoctor`, `SubmitVerificationDocuments`,
`GetDoctorProfile`, `ListSpecialties`.
**Events:** `doctor.verification_submitted`, `doctor.verified`,
`doctor.verification_rejected`.
**Tables:** `doctor_profiles`, `doctor_verification_documents`, `specialties`.
**Classification:** personal; verification documents are sensitive and live
behind the secure-files boundary.
**Prohibited:** granting clinical access. Verification status is not an access
grant.

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

**Built in:** 10. **Owner:** pharmacy domain.
**Public ports:** `CreateOrganization`, `CreateBranch`, `AssignEmployeeRole`,
`GetBranch`.
**Events:** `pharmacy.branch_created`, `pharmacy.role_assigned`.
**Tables:** `pharmacy_organizations`, `pharmacy_branches`,
`branch_payment_methods`.
**Classification:** personal.
**Prohibited:** cross-tenant reads. Every query is branch- or
organization-scoped by a server-owned predicate.

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

**Built in:** 02 and 20. **Owner:** backend.
**Public ports:** `ListVerificationQueue`, `DecideVerification`,
`GetSystemHealthProjection`.
**Events:** `admin.verification_decided`.
**Tables:** admin action records; most reads are projections owned elsewhere.
**Classification:** internal.
**Prohibited:** any clinical-record read path. "Admin" never implies PHI access;
verification, catalog approval, support, security, and operations capabilities
stay separate internally even if V1 presents one admin persona
(`docs/phases/README.md` open decisions).

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
