# Phase 02 chunk 11 — Doctor onboarding and verification UI (not phase PASS)

> **Catalogue supersession (2026-09-24):** Phase 02 Verification Policy
> v1.0.1-phase02 replaces `professional_id` as an ENGINEERING_DEFAULT
> synthetic requirement. See
> `docs/evidence/phase-02/verification-policy-v1.0.1-phase02.md`.

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** Doctor Electron Phase-02 onboarding and verification
vertical slice on already-merged backend foundations. Product flow: Doctor
desktop login → MFA when existing auth policy requires it → inspect own
Doctor profile → if no profile, load active specialties → submit Doctor
onboarding → generic `manual_review_required` or refresh canonical own
profile → open/resume Doctor verification case → select `professional_id`
evidence through a main-owned native file chooser → main-owned secure upload
→ bounded scanner/status polling → submit verification → refresh
authoritative verification status → pending / changes_requested / rejected /
approved / suspended presentation → safe resubmission via a new draft case
where `VerificationService::openDoctorCase()` allows it.

**Explicitly deferred / out of scope for this chunk:** Clinic location UI,
clinic staff invitation UI, Secretary desktop, Patient Flutter Phase-02
completion, Pharmacy additional branches/membership management, scheduling,
availability, booking, queue, clinical workspace, records, prescriptions,
labs, public Doctor listing/search, map/geocoder, general Doctor profile
PATCH after onboarding, DEF-SEC-MFA-001, SF-001, G-08-04, staging
provisioning, Pharmacy envelope-key rotation, Pharmacy subject-erasure
residual.

- **Branch:** `cursor/phase-02-doctor-onboarding-verification-ui-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/17
- **Base (GitHub `main`):** `fbed5f3fd93f0d717821d7158256dc4e8051bcd7`
- **CI-verified HEAD:** `6e8d9fe6c517875d64c146110ba8615647015d63`
- **GitHub CI:** `pull-request` run **35586368278** SUCCESS on that exact HEAD
  (https://github.com/mahmoudemad68/clinical_system/actions/runs/35586368278)
- **Recorded:** 2026-09-21
- **Environment:** host Node 22 workspace, PHP 8.3 Core Pest against local
  PostgreSQL `clinic_test`. Packaged Electron E2E ran in GitHub
  `desktop-packaged-e2e` on ubuntu-latest, macos-latest, and windows-latest.

## What was implemented

Doctor Electron replaces the signed-in Auth-only shell with a Phase-02
onboarding/verification workspace while keeping session controls, language
switching, health, and MFA. Two additive Core HTTP gaps were required so the
desktop could call in-process services that previously had no Doctor UI
route:

1. `GET /api/v1/doctors/specialties` — authenticated Doctor projection of
   existing `ListSpecialties` (active rows only, service order).
2. `POST /api/v1/doctors/me/verification-cases` — additive HTTP for existing
   `VerificationService::openDoctorCase()`, compact applicant-safe outcome
   sized for the Platform 255-byte idempotency pointer.

`professional_id` remains an **ENGINEERING_DEFAULT** synthetic requirement.
No test or UI copy claims government or syndicate verification.

## Doctor-only IPC channels

Shared Auth/Platform channels remain on `ALL_CHANNELS` /
`CAPABILITY_REGISTRY` and are registered by both desktops.

Doctor domain channels live in
`packages/typescript/desktop_bridge_contracts/src/doctor.ts` and are **not**
members of `ALL_CHANNELS`. Executable registration is only in
`apps/doctor-desktop` (`DOCTOR_REGISTRY` =
`CAPABILITY_REGISTRY` ∪ `DOCTOR_CAPABILITY_REGISTRY`). The renderer may call
`window.clinic.doctor`. Pharmacy must not.

| Channel | Capability |
| --- | --- |
| `clinic:doctor.profile.getOwn` | GET own Doctor profile projection |
| `clinic:doctor.specialties.list` | GET active specialty catalogue |
| `clinic:doctor.profile.onboard` | POST Doctor onboarding |
| `clinic:doctor.verification.openCase` | POST open/resume Doctor verification case |
| `clinic:doctor.verification.status` | GET applicant-safe verification status |
| `clinic:doctor.verification.submit` | POST verification submit |
| `clinic:doctor.evidence.select` | native file chooser → opaque handle |
| `clinic:doctor.evidence.clear` | invalidate handle |
| `clinic:doctor.evidence.upload` | main-owned create + PUT + complete |
| `clinic:doctor.upload.status` | GET upload projection |

There is no generic `invoke`, HTTP, filesystem, or upload primitive. The
renderer never receives bearer tokens, signed upload URLs, storage locators,
object IDs, National ID, syndicate number, or absolute paths.

## Trust-boundary proof

Preserved Doctor Electron controls:

- `nodeIntegration: false`, `contextIsolation: true`, `sandbox: true`
- renderer: no Electron/Node import, no `fetch`/`XMLHttpRequest`, no
  `localStorage`/`sessionStorage`/`indexedDB` for Doctor data, no raw IPC,
  no generic `invoke`
- preload maps named methods to constants; main validates sender, origin,
  size, and request/response schema; forged sender / oversized payload /
  unknown fields fail closed
- signed upload target and absolute path never appear in preload or IPC
  response schemas
- Pharmacy desktop source tests assert Doctor channels are absent; Doctor
  desktop source tests assert Pharmacy channels are absent
- packaged runtime E2E asserts `window.clinic.doctor` methods exist on the
  Doctor product and `window.clinic.pharmacy` is absent (and the reverse on
  Pharmacy)

## Added backend HTTP gaps

### Specialty catalogue — `GET /api/v1/doctors/specialties`

Doctors already owned `ListSpecialties` in-process. Onboarding cannot
hard-code specialty UUIDs. `ListDoctorSpecialties` is the smallest
authenticated HTTP wrapper:

- actor must be `AccountType::Doctor` and `canAccessBusinessEndpoints()`
- capability `doctors.specialties.read` (AUTHENTICATED_SELF; pending phone
  still FeatureUnavailable)
- Patient / Pharmacy / Secretary receive the existing 404 FeatureUnavailable
  hide, not Doctor privileges
- projection: `specialty_id`, `code`, `label_ar`, `label_en`, `sort_order`
- no `created_at`, `active`, HMAC, or other internal metadata
- ownership stays on Doctors / `ListSpecialties`

### Doctor verification case open/resume — `POST /api/v1/doctors/me/verification-cases`

`VerificationService::openDoctorCase()` already existed; HTTP previously had
submit/status only. The new route:

- uses Platform `idempotency` middleware
- empty closed JSON body (`DoctorVerificationRules::open()`)
- creates one draft when allowed, replays the current open case, or opens a
  new case only where existing policy permits
- returns compact `DoctorVerificationCaseOutcome` (`status`, `doctor_id`,
  `case_id`, `case_status` ∈ {draft, pending_review}, `case_version`,
  `profile_version`) because the full applicant projection exceeds the
  255-byte idempotency pointer
- never exposes reviewer assignment, notes, fraud signals, object keys,
  HMACs, or protected Doctor identifiers
- Pharmacy verification HTTP is unchanged

## Idempotency semantics (main-owned)

Intents: `doctor.onboard`, `doctor.verification.open`,
`doctor.verification.submit`, `doctor.verification.upload.create`,
`doctor.verification.upload.complete`.

Keys never cross IPC. Same logical intent + same payload retry reuses the
key after timeout/unknown outcome. Changed payload mints a new key. Keys
are not retired before the IPC response schema is accepted. Malformed /
incomplete HTTP success (`profile_ready` without `doctor_id`/`version`)
throws `UPSTREAM_FAILED` and keeps the key. Timeout/transport keeps the
key. Terminal caller-visible `VALIDATION_FAILED` / `PERMISSION_DENIED`
(onboarding) and `VERSION_CONFLICT` / `STATE_CONFLICT` (submit) retire when
it is safe to create a new intent. Successful validated caller-deliverable
results (including `manual_review_required`) retire after mapping + schema
accept.

Doctor copies of intent-keys / ipc-delivery / upload-target /
evidence-handles are Doctor-owned files. Pharmacy application business code
is not imported.

## Secure-file handle lifecycle and TOCTOU

`DoctorEvidenceHandleStore` (main process):

1. Renderer requests select professional verification evidence.
2. Main opens `dialog.showOpenDialog` (PDF/JPEG/PNG, 20 MiB).
3. Main inspects with `lstat` + `realpath`, then opens a file descriptor,
   `fstat`s it, sniffs magic bytes from that descriptor, and stores the
   absolute path privately.
4. Renderer receives only `{ handleId, displayName, sizeBytes, candidateMediaType }`.
5. Handle is unguessable (`base64url` 24 random bytes), TTL 15 minutes,
   one-at-a-time, purpose-bound to `professional_id`.
6. `readForUpload` identity-checks then **descriptor-pins** the inode so a
   pathname swap after open cannot change the uploaded bytes.
7. Handle is invalidated only after caller-visible upload success (create /
   PUT / complete finished, safe projection passed IPC schema). Logout and
   window `closed` clear the store. TIMEOUT / response-schema failure keep
   the handle and both upload keys.

Bytes never cross IPC as a renderer payload. The fd and absolute path never
cross IPC. Failed upload errors serialize the stable code only.

## Upload-target confinement and scanner polling

Main performs `POST /verification-uploads` → privileged-memory signed
target → `PUT` bytes (`redirect: 'error'`) → `POST .../complete`. The
target is not persisted, logged, or placed in renderer errors.

Renderer polls only through `window.clinic.doctor.uploadStatus`. Interval
2s while state is `requested` / `uploading` / `quarantined` / `validating`
/ `scanning`. Finite 60s timeout then a user-visible retry. Complete is not
available. Submit stays disabled until the server marks evidence available
and clean.

## Resubmission

`changes_requested` / `rejected` do not mutate the previous submitted case.
The UI calls open/resume; the server creates a new draft only if
`openDoctorCase()` allows it. Fresh evidence is selected; old handles,
signed URLs, locators, and stale versions are not copied. There is no
general Doctor profile PATCH in this chunk.

## Renderer state coverage

Vitest `App.test.tsx` covers signed-out login, MFA, Doctor account
validation, no-profile onboarding, specialty catalogue loading,
profile-ready (canonical own-profile refresh), generic manual-review,
draft verification, file selection, uploading/scanning, available, submit,
pending review, changes requested, rejected, approved, suspended, version
conflict refresh, logout, and Arabic RTL. National ID and syndicate number
are absent from the DOM after submission. No clinical navigation testids
or Phase-03 screens exist, including after approval.

Bounded feature structure:

- `renderer/features/doctor-onboarding`
- `renderer/features/doctor-verification`
- `renderer/features/doctor-shell`
- `renderer/components`
- `renderer/strings/doctor.ts`

TanStack Query owns server state. React local state owns ephemeral form
state. Language switch updates `document.dir` / `lang` and does not persist
National ID or syndicate number.

## Arabic / RTL / accessibility

Existing `@clinic/localization` + `@clinic/design-tokens`. Doctor copy lives
in `strings/doctor.ts` (en/ar). Labels, `role="alert"`/`status`,
`aria-describedby` on disabled submit, and non-color-only status text are
present. Packaged E2E still switches English → Arabic and asserts `dir=rtl`.

## Admin Doctor regression

Admin verification semantics were not modified. Local Pest ran existing
Doctor Admin review HTTP plus Pharmacy Admin review HTTP (queue, claim,
document access, approve/reject/changes_requested). Applicant canaries and
protected identifiers remain out of Admin projections by existing tests.

## Packaged Doctor binary / ASAR provenance

Packaged proof is GitHub `desktop-packaged-e2e` (real packaged binary, not
Forge/Vitest) on `6e8d9fe6c517875d64c146110ba8615647015d63`, run
**35586368278**. Spec asserts `window.clinic.doctor` methods exist, Pharmacy
surface is absent, Node globals are absent, no generic `invoke`, custom
origin `clinic-doctor-app://-`, and Doctor boot UI. Doctor WebdriverIO:
5 passing / 0 failing on linux, darwin, and win32.

Doctor `app.asar` SHA-256 is identical across the three CI OS runners:

`9ebc2f9769acdd316ec0cee021719024f5d1755a1626ff94f8bb16b1bb973df3`

| OS | Doctor binary SHA-256 |
| --- | --- |
| linux x64 | `199529b21d6f5cb8bb5d3425952ffd11413df9336dafa54ae9ceb8ef3bc2cc92` |
| darwin arm64 | `59f028a611f1d52e7821528280ddd7aa9d9a8cbd9c502dee3070bfea6014c795` |
| win32 x64 | `2c7587ed8a2a377ecc79b035bfbb780a7b185684d9a37d35bafe685ad9f1ae9e` |

Fuses observed on all three: RunAsNode DISABLE, EnableNodeOptionsEnvironmentVariable DISABLE, EnableNodeCliInspectArguments DISABLE, EnableEmbeddedAsarIntegrityValidation ENABLE, OnlyLoadAppFromAsar ENABLE.

Full synthetic Phase-02 Doctor flow against the real backend state machine is
Pest `DoctorVerificationSyntheticE2ETest` (not the unsigned packaged boot
fixture).

## OpenAPI / generated clients

Additive only:

- operationId `listDoctorSpecialties`
- operationId `openOwnDoctorVerificationCase`

No existing operationIds renamed. TypeScript client regenerated.
`npm run contracts:breaking -- origin/main`: **No breaking contract
changes against origin/main.**

## Exact local test counts (this workspace)

| Command | Result |
| --- | --- |
| `npm run typecheck --workspace apps/doctor-desktop` | pass |
| `npm run test --workspace apps/doctor-desktop` | **13 files, 142 passed** |
| `npm run test --workspace apps/pharmacy-desktop` | **13 files, 126 passed** |
| `npm run test --workspace @clinic/desktop-bridge-contracts` | **1 file, 5 passed** |
| `npm run contracts:breaking -- origin/main` | no breaking changes |
| `./vendor/bin/phpstan analyse` (chunk PHP files) | 0 errors |
| `./vendor/bin/deptrac analyse` | 0 violations, 2828 allowed |
| Pest `tests/Feature/Doctors` + `tests/Feature/Verification` + `tests/Feature/Admin` + `IdentityRulesTest` + `ArchitectureBoundaryTest` | **186 tests, 184 passed, 2 skipped**, 7884 assertions |

GitHub CI exact run on this HEAD: **35586368278** SUCCESS
(https://github.com/mahmoudemad68/clinical_system/actions/runs/35586368278).

Jobs SUCCESS: Contracts, Detect changed areas, Security scans, Supply-chain
policy, Electron desktops, Secure-file providers, Admin web, Core API,
Packaged Electron E2E (ubuntu-latest, macos-latest, windows-latest),
Runtime image scan (core-api), Runtime image scan (ai-service). Flutter and
AI service skipped (path filters).

## Residuals (Phase 02 remains NOT PASS)

- Doctor clinic-location/staff UI remains
- Patient Flutter Phase-02 completion remains
- Pharmacy additional branches/membership management remains
- Phase-02 p95/load closeout remains
- Clinic invite TTL/Egypt bbox remain ENGINEERING_DEFAULT
- SF-001 remains MERGE_ONLY / production promotion blocked
- G-08-04 / ADR 0014 / profile-claim gate unchanged
- staging remains unprovisioned
- Pharmacy envelope-key rotation remains deferred
- existing Pharmacy subject-erasure residual remains open
- DEF-SEC-MFA-001 remains OPEN / not addressed
- historical PHARM-PKG-001 is preserved as NOT_REPRODUCED_ON_FRESH_ARTIFACT,
  not treated as an active product defect
