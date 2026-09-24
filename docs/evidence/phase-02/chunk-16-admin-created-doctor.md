# Phase 02 chunk 16 — Admin-created doctor (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** privileged Admin-created Doctor applicant. Product
rule: Admin-created is **not** a verification bypass. The same Doctor
profile, protected-identifier, specialty **validation**, quarantine/malware/
AVAILABLE-only evidence, reviewer-separation, decision, audit/outbox,
pending-capability-denial, and canonical approval path used by
self-registered Doctors applies.

**CH16-SPECIALTY-REFERENCE-001 (independent review):** the engineering-default
12-row file previously added as a production seed is withdrawn. At the time
of this chunk there was no independently approved specialty reference
dataset in repository source-of-truth. That residual is superseded by
`docs/evidence/phase-02/approved-specialty-reference-v1.0.0-phase02.md`
(`v1.0.0-phase02`, 2026-09-24).

Observable Admin browser journey:

    privileged Admin login + MFA
    → `/doctor-applicants/new`
    → create Doctor applicant (closed fields only)
    → required evidence enters the existing secure verification upload path
    → represented submit
    → case appears in the existing verification queue
    → a **different** Admin claims and decides through the canonical review UI
    → approved Doctor receives only already-defined approved capabilities
      (`CreateClinicLocation` / clinic write). `public_status` stays `hidden`.

Pending / rejected / changes_requested Admin-created Doctors receive no
clinic/business capability. The creating Admin cannot claim or decide the
same applicant (`created_by_user_id` self-review prohibition, in addition to
the existing owner-user self-review check).

No parallel approval mechanism. No bootstrap boolean. Ordinary Admin-created
Doctors use the normal verification workflow; a separate high-assurance
bootstrap command was not required and was not added.

**Explicitly deferred / still open:**

- Scheduling / appointments / Phase 03
- Payment methods
- Pharmacy catalog / inventory / POS / Pharmacy Home / Phase 10 roles
- Public doctor/pharmacy discovery
- Recipient invitation inbox/SMS/email
- Secretary desktop
- Profile-claim enablement (`FEATURE_IDENTITY_PROFILE_CLAIM` remains off)
- Pharmacy revoked-member rehire redesign
- Envelope-key rotation expansion
- Broad erasure lifecycle
- Verification SLA escalation job
- SF-001 (`MERGE_ONLY`, `promotion_allowed=false`)
- G-08-04 / ADR 0014
- DEF-SEC-MFA-001 (this chunk did not reproduce a new regression of that finding)
- Staging provisioning / production promotion
- **Approved specialty reference dataset** — supplied and installed in a
  later dedicated change (`v1.0.0-phase02`, 2026-09-24). See
  `docs/evidence/phase-02/approved-specialty-reference-v1.0.0-phase02.md`.
  This chunk's CH16-SPECIALTY-REFERENCE-001 withdrawal of the unapproved
  engineering-default seed remains historically correct.
- Phase 02 PASS

`designs/**` was not modified. No generic IPC. No clinical-data expansion.
No public-directory implementation.

- **Branch:** `cursor/phase-02-chunk-16-admin-created-doctor-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/26
- **Baseline (GitHub `main`):** `3401017e08f2ec60cedb44c5b638d465426ad1a2`
- **CH16-SPECIALTY-REFERENCE-001 remediation:** withdraws the unapproved
  production specialty seed. Do not treat pre-remediation `301d39a` as current.
- **Remediation code commit:** `48361005d006c59e6f5cd4b5e469dfd441afd217`
- **CI-proven HEAD:** `ea1d23b4fa2996d4da3dca681d3caa17c571bb7e`
  (`pull-request` run **35812359501** SUCCESS)
  https://github.com/mahmoudemad68/clinical_system/actions/runs/35812359501
  — 15 success, 3 skipped (Flutter, Flutter Patient profile E2E, AI service
  path filters). Core API **839 passed / 15 skipped**. Admin vitest 49.
  Admin Playwright 2 passed / 18.7s. Contracts and Security scans succeeded.
- **Follow-up on that HEAD:** BinaryColumn `hex2bin` only for valid postgres hex
  (CI Core API 500 on `PharmacySyntheticE2ETest` when raw HMAC started with `\x`;
  not a specialty-catalogue change).
- **Prior product CI (withdrawn specialty seed, not current HEAD):**
  `301d39a658d782152d8a309520878cb81f3e12bb`
  (`pull-request` run **35808624502** SUCCESS)
  https://github.com/mahmoudemad68/clinical_system/actions/runs/35808624502
- **Prior implementation HEAD (failed Core API Tests only):** `b71126b0d633089c10c70a924b81dc232bf492a4`
  (`pull-request` run **35807826004** FAILURE)
  https://github.com/mahmoudemad68/clinical_system/actions/runs/35807826004
  — `DoctorProfileFlowsTest` specialty catalogue assertions saw 2 rows after
  `CommittedDatabaseTestCase` truncated `specialties`. `301d39a` then restored
  a 12-row engineering-default catalogue after truncation. Independent review
  rejected that catalogue (CH16-SPECIALTY-REFERENCE-001). This revision removes
  it; tests/E2E insert synthetic specialty rows only.
- **Recorded:** 2026-09-23
- **Environment:** host PHP 8.3 Core against local PostgreSQL `clinic_test`;
  Admin Playwright against `php -S 127.0.0.1:18080` and Admin host
  `127.0.0.1:14173`. Packaged Electron E2E is the GitHub
  `desktop-packaged-e2e` matrix on the implementation HEAD.

Do **not** mark READY_TO_MERGE from this note. Independent review decides that.
**Phase 02 remains NOT PASS.** This PR remains **Draft**.

## Baseline

Work started from canonical `main` SHA
`3401017e08f2ec60cedb44c5b638d465426ad1a2`. A new Chunk 16 branch was created
from that exact SHA. `designs/**` was not modified.

## Architecture boundaries

```
Admin HTTP (cookie + CSRF + AAL2 + doctors.admin.create)
  -> AdminDoctorApplicantService (transport mapping, closed JSON)
    -> VerificationService::createAdminDoctorApplicant
         -> CreateAdminDoctorApplicant (Doctors, ApprovedCoordinator)
              -> ProvisionDoctorApplicantAccount (Identity)
              -> doctor_profiles insert (draft, hidden, source_type=admin_created)
              -> audit identity.account_registered + doctor.profile_created
         -> openRepresentedDoctorCase (Verification, second transaction)
    -> VerificationUploadService::createRepresentedDoctorUpload
    -> VerificationService::submitRepresentedDoctorCase
    -> existing Admin verification queue/claim/decide (VerificationService)

Admin PHP must not `use Modules\Doctors\` or query `doctor_profiles` /
`specialties` / `verification_*`. Specialty catalogue for the create form is
returned as arrays from Verification. ArchitectureBoundaryTest encodes that.
```

Nested `TransactionRunner` is rejected (no savepoints). Profile create owns
transaction 1; case open is transaction 2.

Capability `doctors.admin.create` is in `Capabilities::PRIVILEGED_OPERATOR`.
`DefaultDenyAuthorizer` checks privileged Admin + AAL2 **before** capability
membership, so Patient/Doctor actors receive the same hide as
`verification.case.review` (`insufficient_assurance` / HTTP 404).

## API / UI contract

Closed Admin create body (`AdminDoctorApplicantRequest`,
`additionalProperties: false`):

| Field | Notes |
| --- | --- |
| `phone` | write-only, Identity canonicalization |
| `national_id` | write-only, existing NationalIdProtector HMAC/ciphertext |
| `professional_display_name` | max 200 |
| `specialty_id` | UUIDv7 of an **active** specialty. Unknown, inactive, or
  absent catalogue fail closed; the server does not invent a row |
| `syndicate_number` | optional write-only, existing syndicate HMAC |
| `password` | write-only initial password |
| `evidence_source` | `in_person_originals` \| `certified_copy` (provenance only) |

Server-owned and rejected on mass assignment: verification status, public
status, capabilities, crypto/key versions, reviewer state, bootstrap flags.

HTTP:

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/admin/doctor-applicants/specialties` | active labels only; this chunk shipped empty until an approved dataset existed. Superseded by `v1.0.0-phase02` (30 rows). |
| POST | `/api/v1/admin/doctor-applicants` | Idempotency-Key required |
| POST | `/api/v1/admin/doctor-applicants/{doctor_id}/verification-uploads` | represented grant |
| POST | `/api/v1/admin/doctor-applicants/{doctor_id}/verification-submissions` | optimistic `expected_case_version` |
| GET | `/api/v1/doctors/specialties` | same catalogue for Doctor actors; this chunk shipped empty after clean migrate. Superseded by `v1.0.0-phase02`. |

Complete/status reuse existing `/api/v1/verification-uploads/{id}` routes.
Claim/decide reuse existing `/api/v1/admin/verification-cases/*`.

Create outcomes: `created`, same-admin replay `already_exists`, other-actor
collision `manual_review_required` (no second profile). Duplicate protected
identity does not disclose the match.

`doctor.profile_created` event schema `source_type` enum:
`self_onboarding | admin_created`. No National ID, syndicate, display name,
ciphertext, HMAC, or key versions in the event.

Admin UI (`apps/admin-web`, existing verification architecture):

- Route `/doctor-applicants/new` behind session capability `doctors.admin.create`
- Does not render clinical data, unrestricted patient lookup, raw object keys,
  crypto fields, or internal reviewer notes
- After represented submit, operator is sent to the existing verification queue
- Empty active catalogue: Admin UI shows an unavailable state and does not
  offer Create applicant
- Existing queue/review screens unchanged except opening a case by professional
  display name so Admin-created E2E does not click the oldest seeded case

## Specialty catalogue residual (CH16-SPECIALTY-REFERENCE-001)

Chunk 02 stated the specialties catalogue remains empty until an approved
medical-specialty reference dataset exists. Independent review rejected the
Chunk 16 attempt to treat a newly invented
`database/data/approved_specialties.v1.php` engineering-default vocabulary as
that dataset.

**Withdrawn from production:**

- `apps/core-api/database/data/approved_specialties.v1.php`
- `apps/core-api/database/migrations/2026_09_22_160100_seed_approved_specialties.php`

A clean production migration **at the time of this chunk** did **not**
insert specialty rows.
`ArchitectureBoundaryTest::production_migrations_do_not_seed_specialty_reference_rows`
encoded that. That residual is superseded by approved catalogue
`v1.0.0-phase02` and
`ArchitectureBoundaryTest::production_specialty_seed_is_only_the_approved_versioned_catalogue`.
The production catalogue is no longer intentionally empty after
`2026_09_24_055405_install_approved_specialty_catalogue_v1_0_0_phase02`.

**Test/E2E only:** `doctorsSeedSpecialty()` and
`e2e:seed-admin-verification` insert synthetic specialty rows for isolated
automated tests. They are not production reference data.

Admin-created Doctor with an unknown/inactive `specialty_id` is 422; no
profile is created. FK and active-specialty checks are unchanged. At the
time of this chunk GET catalogue returned `[]` after clean migrate; that
empty production catalogue is superseded by `v1.0.0-phase02`.

**Files changed by this remediation:**

- deleted `apps/core-api/database/data/approved_specialties.v1.php`
- deleted `apps/core-api/database/migrations/2026_09_22_160100_seed_approved_specialties.php`
- `apps/core-api/tests/CommittedDatabaseTestCase.php` restored to baseline (no catalogue re-seed)
- `apps/core-api/tests/Support/doctorHttpHelpers.php`
- `apps/core-api/tests/Feature/Admin/AdminCreatedDoctorHttpTest.php`
- `apps/core-api/tests/Feature/Doctors/DoctorProfileFlowsTest.php`
- `apps/core-api/tests/Unit/Platform/ArchitectureBoundaryTest.php`
- `apps/core-api/app/Console/SeedAdminVerificationBrowserFixtureCommand.php`
- `apps/admin-web/src/features/doctor-applicants/CreateDoctorPage.tsx`
- `apps/admin-web/src/features/doctor-applicants/CreateDoctorPage.test.tsx`
- `apps/admin-web/src/i18n/en.ts`
- `apps/admin-web/src/i18n/ar.ts`
- `packages/contracts/openapi/openapi.yaml`
- `packages/typescript/api_client/src/generated/schema.d.ts`
- `docs/architecture/module-catalog.md`
- `docs/evidence/phase-02/chunk-16-admin-created-doctor.md`
- `apps/core-api/Modules/Platform/app/Services/Persistence/BinaryColumn.php`
- `apps/core-api/tests/Unit/Platform/BinaryColumnTest.php`

## Security / privacy behavior proven

1. Unauthenticated POST → 401; Patient/Doctor/AAL1 Admin → 404 hide.
2. `doctors.admin.create` is PRIVILEGED_OPERATOR (Admin + AAL2) via existing Access.
3. Duplicate National ID / syndicate does not create a second Doctor profile.
4. Pending/rejected/changes_requested Admin-created Doctors cannot
   `CreateClinicLocation` (404). Approved path returns 201. `public_status`
   remains `hidden`.
5. Approve/reject/changes_requested use existing `VerificationService` decide.
6. Mass assignment of verification/public/capability/crypto/bootstrap fields
   is rejected (`ClosedJsonValidator` / OpenAPI additionalProperties false).
7. Cross-account represented upload/submit (BOLA) → 404. Creator cannot claim.
8. Self-review: owner user id **and** `created_by_user_id` denied in
   `VerificationService` and `VerificationDocumentService`.
9. Same Idempotency-Key replays without duplicate profile/case. Concurrent
   distinct-process race creates one profile (`AdminCreatedDoctorRaceTest`).
10. Represented submit enforces `expected_case_version`.
11. National ID, phone, syndicate, password, HMAC, object keys are absent from
    HTTP bodies, logs (redacting tap canaries), events, URLs, and Admin
    projections. Write-only identifiers are never echoed.
12. Self-registration still sets `source_type=self_onboarding` and
    `created_by_user_id = user_id`.
13. Pharmacy verification HTTP/E2E suites unchanged in this chunk and green in CI.
14. Audit/outbox failure on `doctor.profile_created` rolls back the create.

No exceptional bootstrap command was added.

## Automated tests (local)

Focused after CH16-SPECIALTY-REFERENCE-001 (empty production catalogue;
synthetic test fixtures only):

```text
cd apps/core-api
php artisan test --compact \
  tests/Feature/Admin/AdminCreatedDoctorRaceTest.php \
  tests/Feature/Doctors/DoctorProfileFlowsTest.php \
  tests/Feature/Admin/AdminCreatedDoctorHttpTest.php \
  tests/Unit/Platform/ArchitectureBoundaryTest.php \
  tests/Feature/Verification/DoctorVerificationSyntheticE2ETest.php
# 57 passed, 6016 assertions
```

Relevant Core subset (Admin, Doctors, Verification, Clinics, Identity,
Pharmacies, architecture, Identity/Doctors/Verification unit):

```text
php artisan test --compact tests/Feature/Admin tests/Feature/Doctors \
  tests/Feature/Verification tests/Feature/Clinics tests/Feature/Identity \
  tests/Feature/Pharmacies tests/Unit/Platform/ArchitectureBoundaryTest.php \
  tests/Unit/Identity tests/Unit/Doctors tests/Unit/Verification
# 384 passed, 10816 assertions
```

Admin vitest (CI `npm run admin:test`): 10 files, **49 passed**.

Contracts: `npm run contracts:lint` valid; `npm run contracts:events` 25 schemas.
Deptrac: 0 violations / 0 uncovered.

`AdminCreatedDoctorHttpTest` covers: happy path + pending capability denial,
duplicate protected identity, unauthorized/low-assurance, BOLA + self-review,
mass assignment, idempotent replay, optimistic version, reject +
changes_requested, empty-catalogue 422, inactive specialty 422,
self-registration regression, audit rollback.

## GUI / E2E

Local (after `migrate:fresh` + `e2e:seed-admin-verification` on `clinic_test`,
not in parallel with Pest RefreshDatabase):

```text
npx --prefix tests/e2e playwright test --config tests/e2e/playwright.config.ts \
  --project=admin-verification
# 2 passed (15.4s local)
```

Specs:

- `tests/e2e/admin-created-doctor.spec.ts` — creator MFA → create applicant →
  fixture-identical PDF bytes through secure upload → process with
  `e2e:process-verification-upload` (testing `E2eCleanScanObject`) → submit →
  pending clinic-capability probe 404/`hidden` → sign out → **approver** MFA
  (dedicated TOTP, not the queue-reviewer fixture) → claim/approve → probe 201
  with `verification_status=approved` and `public_status=hidden`.
- `tests/e2e/admin-verification.spec.ts` — existing Admin verification
  regression. Seeded “Dr E2E Review” remains the oldest queue row.

Playwright `testMatch` is `/admin-(verification|created-doctor)\.spec\.ts/` so
CI command ` --project=admin-verification` runs both.

Packaged Electron: no product work in this chunk. Path filter still ran the
matrix because OpenAPI/TypeScript client changed. CI packaged Doctor +
Pharmacy WebdriverIO passed on ubuntu-latest, macos-latest, and windows-latest.
Forge Doctor practice E2E and Forge Pharmacy practice E2E jobs succeeded.

## GitHub CI — run 35812359501 (SUCCESS) on `ea1d23b`

https://github.com/mahmoudemad68/clinical_system/actions/runs/35812359501

Current CH16-SPECIALTY-REFERENCE-001 HEAD (specialty seed withdrawn + BinaryColumn
hex payload validation).

| Job | Conclusion |
| --- | --- |
| Detect changed areas | success |
| Contracts | success |
| Supply-chain policy | success |
| Security scans | success |
| Core API | success (839 passed / 15 skipped Pest) |
| Admin web | success (49 vitest; Playwright 2 passed / 18.7s) |
| Secure-file providers | success |
| Electron desktops | success |
| Forge Doctor practice E2E | success |
| Forge Pharmacy practice E2E | success |
| Packaged Electron E2E (ubuntu-latest) | success |
| Packaged Electron E2E (macos-latest) | success |
| Packaged Electron E2E (windows-latest) | success |
| Runtime image scan (core-api) | success |
| Runtime image scan (ai-service) | success |
| Flutter | skipped (path filter) |
| Flutter Patient profile E2E | skipped (path filter) |
| AI service | skipped (path filter) |

## GitHub CI — run 35811818017 on `4836100` (Core API failure)

https://github.com/mahmoudemad68/clinical_system/actions/runs/35811818017

CH16-SPECIALTY-REFERENCE-001 HEAD. Contracts, Security scans, Admin web
(including Playwright), Doctor/Pharmacy packaged and Forge E2E succeeded.
Core API failed **one** existing pharmacy regression:
`PharmacySyntheticE2ETest` invitation accept returned 500 because
`BinaryColumn::asString` called `hex2bin()` on raw HMAC bytes that happened
to start with `\x`. PHP 8 warns instead of returning false. That is not a
specialty-catalogue defect. Follow-up commit decodes only even-length
`ctype_xdigit` payloads.

## GitHub CI — historical run 35808624502 (SUCCESS) on withdrawn `301d39a`

Pre-remediation product CI. That HEAD still contained the unapproved specialty
seed and must not be treated as the Chunk 16 merge candidate.

https://github.com/mahmoudemad68/clinical_system/actions/runs/35808624502

| Job | Conclusion |
| --- | --- |
| Detect changed areas | success |
| Contracts | success |
| Supply-chain policy | success |
| Security scans | success |
| Core API | success (836 passed / 15 skipped Pest; 4 passed browser CSRF) |
| Admin web | success (48 vitest; Playwright 2 passed / 18.5s) |
| Secure-file providers | success |
| Electron desktops | success |
| Forge Doctor practice E2E | success |
| Forge Pharmacy practice E2E | success |
| Packaged Electron E2E (ubuntu-latest) | success |
| Packaged Electron E2E (macos-latest) | success |
| Packaged Electron E2E (windows-latest) | success |
| Runtime image scan (core-api) | success |
| Runtime image scan (ai-service) | success |
| Flutter | skipped (path filter) |
| Flutter Patient profile E2E | skipped (path filter) |
| AI service | skipped (path filter) |

## Commits since baseline

```text
19193e2 Add Admin-created doctor applicant path and specialty seed.
a2223db Isolate Admin-created doctor HTTP tests from cookie/TOTP collisions.
3c220ef Match Admin-created doctor E2E identifiers as UUIDv7.
a0948d9 Keep clinic cookie helpers intact and fix Create Doctor labels.
e17dbd7 Align Admin-created doctor E2E evidence bytes with the fixture writer.
b71126b Give Admin-created doctor E2E its own approver TOTP.
301d39a Restore approved specialties after committed-database truncation.
aaf36b2 Record Phase 02 Chunk 16 Admin-created doctor evidence.
0047a29 Record Chunk 16 evidence document commit SHA.
4836100 Withdraw unapproved specialty catalogue seed.
ea1d23b Decode bytea only when the postgres hex payload is valid.
```

## Exact changed files versus baseline `3401017e`

```text
apps/admin-web/src/api/client.ts
apps/admin-web/src/app/AdminShell.tsx
apps/admin-web/src/app/App.tsx
apps/admin-web/src/app/prohibitedRoutes.test.ts
apps/admin-web/src/app/routes.ts
apps/admin-web/src/features/doctor-applicants/CreateDoctorPage.test.tsx
apps/admin-web/src/features/doctor-applicants/CreateDoctorPage.tsx
apps/admin-web/src/features/verification/VerificationQueuePage.tsx
apps/admin-web/src/i18n/ar.ts
apps/admin-web/src/i18n/en.ts
apps/admin-web/src/session/keys.ts
apps/admin-web/src/session/sessionContext.ts
apps/admin-web/src/session/SessionProvider.tsx
apps/admin-web/src/test/fixtures.ts
apps/core-api/app/Console/E2eCleanScanObject.php
apps/core-api/app/Console/ProbeDoctorClinicCapabilityCommand.php
apps/core-api/app/Console/ProcessVerificationUploadFixtureCommand.php
apps/core-api/app/Console/SeedAdminVerificationBrowserFixtureCommand.php
apps/core-api/app/Console/WriteVerificationUploadBytesCommand.php
apps/core-api/app/Providers/AppServiceProvider.php
apps/core-api/database/migrations/2026_09_22_160000_add_doctor_profile_provenance.php
apps/core-api/Modules/Access/app/Support/Capabilities.php
apps/core-api/Modules/Admin/app/Http/Controllers/AdminDoctorApplicantController.php
apps/core-api/Modules/Admin/app/Providers/AdminServiceProvider.php
apps/core-api/Modules/Admin/app/Services/AdminDoctorApplicantService.php
apps/core-api/Modules/Admin/app/Support/AdminDoctorApplicantRules.php
apps/core-api/Modules/Doctors/app/Enums/DoctorEvidenceSource.php
apps/core-api/Modules/Doctors/app/Enums/DoctorSourceType.php
apps/core-api/Modules/Doctors/app/Providers/DoctorsServiceProvider.php
apps/core-api/Modules/Doctors/app/Services/CreateAdminDoctorApplicant.php
apps/core-api/Modules/Doctors/app/Services/DoctorApplicantService.php
apps/core-api/Modules/Doctors/app/Services/Persistence/PostgresDoctorProfileStore.php
apps/core-api/Modules/Doctors/app/Support/AdminCreatedDoctorResult.php
apps/core-api/Modules/Doctors/app/Support/DoctorApplicantProjection.php
apps/core-api/Modules/Doctors/app/Support/DoctorProfileRecord.php
apps/core-api/Modules/Doctors/app/Support/DoctorProfileRowFactory.php
apps/core-api/Modules/Identity/app/Providers/IdentityServiceProvider.php
apps/core-api/Modules/Identity/app/Services/ProvisionDoctorApplicantAccount.php
apps/core-api/Modules/Platform/app/Services/Coordinators/ApprovedCoordinators.php
apps/core-api/Modules/Platform/app/Services/Persistence/BinaryColumn.php
apps/core-api/Modules/Verification/app/Services/VerificationDocumentService.php
apps/core-api/Modules/Verification/app/Services/VerificationService.php
apps/core-api/Modules/Verification/app/Services/VerificationUploadService.php
apps/core-api/Modules/Verification/app/Support/AdminDoctorApplicantOutcome.php
apps/core-api/Modules/Verification/app/Support/PrivilegedAdminDoctorCreateGuard.php
apps/core-api/routes/api.php
apps/core-api/tests/Feature/Admin/AdminCreatedDoctorHttpTest.php
apps/core-api/tests/Feature/Admin/AdminCreatedDoctorRaceTest.php
apps/core-api/tests/Feature/Admin/SeedAdminVerificationBrowserFixtureTest.php
apps/core-api/tests/Feature/Doctors/DoctorProfileFlowsTest.php
apps/core-api/tests/Feature/Verification/DoctorVerificationSyntheticE2ETest.php
apps/core-api/tests/Pest.php
apps/core-api/tests/Support/adminCreatedDoctorHttpHelpers.php
apps/core-api/tests/Support/bin/auth-race-worker.php
apps/core-api/tests/Support/doctorHttpHelpers.php
apps/core-api/tests/Unit/Identity/IdentityRulesTest.php
apps/core-api/tests/Unit/Platform/ArchitectureBoundaryTest.php
apps/core-api/tests/Unit/Platform/BinaryColumnTest.php
docs/architecture/module-catalog.md
docs/evidence/phase-02/chunk-16-admin-created-doctor.md
packages/contracts/events/doctor/profile_created.v1.schema.json
packages/contracts/openapi/openapi.yaml
packages/typescript/api_client/src/generated/schema.d.ts
tests/e2e/admin-created-doctor.spec.ts
tests/e2e/playwright.config.ts
```

## Residuals explicitly deferred

Same list as the deferred section above. Independent review is still required.
This chunk does not declare Phase 02 PASS and does not mark READY_TO_MERGE.
