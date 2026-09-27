# Phase 02 entry-point catalog

Generated from current `apps/core-api/routes/api.php`, `routes/web.php`,
`routes/console.php`, `packages/contracts/openapi/openapi.yaml`,
`packages/typescript/desktop_bridge_contracts`, doctor/pharmacy
`src/main/capabilities.ts`, Verification/Platform outbox consumers, and
object-store/scanner adapters at starting main
`d16fcde5b07844f69547a7ed7be52187a44800d8`. **Not** a memory dump of the
planned API list in
`docs/phases/02_onboarding_verification_profiles_and_locations.md`.

Scope: Phase 02 onboarding, verification, profiles, locations, memberships,
and the verification upload/scanner/object-store boundary. Phase 01 auth/me
routes remain in [phase-01-entry-points.md](phase-01-entry-points.md) and are
not re-listed except where a Phase 02 actor reuses them.

CSRF notes:

- **cookie-session:** `ValidateCookieCsrf` on the `identity.session` group.
  Required on unsafe methods when a session cookie, XSRF cookie, or
  authenticated web user is present. Bearer tokens are exempt.
- **signed-query:** reviewer download is **not** on `identity.session`. The
  application HMAC is the capability.
- **n/a:** Electron main-process `net.fetch` uses bearer, not cookies.

AAL: `verification.case.review` and represented-applicant create require
admin + `AssuranceLevel::satisfiesPrivilegedSession()` (`aal2_totp` or
`aal2_recovery_code`). Applicant self-service does not.

---

## Classification vs the canonical planned list

Canonical planned paths (phase document, unversioned) compared with
implemented `/api/v1` routes:

| Planned path | Classification | Implemented route(s) |
| --- | --- | --- |
| `POST /patients/onboarding` | implemented | `POST /api/v1/patients/onboarding` |
| `GET /patients/me/profile` | implemented | `GET /api/v1/patients/me/profile` |
| `PATCH /patients/me/demographics` | implemented | `PATCH /api/v1/patients/me/demographics` |
| `POST /doctors/onboarding` | implemented | `POST /api/v1/doctors/onboarding` |
| `GET /doctors/me/profile` | implemented | `GET /api/v1/doctors/me/profile` |
| `POST /doctors/me/verification-submissions` | implemented | `POST /api/v1/doctors/me/verification-submissions` |
| `GET /doctors/me/verification-status` | implemented | `GET /api/v1/doctors/me/verification-status` |
| `POST /pharmacy-organizations/onboarding` | implemented | `POST /api/v1/pharmacy-organizations/onboarding` |
| `GET /pharmacy-organizations/me` | implemented | `GET /api/v1/pharmacy-organizations/me` |
| `POST /pharmacy-organizations/{id}/branches` | implemented | `POST /api/v1/pharmacy-organizations/{organizationId}/branches` (chunk 14; phase text that called this “later” is stale) |
| `GET /pharmacy-organizations/{id}/verification-status` | implemented | `GET /api/v1/pharmacy-organizations/{organizationId}/verification-status` |
| `POST /verification-uploads` | implemented | `POST /api/v1/verification-uploads` |
| `POST /verification-uploads/{id}/complete` | implemented | `POST /api/v1/verification-uploads/{uploadId}/complete` |
| `GET /admin/verification-cases` | implemented | `GET /api/v1/admin/verification-cases` |
| `GET /admin/verification-cases/{id}` | implemented | `GET /api/v1/admin/verification-cases/{caseId}` |
| `POST /admin/verification-cases/{id}/decisions` | implemented | `POST /api/v1/admin/verification-cases/{caseId}/decisions` |
| `POST /clinic-locations` | implemented | `POST /api/v1/clinic-locations` |
| `PATCH /clinic-locations/{id}` | implemented | `PATCH /api/v1/clinic-locations/{locationId}` |
| `POST /clinic-locations/{id}/staff-invitations` | implemented | `POST /api/v1/clinic-locations/{locationId}/staff-invitations` |
| `DELETE /clinic-locations/{id}/memberships/{membership_id}` | implemented | `DELETE /api/v1/clinic-locations/{locationId}/memberships/{membershipId}` |
| Patient exact-match lookup | intentionally internal | `ResolvePatientHandle` / `CreateUnlinkedPatientProfile` — **no HTTP** |
| Access grant issue/revoke | intentionally internal | Phase 01 services — **no HTTP** |
| Appeal | deferred | no route, no capability (`appeal.allowed=false`) |
| Profile-claim attach ceremony | deferred / flag-off | `LinkVerifiedPatientAccount`; `FEATURE_IDENTITY_PROFILE_CLAIM` default false |
| Dual-approval high-risk exception | absent | not implemented; not invented here |
| Public pharmacy/clinic directory search | absent | no public geo search HTTP |
| Inertia admin verification pages | absent | admin verification is `apps/admin-web` JSON API, not Inertia |

Unexpected relative to the short planned list (still in-scope Phase 02
chunks): specialties list, explicit case-open POSTs, upload status GET,
pharmacy branch CRUD/memberships, clinic list/show/memberships, invitation
accept, admin claim + document-access + represented-doctor create, and the
application-signed reviewer download.

---

## HTTP entry points (44)

Authenticated API group middleware unless noted: `identity.session`,
`auth.actor`, `auth.pending`. Actor is bearer XOR cookie.

| # | Method | Route | Actor / client class | Auth state | Required AAL | CSRF | Rate-limit class | Idempotency | Feature flag | Privileged capability | Primary threat |
| ---: | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/verification-review-files/{caseId}/{documentId}` | admin_web (or stolen URL holder) | **signed query**, not session | n/a (HMAC is the grant) | signed-query | none | no | — | prior `verification.case.review` at grant time | P02-T26 |
| 2 | POST | `/api/v1/patients/onboarding` | patient_mobile | authenticated | any live | cookie-session if cookie | none extra | yes | `FEATURE_AUTH_REGISTRATION` already passed | `patients.onboarding.submit` | P02-T01, P02-T03, P02-T40 |
| 3 | GET | `/api/v1/patients/me/profile` | patient_mobile | authenticated | any live | cookie-session (GET) | none | no | — | `patients.profile.read_own` | P02-T08 |
| 4 | PATCH | `/api/v1/patients/me/demographics` | patient_mobile | authenticated | any live | cookie-session if cookie | none | no | — | `patients.profile.update_own` | P02-T45, P02-T19 |
| 5 | POST | `/api/v1/doctors/onboarding` | doctor_desktop | authenticated | any live | cookie-session if cookie | none | yes | — | `doctors.onboarding.submit` | P02-T01, P02-T05 |
| 6 | GET | `/api/v1/doctors/me/profile` | doctor_desktop | authenticated | any live | cookie-session (GET) | none | no | — | `doctors.profile.read_own` | P02-T08 |
| 7 | GET | `/api/v1/doctors/specialties` | doctor_desktop / admin_web | authenticated | any live | cookie-session (GET) | none | no | — | `doctors.specialties.read` | P02-T07 |
| 8 | POST | `/api/v1/doctors/me/verification-cases` | doctor_desktop | authenticated doctor | any live | cookie-session if cookie | none | yes | — | `verification.case.submit_own` | P02-T17, P02-T05 |
| 9 | POST | `/api/v1/doctors/me/verification-submissions` | doctor_desktop | authenticated doctor | any live | cookie-session if cookie | none | yes | — | `verification.case.submit_own` | P02-T20, P02-T19 |
| 10 | GET | `/api/v1/doctors/me/verification-status` | doctor_desktop | authenticated doctor | any live | cookie-session (GET) | none | no | — | `verification.case.read_own` | P02-T06, P02-T36 |
| 11 | POST | `/api/v1/pharmacy-organizations/onboarding` | pharmacy_desktop | authenticated | any live | cookie-session if cookie | none | yes | — | `pharmacies.onboarding.submit` | P02-T01, P02-T05 |
| 12 | GET | `/api/v1/pharmacy-organizations/me` | pharmacy_desktop | authenticated | any live | cookie-session (GET) | none | no | — | `pharmacies.organization.read_own` | P02-T10 |
| 13 | GET | `/api/v1/pharmacy-organizations/{organizationId}/branches` | pharmacy_desktop | authenticated member | any live | cookie-session (GET) | none | no | — | `pharmacies.branch.read_own` | P02-T10 |
| 14 | POST | `/api/v1/pharmacy-organizations/{organizationId}/branches` | pharmacy_desktop owner | authenticated | any live | cookie-session if cookie | none | yes | — | `pharmacies.branch.write` | P02-T10, P02-T38 |
| 15 | GET | `/api/v1/pharmacy-organizations/{organizationId}/branches/{branchId}` | pharmacy_desktop | authenticated member | any live | cookie-session (GET) | none | no | — | `pharmacies.branch.read_own` | P02-T10 |
| 16 | PATCH | `/api/v1/pharmacy-organizations/{organizationId}/branches/{branchId}` | pharmacy_desktop owner | authenticated | any live | cookie-session if cookie | none | no | — | `pharmacies.branch.write` | P02-T38, P02-T19 |
| 17 | POST | `/api/v1/pharmacy-organizations/{organizationId}/branches/{branchId}/staff-invitations` | pharmacy_desktop owner | authenticated | any live | cookie-session if cookie | none | yes | — | `pharmacies.staff.invite` | P02-T11 |
| 18 | GET | `/api/v1/pharmacy-organizations/{organizationId}/branches/{branchId}/memberships` | pharmacy_desktop | authenticated | any live | cookie-session (GET) | none | no | — | `pharmacies.membership.read_own` | P02-T10 |
| 19 | DELETE | `/api/v1/pharmacy-organizations/{organizationId}/branches/{branchId}/memberships/{membershipId}` | pharmacy_desktop owner | authenticated | any live | cookie-session if cookie | none | no | — | `pharmacies.membership.revoke` | P02-T11, P02-T12 |
| 20 | POST | `/api/v1/pharmacy-staff-invitations/{invitationId}/accept` | pharmacy_desktop invitee | authenticated | any live | cookie-session if cookie | none | yes | — | `pharmacies.staff.accept` | P02-T11 |
| 21 | POST | `/api/v1/pharmacy-organizations/me/verification-cases` | pharmacy_desktop owner | authenticated | any live | cookie-session if cookie | none | yes | — | `verification.case.submit_own` | P02-T17 |
| 22 | POST | `/api/v1/pharmacy-organizations/me/verification-submissions` | pharmacy_desktop owner | authenticated | any live | cookie-session if cookie | none | yes | — | `verification.case.submit_own` | P02-T20 |
| 23 | GET | `/api/v1/pharmacy-organizations/me/verification-status` | pharmacy_desktop | authenticated | any live | cookie-session (GET) | none | no | — | `verification.case.read_own` | P02-T06 |
| 24 | GET | `/api/v1/pharmacy-organizations/{organizationId}/verification-status` | pharmacy_desktop | authenticated member of that org | any live | cookie-session (GET) | none | no | — | `verification.case.read_own` | P02-T10 |
| 25 | GET | `/api/v1/clinic-locations` | doctor_desktop | authenticated doctor | any live | cookie-session (GET) | none | no | — | `clinics.location.read_own` | P02-T09 |
| 26 | POST | `/api/v1/clinic-locations` | doctor_desktop | authenticated approved doctor | any live | cookie-session if cookie | none | yes | — | `clinics.location.write` | P02-T38 |
| 27 | GET | `/api/v1/clinic-locations/{locationId}` | doctor_desktop | authenticated owning doctor | any live | cookie-session (GET) | none | no | — | `clinics.location.read_own` | P02-T09 |
| 28 | PATCH | `/api/v1/clinic-locations/{locationId}` | doctor_desktop | authenticated owning doctor | any live | cookie-session if cookie | none | no | — | `clinics.location.write` | P02-T38, P02-T19 |
| 29 | POST | `/api/v1/clinic-locations/{locationId}/staff-invitations` | doctor_desktop | authenticated owning doctor | any live | cookie-session if cookie | none | yes | — | `clinics.staff.invite` | P02-T11 |
| 30 | GET | `/api/v1/clinic-locations/{locationId}/memberships` | doctor_desktop | authenticated owning doctor | any live | cookie-session (GET) | none | no | — | `clinics.membership.read_own` | P02-T09 |
| 31 | DELETE | `/api/v1/clinic-locations/{locationId}/memberships/{membershipId}` | doctor_desktop | authenticated owning doctor | any live | cookie-session if cookie | none | no | — | `clinics.membership.revoke` | P02-T11, P02-T12 |
| 32 | POST | `/api/v1/clinic-staff-invitations/{invitationId}/accept` | doctor_desktop invitee | authenticated | any live | cookie-session if cookie | none | yes | — | `clinics.staff.accept` | P02-T11 |
| 33 | POST | `/api/v1/verification-uploads` | doctor_desktop / pharmacy_desktop / admin_web (represented) | authenticated applicant or representing admin | any live | cookie-session if cookie | none | yes | — | `verification.case.submit_own` or `doctors.admin.create` | P02-T21, P02-T27 |
| 34 | POST | `/api/v1/verification-uploads/{uploadId}/complete` | same | authenticated owner of intent | any live | cookie-session if cookie | none | yes | — | same | P02-T28, P02-T41 |
| 35 | GET | `/api/v1/verification-uploads/{uploadId}` | same | authenticated owner of intent | any live | cookie-session (GET) | none | no | — | same | P02-T06 |
| 36 | GET | `/api/v1/admin/doctor-applicants/specialties` | admin_web | authenticated admin | **privileged AAL2** | cookie-session (GET) | none | no | — | `doctors.admin.create` | P02-T15 |
| 37 | POST | `/api/v1/admin/doctor-applicants` | admin_web | authenticated admin | **privileged AAL2** | cookie-session if cookie | none | yes | — | `doctors.admin.create` | P02-T14, P02-T05 |
| 38 | POST | `/api/v1/admin/doctor-applicants/{doctorId}/verification-uploads` | admin_web | representing admin | **privileged AAL2** | cookie-session if cookie | none | yes | — | `doctors.admin.create` | P02-T14 |
| 39 | POST | `/api/v1/admin/doctor-applicants/{doctorId}/verification-submissions` | admin_web | representing admin | **privileged AAL2** | cookie-session if cookie | none | yes | — | `doctors.admin.create` | P02-T14, P02-T20 |
| 40 | GET | `/api/v1/admin/verification-cases` | admin_web | authenticated admin | **privileged AAL2** | cookie-session (GET) | none | no | — | `verification.case.review` | P02-T16, P02-T50 |
| 41 | GET | `/api/v1/admin/verification-cases/{caseId}` | admin_web | authenticated admin | **privileged AAL2** | cookie-session (GET) | none | no | — | `verification.case.review` | P02-T16, P02-T36 |
| 42 | POST | `/api/v1/admin/verification-cases/{caseId}/claim` | admin_web | authenticated admin | **privileged AAL2** | cookie-session if cookie | none | no | — | `verification.case.review` | P02-T13, P02-T14 |
| 43 | POST | `/api/v1/admin/verification-cases/{caseId}/decisions` | admin_web assigned reviewer | authenticated admin | **privileged AAL2** | cookie-session if cookie | none | yes | — | `verification.case.review` | P02-T13, P02-T18, P02-T43 |
| 44 | POST | `/api/v1/admin/verification-cases/{caseId}/documents/{documentId}/access` | admin_web assigned reviewer | authenticated admin | **privileged AAL2** | cookie-session if cookie | none | no | — | `verification.case.review` | P02-T26, P02-T27 |

OpenAPI documents the `/api/v1` operations above except framework-only paths.
Admin verification UI is `apps/admin-web` (`/verification`, `/verification/:caseId`),
not Inertia. `Modules/Admin/routes/web.php` is empty.

---

## Electron IPC entry points (33 domain + inherited auth)

Renderer has no Node, no bearer, no generic `ipcRenderer.invoke`. Main
validates `event.senderFrame` origin via `isTrustedFrameOrigin` before
handling. Channels are closed allowlists in
`packages/typescript/desktop_bridge_contracts`.

### Doctor domain (`DOCTOR_CHANNELS`, 17)

| Channel | Kind | What it can do | Primary threat |
| --- | --- | --- | --- |
| `clinic:doctor.profile.getOwn` | IPC → HTTP | Own doctor projection | P02-T08 |
| `clinic:doctor.specialties.list` | IPC → HTTP | Approved specialty list | P02-T07 |
| `clinic:doctor.profile.onboard` | IPC → HTTP | Create doctor profile | P02-T05 |
| `clinic:doctor.verification.openCase` | IPC → HTTP | Open own case | P02-T17 |
| `clinic:doctor.verification.status` | IPC → HTTP | Own status (no reviewer notes) | P02-T36 |
| `clinic:doctor.verification.submit` | IPC → HTTP | Submit when required docs AVAILABLE | P02-T20 |
| `clinic:doctor.evidence.select` | IPC + dialog | Opaque handle; no path to renderer | P02-T30, P02-T32 |
| `clinic:doctor.evidence.clear` | IPC | Drop handle | P02-T32 |
| `clinic:doctor.evidence.upload` | IPC → signed PUT | Main streams bytes; renderer never sees target | P02-T27, P02-T33 |
| `clinic:doctor.upload.status` | IPC → HTTP | Poll intent | P02-T06 |
| `clinic:doctor.locations.list` | IPC → HTTP | Own locations | P02-T09 |
| `clinic:doctor.locations.create` | IPC → HTTP | Create location | P02-T38 |
| `clinic:doctor.locations.get` | IPC → HTTP | Own location | P02-T09 |
| `clinic:doctor.locations.update` | IPC → HTTP | Patch location | P02-T38 |
| `clinic:doctor.locations.inviteStaff` | IPC → HTTP | Invite secretary | P02-T11 |
| `clinic:doctor.locations.memberships` | IPC → HTTP | List memberships | P02-T09 |
| `clinic:doctor.locations.revokeMembership` | IPC → HTTP | Revoke | P02-T12 |

Handlers: `apps/doctor-desktop/src/main/capabilities.ts`.
Handle store: `apps/doctor-desktop/src/main/evidence-handles.ts`.

### Pharmacy domain (`PHARMACY_CHANNELS`, 16)

| Channel | Kind | What it can do | Primary threat |
| --- | --- | --- | --- |
| `clinic:pharmacy.organization.getOwn` | IPC → HTTP | Own org | P02-T10 |
| `clinic:pharmacy.organization.onboard` | IPC → HTTP | Create org | P02-T05 |
| `clinic:pharmacy.verification.openCase` | IPC → HTTP | Open own case | P02-T17 |
| `clinic:pharmacy.verification.status` | IPC → HTTP | Own status | P02-T36 |
| `clinic:pharmacy.verification.submit` | IPC → HTTP | Submit | P02-T20 |
| `clinic:pharmacy.evidence.select` | IPC + dialog | Opaque handle | P02-T30 |
| `clinic:pharmacy.evidence.clear` | IPC | Drop handle | P02-T32 |
| `clinic:pharmacy.evidence.upload` | IPC → signed PUT | Main streams bytes | P02-T27 |
| `clinic:pharmacy.upload.status` | IPC → HTTP | Poll intent | P02-T06 |
| `clinic:pharmacy.branches.list` | IPC → HTTP | Own branches | P02-T10 |
| `clinic:pharmacy.branch.create` | IPC → HTTP | Create branch | P02-T38 |
| `clinic:pharmacy.branch.get` | IPC → HTTP | Own branch | P02-T10 |
| `clinic:pharmacy.branch.update` | IPC → HTTP | Patch branch | P02-T38 |
| `clinic:pharmacy.branch.inviteOperator` | IPC → HTTP | Invite operator | P02-T11 |
| `clinic:pharmacy.branch.memberships` | IPC → HTTP | List | P02-T10 |
| `clinic:pharmacy.branch.revokeMembership` | IPC → HTTP | Revoke | P02-T12 |

Inherited Phase 01 auth/platform channels (`clinic:auth.*`,
`clinic:platform.*`, locale) still apply. They are not re-catalogued.

---

## Non-HTTP security entry points (18)

These are not HTTP routes. Do not invent REST paths for them.

| # | Entry | Kind | Actor | What it can do | Primary threat |
| ---: | --- | --- | --- | --- | --- |
| 1 | `CreateUnlinkedPatientProfile` | module service | future Phase 03 doctor/secretary with `patients.unlinked.create` | Walk-in profile; **no HTTP** | P02-T52 |
| 2 | `ResolvePatientHandle` | module service | `patients.unlinked.resolve` | Exact HMAC resolve; **no HTTP**; no existence API | P02-T03 |
| 3 | `LinkVerifiedPatientAccount` | module service | claim ceremony | Throws `FeatureUnavailable` while `FEATURE_IDENTITY_PROFILE_CLAIM` is off | P02-T46 |
| 4 | `VerificationUploadCompletedConsumer` | outbox consumer | `clinic_worker` | `verification.upload_completed` → `VerificationUploadProcessor::process` | P02-T44, P02-T29 |
| 5 | `VerificationUploadProcessor` | module service | worker only | Observe bytes, inspect, scan, promote or reject | P02-T24, P02-T28 |
| 6 | `verification:reconcile-uploads {--limit=50}` | artisan / hourly scheduler | system | Re-drive stuck scanning intents | P02-T44 |
| 7 | `e2e:process-verification-upload` | artisan | local/testing only | Fixture processor | P02-T29 |
| 8 | `e2e:write-verification-upload` | artisan | local/testing only | Write fixture bytes | P02-T21 |
| 9 | `e2e:seed-admin-verification` | artisan | local/testing only | Browser fixture | P02-T16 |
| 10 | `StoreObject` / `S3StoreObject` | provider | API + worker | PUT grant only for `/q/` keys; copy to `/c/`; `anonymousGet`/`anonymousList` throw | P02-T27, P02-T51 |
| 11 | `ScanObject` / `ClamdScanObject` / `DisabledScanObject` | provider | worker | Exact `stream: OK` is clean; miss → `unavailable` → not promote | P02-T24, P02-T51 |
| 12 | `ReviewerDocumentUrlSigner` | module service | assigned reviewer via HTTP grant | HMAC purpose `verification_review_download`; TTL ≤ 300s | P02-T26 |
| 13 | `ProcessingTrustedDocumentEvidenceIssuer` | worker binder | worker | Only worker context may mint AVAILABLE | P02-T29 |
| 14 | `DisabledTrustedDocumentEvidenceIssuer` | HTTP/production binder | API process | `canIssue() === false` | P02-T29 |
| 15 | `DoctorEvidenceHandleStore` / `PharmacyEvidenceHandleStore` | Electron main | renderer via typed IPC | Opaque handle, TTL, inode pin, logout clear | P02-T32, P02-T33 |
| 16 | `outbox:work` | worker (Phase 01 catalog) | `clinic_worker` | Now also dispatches verification upload consumer | P02-T44 |
| 17 | `AdminVerificationReviewService` | module service | Admin HTTP | Queue/show/claim/decide/documentAccess facade | P02-T16 |
| 18 | `platform:prune` | artisan (Phase 01) | system | Does **not** purge quarantine objects | P02-T28 residual |

---

## Jobs / storage / scanner / events

| Kind | Actual name | Notes |
| --- | --- | --- |
| Laravel `ShouldQueue` job in Verification | **none** | Scan is outbox-driven, not a Horizon job class |
| Outbox event | `verification.upload_completed` | Triggers processor |
| Outbox event | `doctor.verification_submitted` | After doctor submit |
| Outbox event | `doctor.verification_decided` / `pharmacy.verification_decided` | No notes, NID, object keys |
| Outbox event | `patient.profile_created` / `patient.account_linked` | No NID |
| Outbox event | `doctor.profile_created` | Admin-created and self |
| Outbox event | `pharmacy.organization_created` / `pharmacy.branch_changed` / `pharmacy.membership_changed` | |
| Outbox event | `clinic.location_changed` / `clinic.membership_changed` | |
| Planned `pharmacy.verification_submitted` | **not emitted** | Tests assert count 0 |
| Object storage callback | **none** | No S3 event notification webhook |
| Scanner callback | **none** | Core pulls clamd INSTREAM; no inbound scanner HTTP |

Flutter patient onboarding uses generated Dart against the three patient
HTTP routes; it has no verification upload IPC.
