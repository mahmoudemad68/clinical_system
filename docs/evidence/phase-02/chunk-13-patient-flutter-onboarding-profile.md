# Phase 02 chunk 13 — Patient Flutter onboarding and profile (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** Patient Flutter Phase-02 own-profile onboarding and
demographics on the already-merged Chunk 01 Patients HTTP contracts. Product
flow for an authenticated Patient account:

authenticated Patient → `GET /api/v1/me` → `GET /api/v1/patients/me/profile`
→ create a profile when absent (`POST /api/v1/patients/onboarding`)
→ generic non-enumerating manual-review holding when the server returns
`manual_review_required` → view the authoritative own projection
→ edit allowlisted demographics with compare-and-set `version`
(`PATCH /api/v1/patients/me/demographics`) → visible `VERSION_CONFLICT` on
stale save, then explicit refresh and re-edit.

**Explicitly deferred / out of scope for this chunk:** Patient Home (Phase 08),
appointments, booking, doctor/clinic search, maps, queue, prescriptions,
medications, medical records, labs, reports, chat, reviews, AI/triage,
Admin patient search, generic profile-claim UI, a new National-ID algorithm,
Pharmacy membership management, DEF-SEC-MFA-001, SF-001, staging
provisioning, and Phase 03. `designs/**` was not modified.

Chunk 13 does **not** complete Phase 02.
**Phase 02 remains NOT PASS.**
Patient Home, appointments, doctor search, booking, clinical records,
prescriptions, labs, chat, and AI remain outside this chunk.

Do **not** mark READY_TO_MERGE from this note. Independent review decides that.

- **Branch:** `cursor/phase-02-chunk-13-patient-flutter-onboarding-369e`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/22
- **Baseline (GitHub `main`):** `bbbcd7f499529cfa6e01420827b36418d7631a4a`
  (merge of PR #21, Chunk 12 Doctor clinic locations/staff UI). Chunks 11 and
  12 are CLOSED.
- **Implementation HEAD (live Core E2E green):** `87c6a59b8cdedeab7b747aef57f17b17ba0f36d9`
- **Evidence / final HEAD:** the tip of this branch (commit that contains this
  file). GitHub CI is the `pull-request` workflow on that SHA.
- **CI-green HEAD:** `64163726ec4b12b6e88495f8261d3d54f83c6483`
- **GitHub CI (CI-green HEAD):** `pull-request` run **35713695100**
  overall **SUCCESS** on that exact SHA
  (https://github.com/mahmoudemad68/clinical_system/actions/runs/35713695100)
  — 9 success, 5 skipped by path filter.

Jobs on that run: Detect changed areas, Supply-chain policy, Contracts,
Security scans, Core API, Secure-file providers, Flutter, Runtime image
scan (core-api), Runtime image scan (ai-service) all **SUCCESS**. Skipped:
Admin web, Electron desktops, Forge Doctor practice E2E, Packaged Electron
E2E, AI service.

Flutter job is `melos run analyze` + `melos run test` (live Core journey
skipped without `CLINIC_E2E_*`). Core API Tests step succeeded (Patients
suite including `SeedPatientFlutterFixtureTest`).

Prior superseded runs (not this HEAD):
- `a806e0b` [35707183388](https://github.com/mahmoudemad68/clinical_system/actions/runs/35707183388) **failure** (Core API, Flutter, Security scans)
- `23e7c15` [35708352576](https://github.com/mahmoudemad68/clinical_system/actions/runs/35708352576) **failure** (Core API, Security scans; Flutter **SUCCESS**)
- `b3d5cdf` [35713063388](https://github.com/mahmoudemad68/clinical_system/actions/runs/35713063388) **failure** (Security scans only; Core API **SUCCESS**)

Security scans: Gitleaks `clinic-egyptian-national-id` flagged unpublished
synthetic canary `29201011234567`. Tests now use already-allowlisted
`29901011234567`; the old exact literal remains a global allowlist entry
so PR history does not fail on a fixture. Core API 403 was Laravel
HTTP-test session reuse; `patientFlutterDeviceLogin` isolates logins.
- **Recorded:** 2026-09-22
- **Environment:** Flutter 3.47.1 / Dart 3.13.1, PHP 8.3, PostgreSQL 16 +
  PostGIS, Redis. Patient-app widget tests on the host. Live Core
  `php artisan serve` on `http://127.0.0.1:8080` against database
  `clinic_e2e` (migrator `clinic_migrator`). Backend Pest against
  `clinic_test`.

## Baseline

`origin/main` at start of this chunk was
`bbbcd7f499529cfa6e01420827b36418d7631a4a`. Work started on a new branch from
that commit. No prior Phase-02 feature branch was continued. `designs/**` was
not modified. `FEATURE_IDENTITY_PROFILE_CLAIM` remains off in defaults,
`phpunit.xml`, and environment files.

## What was implemented

The Patient app previously had Phase 00/01 authentication and platform health
only. This chunk adds server-authoritative profile routing and the Phase 02
profile surfaces. Existing `AuthApi`, `TokenStore`, `SecureStorageVault`,
`AuthInterceptor`, and `ClinicHttpClient` are unchanged as the credential
path. Access/refresh tokens stay in the vault. Profile APIs use the shared
authenticated Dio client. There is no GET-by-patient-id adapter method and no
public patient lookup.

Account gating uses server-owned `account_type` from `GET /api/v1/me`.
Non-patient accounts hit a wrong-account holding screen; hidden navigation is
not treated as authorization. Backend remains authoritative.

## Patient routes / screens

Named routes never take a patient id or National ID. `PatientShell` switches
on `PatientRouteState` from Riverpod:

| Route / state | Screen |
| --- | --- |
| `PatientRouteUnauthenticated` | existing auth panel |
| `PatientRouteOnboarding` (`/onboarding/profile`) | Screen 1 — Patient profile onboarding (4-step Material 3) |
| `PatientRouteManualReview` (`/onboarding/review`) | Screen 2 — identity review pending (generic) |
| `PatientRouteProfile` (`/profile`) | Screen 4 — Profile and demographics |
| `EditDemographicsScreen` (pushed) | allowlisted PATCH editor + VERSION_CONFLICT |
| `PatientRouteWrongAccount` | Patient-only gate |

Screen 3 Patient Home and Screen 6 Appointments are not implemented.

Onboarding steps: Identity (National ID + full name) → Demographics (gender,
date of birth, marital status) → Self-reported measurements (height, weight,
blood type) → Review (safe fields only; National ID never echoed).

## Patient API adapter / contracts

`packages/flutter/api_client/lib/src/patient_api.dart` (`PatientApi`):

| Method | Contract |
| --- | --- |
| `onboard(...)` | `POST /api/v1/patients/onboarding` with `Idempotency-Key` |
| `getOwnProfile()` | `GET /api/v1/patients/me/profile` |
| `updateDemographics(...)` | `PATCH /api/v1/patients/me/demographics` including `version` |

Paths and methods come from generated OpenAPI constants
(`OpenApiPaths.*` / `OpenApiMethods.*`). Widgets do not construct raw URLs.
The adapter source test asserts there is no `getById`, no `/patients/{id}`
interpolation, and `getOwnProfile` never accepts a caller-supplied id.

Onboarding request fields: `national_id`, `full_name`, `gender` (required),
plus optional `date_of_birth`, `height_cm`, `weight_kg`, `marital_status`,
`blood_type`. Compact `profile_ready` / `manual_review_required` results are
not used to render the Profile screen; `profile_ready` always refetches
`GET /patients/me/profile`.

## Authentication → profile routing

After login/OTP, `PatientSession.onAuthenticated()` / `restore()`:

1. Require a vault access token (else unauthenticated).
2. `GET /api/v1/me`. Non-patient `account_type` → wrong-account.
3. `GET /api/v1/patients/me/profile`.
   - 200 → Profile (read-only UI when status is not `active`).
   - 404 → onboarding, unless an in-session `_manualReviewHold` from
     `manual_review_required`.
   - 401 → unauthenticated; tokens cleared.

The app does not infer profile existence from local persistence.
`FEATURE_IDENTITY_PROFILE_CLAIM` stays off; no claim/takeover UI.

## Onboarding result

Allowed outcomes:

- `profile_ready` → `applyOnboarding` refetches own profile and shows
  Screen 4 from the GET projection (not the submitted form).
- `manual_review_required` → generic holding screen. Extra server fields
  such as speculative `reason` are ignored by `mapOnboardingResult`.

Live Core (2026-09-22, `clinic_e2e` + `CLINIC_E2E_*`):

- Fresh seeded Patient with no profile logged in → onboarding.
- Widget stepper filled (National ID absent on review) →
  `PatientApi.onboard` / controller submit inside `tester.runAsync` →
  `profile_ready` → authoritative GET → `PatientRouteProfile` version 1,
  full name `E2E Patient`.

## National-ID non-persistence proof

National ID is write-only. It is not a `PatientProfile` field. Controllers
clear it after success, `manual_review_required`, logout, route dispose, and
terminal 422/401/404. Registration `PatientAuthPanel` clears the National ID
controller after the Phase 01 register/OTP purpose completes; it is not
carried into Phase 02 onboarding.

Automated:

- `privacy_canary_test` — canary absent from `MemoryVault`, request URLs,
  named routes, and SharedPreferences/Drift-equivalent stores used by the
  app. Language switch does not persist it.
- `patient_profile_test` — model has no national-id field.
- `patient_api_test` — write-only request field; responses with extra
  protected keys do not map National ID.
- `api_failure_envelope_test` — validation messages redact an echoed id;
  `toString` omits the message body.
- Onboarding review widget tests — `find.text(canary)` is nothing.

Live Core: fixture National IDs were **not** present in
`apps/core-api/storage/logs/laravel.log` after the journey. The local/testing
seeder writes credentials only under `/tmp/` and does not print National IDs
to the console. `/tmp` JSON is not in the repository.

Secure storage may still hold authentication tokens from `TokenStore`. It is
not a Patient profile / National ID cache.

## Idempotency proof

`IntentIdempotencyStore` (in-memory only):

- Same payload fingerprint reuses the key (timeout/retry).
- Material fingerprint change mints a new key.
- The key is a random hex string, **not** derived from National ID, phone,
  or name (`intent_idempotency_test` asserts the canary is absent from the
  key).
- The pair is retired after `profile_ready` / `manual_review_required` and
  after terminal validation/auth/not-found outcomes (`patient_api_test`).

The in-memory fingerprint may include protected input only while the intent
is live; `retire()` drops it. Nothing is written to disk.

## Manual-review / non-enumeration proof

Client mapping: any `manual_review_required` compact result becomes
`PatientOnboardingStatus.manualReviewRequired` regardless of extra `reason`
payload. Privacy test feeds two different speculative reasons and asserts
the same client status name.

UI copy (`manualReviewTitle` / `Body` / `Next`) does not mention National
ID, “already exists”, unlinked profiles, claim internals, or another
patient. Refresh re-queries own `/me` + `/patients/me/profile` only. No
Admin appeal API.

Live Core: challenger Patient onboarded with the owner’s National ID (the
legitimate identity-mismatch case under claim-disabled defaults) →
`manual_review_required` → holding screen with generic body; no “already
exists” / “National ID” / “unlinked” text.

## Profile display result

`ProfileScreen` renders the GET projection: full name, gender, date of
birth, marital status, self-reported height/weight/blood type, server
status, version, timestamps. Patient ID is not promoted as a credential.
National ID, HMAC, ciphertext, key version, ownership internals, and audit
metadata are not displayed.

Non-`active` statuses (`disputed`, `merged`, `restricted`, `archived`,
unknown) are read-only; the client never assigns status or offers
transitions.

Live Core: after onboarding, the profile screen showed `E2E Patient` and
did not show the National ID.

## Demographics edit result

`EditDemographicsController` + `EditDemographicsScreen` PATCH allowlisted
fields only, with the loaded `version`. National ID is not an edit control.
Ownership/status/encryption/audit fields are not submitted.

Live Core: weight `70` saved against version 1 → authoritative profile
version 2.

## VERSION_CONFLICT result

Mandatory path (no last-write-wins, no hidden retry with a new version):

1. Flutter held version N (here N=2 after the weight edit).
2. A second authorized client PATCHed `marital_status=married` at version 2
   → server version 3.
3. Flutter stale save at version 2 → HTTP 409 `VERSION_CONFLICT`.
4. Conflict card (`version-conflict`) with localized copy requiring refresh.
5. Refresh loaded authoritative version 3 (`married`); conflict cleared.
6. No automatic re-PATCH.

Covered by `profile_widget_test` (scripted 409) and the live Core journey.

## Logout / account-switch isolation result

`signOut` increments generation, clears onboarding (including National ID),
clears the edit-demographics controller on `PatientRouteUnauthenticated`,
sets unauthenticated immediately, then revokes via `AuthApi.logout`.
In-flight `restore()` cannot overwrite that generation.

`patient_session_test`: Patient A profile → logout → Patient B login → A
demographics absent.

Live Core: sign-out then Patient B (`full_name=Patient B`) → profile screen
showed Patient B only; `E2E Patient` and the fresh National ID were absent.

## Arabic / RTL / accessibility result

`ClinicStrings` covers onboarding steps, demographics, self-reported labels
and storage-bounds (not medical advice), manual review, profile, edit,
validation, version conflict, refresh, logout/session errors in English and
Arabic. Widget tests:

- Arabic onboarding review is RTL and still hides National ID.
- Arabic profile is RTL and omits National ID.
- Large text (text scale 2) keeps the primary / edit action.
- Identity validation is announced as text (`errorText` / live region), not
  color only.
- Numeric keyboards on height/weight/National ID; National ID is obscured
  with empty autofill hints.
- Primary action lives in `PrimaryBottomBar` so reflow does not hide it.

Language switch during onboarding keeps the in-memory draft; privacy test
asserts the canary is not persisted.

## Privacy / security-canary result

| Surface | Result |
| --- | --- |
| Credential vault / TokenStore | canary absent (tokens only) |
| SharedPreferences / Drift profile cache | not introduced; canary test |
| Named routes | no patient-id or National-ID parameters |
| Dio request path/query | canary absent; body may send `national_id` once |
| ApiFailure message / toString | redacted; toString omits message |
| Review / profile / manual-review UI | canary absent |
| Laravel log after live E2E | fixture National IDs absent |
| BOLA | no Flutter get-by-id; own-profile path only |

Android application backup remains disabled (`backup_exclusion_test`).

## Real Flutter / Core integration result

**Executed locally** (not GitHub Flutter job; that job does not provision
Core or set `CLINIC_E2E_*`):

```
CLINIC_E2E_BASE_URL=http://127.0.0.1:8080
flutter test test/e2e/patient_core_journey_test.dart
```

Result: **1 passed** (`fresh patient onboards, edits, conflicts, and isolates
accounts against Core`), ~3s after load, 2026-09-22.

Fixture: `php artisan e2e:seed-patient-flutter --write=/tmp/clinic-e2e-patient-flutter.json`
(local/testing only). HTTP from widget tests requires
`HttpOverrides` plus `tester.runAsync`; the journey fills onboarding widgets,
then drives `OnboardingController.submit` / `PatientApi` / demographics
save inside `runAsync` so Dio is not trapped in fake async. Direct DB
mutation was not used for the patient-facing actions. Sibling-device login
for VERSION_CONFLICT used the same Patients PATCH contract.

Without `CLINIC_E2E_*`, the same file is **skipped** (CI default).

## Android / iOS coverage actually run

| Layer | Result |
| --- | --- |
| `dart analyze --fatal-infos --fatal-warnings` on `apps/patient-app` | No issues |
| Host `flutter test` widget/unit/privacy (no E2E env) | **37 passed, 1 skipped** (live Core journey skipped) |
| Live Core journey with `CLINIC_E2E_*` | **1 passed** (see above) |
| Android emulator / device GUI | **not run** |
| iOS simulator / device GUI | **not run** |
| GitHub `Flutter` job | analysis + `melos run test` on ubuntu-latest; no device matrix |

Do not treat this chunk as an Android or iOS platform PASS.

## Exact Flutter test counts (host, this environment)

| Package | Result |
| --- | --- |
| `apps/patient-app` (default, no E2E env) | **37 passed, 1 skipped** |
| `apps/patient-app` live Core file with env | **1 passed** |
| `packages/flutter/api_client` | **13 passed** |
| `packages/flutter/error_handling` | **11 passed** |
| `packages/flutter/networking` | **4 passed** |
| `packages/flutter/common_models` | **8 passed** |
| `packages/flutter/localization` | **1 passed** (`patient_strings_test`) |
| `packages/flutter/authentication` (unchanged auth suite) | **24 passed** |

GitHub Flutter CI additionally runs `melos run analyze` and `melos run test`
across the Dart workspace after `npm run contracts:generate:dart`. That job
does not execute the live Core journey.

## Exact Core / Patients test counts (host Pest, `clinic_test`)

| Suite | Result |
| --- | --- |
| `tests/Feature/Patients` (Flows + Race + Privilege + Seed fixture) | **23 passed** / 310 assertions |
| `PatientProfileFlowsTest` | included in Patients 23 (14 flow cases, unchanged) |
| `PatientProfileRaceTest` | included (4 race cases, unchanged) |
| `PatientPostgresPrivilegeTest` | included (2 passed; roles present on `clinic_test`) |
| `SeedPatientFlutterFixtureTest` | 3 passed (env gate, `/tmp` write path, session-isolated HTTP) |
| `tests/Feature/Auth/AuthenticationFlowsTest.php` | **22 passed** / 143 assertions |
| `tests/Feature/Identity` + `tests/Unit/Identity` | **108 passed** / 545 assertions |
| `tests/Unit/Platform/ArchitectureBoundaryTest.php` | **25 passed** / 4926 assertions |
| `tests/Unit/Platform/OpenApiErrorCodeParityTest.php` | **1 passed** / 3 assertions |
| Combined Patients + Auth + Identity **Feature** + Architecture | **162 passed** / 5848 assertions |
| `npm run contracts:lint` | OpenAPI `core@v1` valid |

Backend tests were not weakened. Concurrent duplicate-profile behavior
remains server-owned. The seeder is registered in `AppServiceProvider` and
is fail-closed outside local/testing.

## Exact changed files (`bbbcd7f` → this evidence commit)

Implementation (40 files) plus this evidence file:

```
.gitleaks.toml
apps/core-api/app/Console/SeedPatientFlutterFixtureCommand.php
apps/core-api/app/Providers/AppServiceProvider.php
apps/core-api/tests/Feature/Patients/SeedPatientFlutterFixtureTest.php
apps/patient-app/lib/auth_panel.dart
apps/patient-app/lib/main.dart
apps/patient-app/lib/onboarding/onboarding_controller.dart
apps/patient-app/lib/onboarding/onboarding_draft.dart
apps/patient-app/lib/onboarding/onboarding_screen.dart
apps/patient-app/lib/onboarding/review_holding_screen.dart
apps/patient-app/lib/profile/account_gate_screen.dart
apps/patient-app/lib/profile/edit_demographics_controller.dart
apps/patient-app/lib/profile/edit_demographics_screen.dart
apps/patient-app/lib/profile/profile_screen.dart
apps/patient-app/lib/providers.dart
apps/patient-app/lib/session/patient_session.dart
apps/patient-app/lib/widgets/patient_chrome.dart
apps/patient-app/pubspec.yaml
apps/patient-app/test/backup_exclusion_test.dart
apps/patient-app/test/e2e/patient_core_journey_test.dart
apps/patient-app/test/onboarding_validation_test.dart
apps/patient-app/test/onboarding_widget_test.dart
apps/patient-app/test/patient_session_test.dart
apps/patient-app/test/privacy_canary_test.dart
apps/patient-app/test/profile_widget_test.dart
apps/patient-app/test/routing_widget_test.dart
apps/patient-app/test/support/harness.dart
packages/flutter/api_client/lib/clinic_api_client.dart
packages/flutter/api_client/lib/src/patient_api.dart
packages/flutter/api_client/test/fakes.dart
packages/flutter/api_client/test/patient_api_test.dart
packages/flutter/common_models/lib/clinic_common_models.dart
packages/flutter/common_models/lib/src/patient_profile.dart
packages/flutter/common_models/test/patient_profile_test.dart
packages/flutter/error_handling/lib/src/api_failure.dart
packages/flutter/error_handling/test/api_failure_envelope_test.dart
packages/flutter/localization/lib/src/clinic_strings.dart
packages/flutter/localization/test/patient_strings_test.dart
packages/flutter/networking/lib/clinic_networking.dart
packages/flutter/networking/lib/src/intent_idempotency.dart
packages/flutter/networking/test/intent_idempotency_test.dart
docs/evidence/phase-02/chunk-13-patient-flutter-onboarding-profile.md
```

`designs/**` was not changed.

## Residuals — not closed

Unrelated residuals remain open and were not silently closed:

- patient demographic revision erasure residual
- unlinked walk-in erasure residual
- `identity_profile_links` unused
- `FEATURE_IDENTITY_PROFILE_CLAIM` disabled
- DEF-SEC-MFA-001
- SF-001
- G-08-04
- staging fail-closed

## Remaining risks

- Manual-review hold is in-session only. There is no polling endpoint beyond
  own profile; a process kill returns the user to onboarding on 404, which
  matches the backend’s non-enumeration (404 for both “no profile” and
  “not authorized”).
- Live Core journey is host/`CLINIC_E2E_*` only. GitHub Flutter CI will skip
  it. Widget `tap` of Submit still starts Dio in fake async; E2E therefore
  awaits the same Riverpod controllers inside `runAsync` after the review
  UI assertion.
- No Android/iOS simulator evidence.
- `/tmp` E2E fixture JSON contains National ID by design; it must never be
  committed.
- Independent review decides READY_TO_MERGE.

Chunk 13 does not complete Phase 02.
Phase 02 remains NOT PASS.
