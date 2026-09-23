# Phase 02 chunk 16 — Admin-created doctor + approved specialty seed (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** privileged Admin-created Doctor applicant plus the
approved specialty catalogue seed required by Phase 02. Product rule:
Admin-created is **not** a verification bypass. The same Doctor profile,
protected-identifier, specialty, quarantine/malware/AVAILABLE-only evidence,
reviewer-separation, decision, audit/outbox, pending-capability-denial, and
canonical approval path used by self-registered Doctors applies.

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
- Phase 02 PASS

`designs/**` was not modified. No generic IPC. No clinical-data expansion.
No public-directory implementation.

- **Branch:** `cursor/phase-02-chunk-16-admin-created-doctor-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/26
- **Baseline (GitHub `main`):** `3401017e08f2ec60cedb44c5b638d465426ad1a2`
- **CI-proven implementation HEAD:** `301d39a658d782152d8a309520878cb81f3e12bb`
  (`pull-request` run **35808624502** SUCCESS)
  https://github.com/mahmoudemad68/clinical_system/actions/runs/35808624502
  — 15 success, 3 skipped (Flutter, Flutter Patient profile E2E, AI service
  path filters). Core API, Admin web Playwright, packaged Electron
  ubuntu/macos/windows, Forge Doctor/Pharmacy practice E2E, Contracts, and
  Security scans succeeded.
- **Prior implementation HEAD (failed Core API Tests only):** `b71126b0d633089c10c70a924b81dc232bf492a4`
  (`pull-request` run **35807826004** FAILURE)
  https://github.com/mahmoudemad68/clinical_system/actions/runs/35807826004
  — `DoctorProfileFlowsTest` specialty catalogue assertions saw 2 rows after
  `CommittedDatabaseTestCase` truncated `specialties`. Fix is `301d39a`
  (restore versioned catalogue after truncation).
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
| `specialty_id` | UUIDv7 of an **active** seeded specialty |
| `syndicate_number` | optional write-only, existing syndicate HMAC |
| `password` | write-only initial password |
| `evidence_source` | `in_person_originals` \| `certified_copy` (provenance only) |

Server-owned and rejected on mass assignment: verification status, public
status, capabilities, crypto/key versions, reviewer state, bootstrap flags.

HTTP:

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/admin/doctor-applicants/specialties` | active catalogue labels only |
| POST | `/api/v1/admin/doctor-applicants` | Idempotency-Key required |
| POST | `/api/v1/admin/doctor-applicants/{doctor_id}/verification-uploads` | represented grant |
| POST | `/api/v1/admin/doctor-applicants/{doctor_id}/verification-submissions` | optimistic `expected_case_version` |
| GET | `/api/v1/doctors/specialties` | same catalogue for Doctor actors; non-empty after clean migrate |

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
- Existing queue/review screens unchanged except opening a case by professional
  display name so Admin-created E2E does not click the oldest seeded case

## Specialty seed source

**Authoritative in-repo source:**
`apps/core-api/database/data/approved_specialties.v1.php`

Why it is authoritative for this repository: it is the engineering-default
bilingual vocabulary already used by Doctors tests and onboarding helpers
(`doctorsSeedSpecialty` / chunk-11 catalogue projections). There is no
government, syndicate, board, or licensing dataset in repository
source-of-truth. Labels are public names only and must not be described as
certified.

Versioned migration `2026_09_22_160100_seed_approved_specialties.php` inserts
idempotently by `code`. Identity is migration-safe (stable UUIDv7 + unique
`code`). All 12 rows are `active=true`.

| id | code | label_ar | label_en | sort_order |
| --- | --- | --- | --- | --- |
| `0199a016-c516-7000-8000-000000000001` | `general_practice` | طب الأسرة | General Practice | 10 |
| `0199a016-c516-7000-8000-000000000002` | `cardiology` | قلب | Cardiology | 20 |
| `0199a016-c516-7000-8000-000000000003` | `dermatology` | جلدية | Dermatology | 30 |
| `0199a016-c516-7000-8000-000000000004` | `endocrinology` | غدد صماء | Endocrinology | 40 |
| `0199a016-c516-7000-8000-000000000005` | `gastroenterology` | جهاز هضمي | Gastroenterology | 50 |
| `0199a016-c516-7000-8000-000000000006` | `neurology` | مخ وأعصاب | Neurology | 60 |
| `0199a016-c516-7000-8000-000000000007` | `obstetrics_gynecology` | نساء وتوليد | Obstetrics and Gynecology | 70 |
| `0199a016-c516-7000-8000-000000000008` | `ophthalmology` | عيون | Ophthalmology | 80 |
| `0199a016-c516-7000-8000-000000000009` | `orthopedics` | عظام | Orthopedics | 90 |
| `0199a016-c516-7000-8000-00000000000a` | `otolaryngology` | أنف وأذن وحنجرة | Otolaryngology | 100 |
| `0199a016-c516-7000-8000-00000000000b` | `pediatrics` | أطفال | Pediatrics | 110 |
| `0199a016-c516-7000-8000-00000000000c` | `pulmonology` | صدر | Pulmonology | 120 |

`CommittedDatabaseTestCase` restores this catalogue after truncation so later
`RefreshDatabase` tests still observe a clean migrated catalog.

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

Focused after truncation fix (race → catalogue order):

```text
cd apps/core-api
php artisan test --compact \
  tests/Feature/Admin/AdminCreatedDoctorRaceTest.php \
  tests/Feature/Doctors/DoctorProfileFlowsTest.php \
  tests/Feature/Admin/AdminCreatedDoctorHttpTest.php \
  tests/Unit/Platform/ArchitectureBoundaryTest.php \
  tests/Feature/Verification/DoctorVerificationSyntheticE2ETest.php
# 55 passed, 5956 assertions
```

Relevant Core subset (Admin, Doctors, Verification, Clinics, Identity,
Pharmacies, architecture, Identity unit):

```text
php artisan test --compact tests/Feature/Admin tests/Feature/Doctors \
  tests/Feature/Verification tests/Feature/Clinics tests/Feature/Identity \
  tests/Feature/Pharmacies tests/Unit/Platform/ArchitectureBoundaryTest.php \
  tests/Unit/Identity
# 367 passed, 10679 assertions
```

Admin vitest (CI `npm run admin:test`): 10 files, **48 passed**.

Contracts: OpenAPI lint + event schemas valid in CI Contracts job (25 event
schemas historically; generated TypeScript client committed and stale-check
passed).

`AdminCreatedDoctorHttpTest` covers: happy path + pending capability denial,
duplicate protected identity, unauthorized/low-assurance, BOLA + self-review,
mass assignment, idempotent replay, optimistic version, reject +
changes_requested, specialty catalogue ≥12 + inactive 422, self-registration
regression, audit rollback.

## GUI / E2E

Local (after `migrate:fresh` + `e2e:seed-admin-verification` on `clinic_test`,
not in parallel with Pest RefreshDatabase):

```text
npx --prefix tests/e2e playwright test --config tests/e2e/playwright.config.ts \
  --project=admin-verification
# 2 passed (14.0s local; 18.5s in CI)
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

## GitHub CI — run 35808624502 (SUCCESS) on `301d39a`

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
```

Plus this evidence document commit.

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
apps/core-api/database/data/approved_specialties.v1.php
apps/core-api/database/migrations/2026_09_22_160000_add_doctor_profile_provenance.php
apps/core-api/database/migrations/2026_09_22_160100_seed_approved_specialties.php
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
apps/core-api/Modules/Verification/app/Services/VerificationDocumentService.php
apps/core-api/Modules/Verification/app/Services/VerificationService.php
apps/core-api/Modules/Verification/app/Services/VerificationUploadService.php
apps/core-api/Modules/Verification/app/Support/AdminDoctorApplicantOutcome.php
apps/core-api/Modules/Verification/app/Support/PrivilegedAdminDoctorCreateGuard.php
apps/core-api/routes/api.php
apps/core-api/tests/CommittedDatabaseTestCase.php
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
