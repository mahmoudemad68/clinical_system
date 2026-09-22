# Phase 02 chunk 12 — Doctor clinic locations and staff UI (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** Doctor Electron Phase-02 clinic-location and clinic-staff
management UI on the already-merged Chunk 10 Clinics HTTP contracts. Product
flow for an authenticated, active, approved owning Doctor:

Doctor desktop login → MFA when required → Doctor workspace → Clinic Locations
→ create an EG Cairo clinic location → list shows the location → open details →
edit public name / address / coordinates with `expected_version` → reload the
authoritative projection → invite a secretary with a write-only phone → observe
a pending invitation → the synthetic intended secretary accepts through the
existing Core `AcceptClinicStaffInvitation` path (not a Doctor UI action) →
Doctor refreshes memberships → active secretary is visible → Doctor confirms
revoke → revoked state is visible.

**Explicitly deferred / out of scope for this chunk:** schedules, appointment
types, prices, availability, booking, walk-ins, queue, encounters, clinical
records, patient list, public doctor/clinic directory, public clinic search,
public availability, inventory, billing, archive/deactivate/close location,
Patient Flutter completion, Pharmacy membership-management UI, Admin clinic
management, DEF-SEC-MFA-001, SF-001, staging provisioning, Phase 03, and any
unapproved third-party map/geocoder. `designs/**` was not modified.

Chunk 12 does **not** complete Phase 02.
**Phase 02 remains NOT PASS.**
Schedules, appointment types, prices, availability, booking, and public clinic
directory remain outside this chunk.

Do **not** mark READY_TO_MERGE from this note. Independent review decides that.

- **Branch:** `cursor/phase-02-chunk-12-doctor-clinic-locations-staff-ui-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/21
- **Baseline (GitHub `main`):** `a667231847484482edef4e087425e369ab4dcbce`
- **Product UI HEAD:** `5b98f934bdbb959e72e2fe32f16032525b769297`
- **CI-gate implementation HEAD:** `59116459bcb45d9a04b73a3eec5c91f1efd3f6e9`
- **GitHub CI (CI-gate HEAD):** `pull-request` run **35696845966** SUCCESS
  on that exact SHA
  (https://github.com/mahmoudemad68/clinical_system/actions/runs/35696845966)
  — 15 success, 1 skipped by path filter (`AI service`)

Jobs on that run: Detect changed areas, Supply-chain policy, Contracts,
Security scans, Core API, Secure-file providers, Admin web, Flutter, Electron
desktops, **Forge Doctor practice E2E** (executed, not skipped), Packaged
Electron E2E (ubuntu/macos/windows), Runtime image scan (core-api), Runtime
image scan (ai-service). `AI service` skipped. Core API Tests step succeeded
(Chunk 10 Clinics suite included in the full Core Pest run).

Run **35694988078** on `5b98f93` is **not** practice-E2E proof: that desktop
job wrote `skipped=true` because Core was unreachable and still exited 0.

- **Recorded:** 2026-09-22
- **Environment:** host Node 22 workspace, PHP 8.3 Core Pest against local
  PostgreSQL `clinic_test`. Forge Doctor GUI used live Core at
  `http://localhost:8080` (`/api/v1/health` HTTP 200, `core=operational`,
  `ai=degraded`). Packaged Electron E2E is the GitHub `desktop-packaged-e2e`
  matrix; packaged Doctor is not required to connect to local HTTP
  (`DOC-PKG-HTTP-001` remains expected / by design).

## Baseline

`origin/main` at start of this chunk was
`a667231847484482edef4e087425e369ab4dcbce` (merge of PR #20, Chunk 11 worker
audit identity). Chunk 11 is CLOSED. Work started on a new branch from that
commit. No prior Phase-02 feature branch was continued. `designs/**` was not
modified.

## What was implemented

Doctor Electron adds a Phase-02 practice workspace while keeping session
controls, language switching, health, MFA, and the existing onboarding /
verification surfaces. Clinic-management navigation is shown only when
authoritative `/me` plus own Doctor profile report:

- `accountType === 'doctor'`
- `status === 'active'`
- `assuranceLevel` in `aal2_totp` | `aal2_recovery_code`
- `profile.verificationStatus === 'approved'`

Capability strings such as `clinics.location.write` are not treated as a client
grant. Hidden UI is not authorization; Core still enforces owner / approved
doctor / BOLA rules.

No Clinics backend business-rule change. Two Core test helpers seed/accept
synthetic actors for Forge GUI E2E only.

## Doctor routes / screens

Hash routes (address and invitation phone are never placed in the URL):

| Route | Screen |
| --- | --- |
| `#/practice/locations` | Clinic location list |
| `#/practice/locations/new` | Create location |
| `#/practice/locations/:id` | Location details (Details tab) |
| `#/practice/locations/:id/location` | Location edit (coordinates confirmed locally) |
| `#/practice/locations/:id/staff` | Staff invite + memberships |

Phase 02 tabs are **Details**, **Location**, and **Staff** only. There is no
Schedule or Appointment Types tab (functional or teaser) and no Phase 03
placeholder route. `PHASE_03_HASH_FRAGMENTS` parse as home.

## Bridge operations added

Doctor-only IPC in `packages/typescript/desktop_bridge_contracts/src/doctor.ts`.
Pharmacy does not register these channels. There is no generic `invoke`.

| Channel | Operation |
| --- | --- |
| `clinic:doctor.locations.list` | `listLocations({ cursor?, limit? })` |
| `clinic:doctor.locations.create` | `createLocation({ publicName, address, countryCode: 'EG', latitude, longitude })` |
| `clinic:doctor.locations.get` | `getLocation({ locationId })` |
| `clinic:doctor.locations.update` | `updateLocation({ locationId, expectedVersion, ... })` |
| `clinic:doctor.locations.inviteStaff` | `inviteStaff({ locationId, phone })` |
| `clinic:doctor.locations.memberships` | `listMemberships({ locationId })` |
| `clinic:doctor.locations.revokeMembership` | `revokeMembership({ locationId, membershipId })` |

Create and invite send a main-process `Idempotency-Key` (`doctor.clinic.location.create`,
`doctor.clinic.staff.invite`). The renderer never sees tokens, cookies, XSRF,
`ipcRenderer`, Node `require`/`process`, or a generic network capability.

Invite HTTP 201 → `existingPending: false`. Invite HTTP 200 (replayed pending)
→ `existingPending: true`. Both render as a pending invitation; wording differs
only in a harmless already-pending phrase. Phone is cleared from form state
after submit and is stripped from the mapped response.

## Backend APIs reused

- `GET /api/v1/clinic-locations`
- `POST /api/v1/clinic-locations`
- `GET /api/v1/clinic-locations/{location_id}`
- `PATCH /api/v1/clinic-locations/{location_id}`
- `POST /api/v1/clinic-locations/{location_id}/staff-invitations`
- `GET /api/v1/clinic-locations/{location_id}/memberships`
- `DELETE /api/v1/clinic-locations/{location_id}/memberships/{membership_id}`

Secretary accept `POST /api/v1/clinic-staff-invitations/{invitation_id}/accept`
exists and is **not** a Doctor UI action. Forge E2E calls
`AcceptClinicStaffInvitation` with a secretary `ActorContext` so the Doctor UI
can refresh an active membership.

List pagination is read from envelope `meta.pagination` `{ has_more, next, limit }`.
Owner projections may include `address`, `latitude`, and `longitude`. Staff
projections omit those fields. Memberships never include phone, HMAC, National
ID, email, device, or clinical data.

## Create / edit / version-conflict proof

- Gateway: create body is only `public_name`, `address`, `country_code`,
  `latitude`, `longitude` plus `Idempotency-Key`; `doctor_id` / `status` /
  `version` are absent. Network failure reuses the same idempotency key until
  a delivered success.
- Renderer create: valid EG Cairo coordinates `30.0444, 31.2357` with explicit
  confirmation. Invalid coordinates are blocked locally before submit.
- Renderer edit: every PATCH includes `expectedVersion` from the authoritative
  projection. Successful save replaces query cache with the response
  (`version: 2` in the GUI test and in Forge E2E).
- `VERSION_CONFLICT` / HTTP 409 shows
  “This location was changed elsewhere. Refresh the latest version before
  saving again.” and does not auto-overwrite
  (`data-testid="version-conflict"`).
- Country is server policy EG; the country field is read-only in the form.
- Location status is read-only. There is no Delete / Archive / Deactivate /
  Close location action.

## Invitation proof

- Only editable field is `phone` (write-only). Role is not submitted.
- After invite, the phone is cleared; the result shows `invitation_id`,
  `status`, `expires_at`, and a pending / already-pending phrase.
- Replay of an existing pending invitation is the same pending visual with
  `data-existing-pending="true"`.
- The UI does not disclose whether the phone already belongs to an account.
- Phone / HMAC canaries are absent from mapped gateway JSON.

## Membership / revoke proof

- List renders `membership_id`, `role`, `status`, `version`, `invited_at`,
  `accepted_at`, `revoked_at` only.
- Revoke uses an explicit confirmation dialog (`confirm-revoke`) then DELETE.
- After success the row shows `status=revoked`. Repeated revoke is a server
  concern; the UI refreshes the authoritative projection.
- Cross-owner GET/open of another doctor’s location id shows a generic
  unavailable state (`NOT_FOUND`), not an existence leak.

## Authorization / gating proof

- Approved privileged Doctor sees `data-testid="practice-locations-nav"`.
- Pending / rejected / suspended / unverified Doctor does not.
- Patient and Pharmacy accounts see `account-denied` or no practice nav.
- Eligibility ignores a planted `clinics.location.write` capability on a
  non-approved or non-doctor actor.
- Logout clears the React Query cache and `#/practice` hash. Main logout
  clears doctor intent keys.

## RTL / accessibility proof

- `apps/doctor-desktop/src/renderer/strings/doctor.ts` has English and Arabic
  `practice` catalogues, including version-conflict copy.
- GUI test switches to Arabic, asserts `dir=rtl`, and reads membership status
  from the Arabic catalogue.
- Fields use associated labels (`id` + MUI `TextField` / `FormControlLabel`).
- Validation uses `role="alert"` text, not color only.
- Loading / saving / inviting / revoking expose busy labels.
- Keyboard-focusable buttons and a labelled revoke dialog.

## No Phase 03 proof

- No `data-testid="tab-schedule"` or `tab-appointment-types`.
- No `window.clinic.doctor.schedule` / `clinic:doctor.queue`.
- Packaged and Forge snapshots forbid `schedule` and generic `invoke` on the
  Doctor bridge.
- Phase 03 hash fragments parse as home.

## Renderer security-canary proof

Trust-boundary + GUI tests prove absence of:

- access / refresh tokens, cookies, XSRF
- raw `ipcRenderer` / generic `invoke`
- Node `require` / `process` in the renderer
- Pharmacy namespace on Doctor
- phone / HMAC / National ID / clinical fields on memberships
- `localStorage` / `sessionStorage` persistence of location or invitation data
- address / phone in URLs, metric labels, or IPC diagnostic dumps
- Google Maps / Mapbox / OpenStreetMap / Nominatim integration

Cookieless `credentials: 'omit'` on Doctor `net.fetch` is unchanged. Chunk 11
invariants (strong OS safeStorage, no `basic_text`, packaged HTTPS allowlist,
packaged HTTP rejected, Forge non-eval CSP, worker `clinic_worker`, audit
`clinic_audit_writer`, `clinic_worker` audit EXECUTE = false) were not
modified.

Coordinates are a local SVG pin (`CoordinatePreview`). No network map provider.

## Forge GUI E2E result

Required mode (`CLINIC_REQUIRE_DOCTOR_PRACTICE_E2E=1`) is the CI gate. Optional
local skip remains only when that flag is unset. `CI=true` does not imply
required mode.

Authoritative GitHub proof is job **Forge Doctor practice E2E** on run
**35696845966** / SHA `5911645`
(https://github.com/mahmoudemad68/clinical_system/actions/runs/35696845966/job/106645568056):

- log: `Forge Doctor practice E2E passed. Evidence: .../doctor-forge-practice-e2e.json`
- log: `Practice E2E evidence executed (skipped=false).`
- artifact `chunk-12-doctor-practice-e2e`:

```json
{
  "kind": "forge-development-practice-e2e",
  "skipped": false,
  "required": true,
  "apiBase": "http://localhost:8080",
  "coreApi": { "reachable": true, "status": 200 },
  "created": true,
  "edited": true,
  "invited": true,
  "accepted": true,
  "revoked": true,
  "crossOwner": true,
  "pendingGated": true,
  "pharmacyAbsent": true,
  "noScheduleTab": true,
  "noGenericInvoke": true
}
```

Local required-mode rerun against live Core `http://localhost:8080` matched
the same booleans.

Doctor-facing create / edit / invite / refresh / revoke ran through the real
desktop contracts and UI against Core/PostgreSQL. Secretary accept used the
existing Core service, not a Doctor screen and not a direct membership UPDATE.
Patient/Pharmacy GUI denial is covered by renderer tests (`account-denied` /
no practice nav).

Forge development smoke (`npm run desktop:forge-doctor-smoke`) passed on the
unprovisioned Electron desktops job. Packaged Doctor remains HTTP-free.

## Packaged E2E result

GitHub `desktop-packaged-e2e` on CI-gate HEAD `5911645` (run **35696845966**):

| OS | Job | Result |
| --- | --- | --- |
| ubuntu-latest | Packaged Doctor and Pharmacy WebdriverIO | success |
| macos-latest | Packaged Doctor and Pharmacy WebdriverIO | success |
| windows-latest | Packaged Doctor and Pharmacy WebdriverIO | success |

The spec requires Doctor clinic operations (`listLocations` …
`revokeMembership`) and forbids `schedule` / `invoke`. Packaged Doctor is
still not required to connect to local HTTP (`DOC-PKG-HTTP-001` remains
expected / by design). Forge development remains the approved local-HTTP GUI
integration path. The Forge practice job is a separate Core-backed gate.

## Core regression

Existing Chunk 10 Clinics Pest suite, unchanged:

| Suite | Result |
| --- | --- |
| `tests/Feature/Clinics` + `tests/Unit/Clinics` | **33 passed**, 633 assertions |
| `tests/Unit/Platform/ArchitectureBoundaryTest.php` | **25 passed**, 4926 assertions |
| `./vendor/bin/pint --test` | passed |

Includes location ownership, PostGIS / Egypt coordinate policy, version
conflict, cross-owner BOLA, secretary invitation, expiration/replay, HMAC
rotation dedup, acceptance, membership revoke, privilege tests, and
architecture boundaries. No Clinics business-rule test was weakened.

## Exact local test counts

| Command | Result |
| --- | --- |
| `npm run typecheck --workspace apps/doctor-desktop` | pass |
| `npm run typecheck --workspace apps/pharmacy-desktop` | pass |
| `npm run test --workspace apps/doctor-desktop` | **182 passed** / 15 files |
| `npm run test --workspace apps/pharmacy-desktop` | **138 passed** / 13 files |
| `@clinic/desktop-bridge-contracts` vitest | **6 passed** / 1 file |
| `@clinic/admin-web` (via `packages:test`) | 46 passed / 9 files |
| `@clinic/api-client` | 2 passed |
| `@clinic/encrypted-local-store` | 16 passed |
| `@clinic/error-handling` | 3 passed |
| `@clinic/localization` | 2 passed |
| `node --test scripts/desktop/forge-doctor-practice-e2e.test.mjs scripts/desktop/forge-doctor-smoke.test.mjs` | **8 passed** |
| `npm run desktop:forge-doctor-smoke` | passed |
| `CLINIC_REQUIRE_DOCTOR_PRACTICE_E2E=1 npm run desktop:forge-doctor-practice-e2e` | passed, `skipped=false` |
| `npm run desktop:forge-doctor-practice-e2e:assert` | passed |
| Clinics Pest (Feature + Unit) + ArchitectureBoundary | **58 passed**, 5559 assertions |

## Exact changed files since product UI HEAD `5b98f93`

```
.github/path-filters.yaml
.github/workflows/pull-request.yaml
package.json
scripts/desktop/assert-forge-doctor-practice-e2e-evidence.mjs
scripts/desktop/forge-doctor-practice-e2e.test.mjs
scripts/desktop/run-forge-doctor-practice-e2e.mjs
scripts/desktop/run-forge-doctor-smoke.mjs
scripts/desktop/with-linux-os-keystore.sh
docs/evidence/phase-02/chunk-12-doctor-clinic-location-staff-ui.md
```

## Exact changed files

Relative to `a667231847484482edef4e087425e369ab4dcbce`:

```
.github/path-filters.yaml
.github/workflows/pull-request.yaml
apps/core-api/tests/Support/bin/accept-chunk12-invitation.php
apps/core-api/tests/Support/bin/seed-chunk12-doctor-practice.php
apps/doctor-desktop/src/main/capabilities.ts
apps/doctor-desktop/src/main/doctor-gateway.test.ts
apps/doctor-desktop/src/main/doctor-gateway.ts
apps/doctor-desktop/src/main/platform-gateway.ts
apps/doctor-desktop/src/preload/index.ts
apps/doctor-desktop/src/renderer/App.test.tsx
apps/doctor-desktop/src/renderer/App.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/CoordinatePreview.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/LocationCreate.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/LocationDetails.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/LocationForm.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/LocationList.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/PracticeLocations.test.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/PracticeWorkspace.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/StaffSection.tsx
apps/doctor-desktop/src/renderer/features/doctor-practice/egyptCoordinates.ts
apps/doctor-desktop/src/renderer/features/doctor-practice/eligibility.ts
apps/doctor-desktop/src/renderer/features/doctor-practice/practiceRoute.ts
apps/doctor-desktop/src/renderer/features/doctor-shell/DoctorWorkspace.tsx
apps/doctor-desktop/src/renderer/strings/doctor.ts
apps/doctor-desktop/src/shared/trust-boundary.test.ts
package.json
packages/typescript/desktop_bridge_contracts/src/bridge-contracts.test.ts
packages/typescript/desktop_bridge_contracts/src/doctor.ts
packages/typescript/desktop_bridge_contracts/src/index.ts
scripts/desktop/assert-forge-doctor-practice-e2e-evidence.mjs
scripts/desktop/forge-doctor-practice-e2e.test.mjs
scripts/desktop/forge-doctor-smoke.test.mjs
scripts/desktop/run-forge-doctor-practice-e2e.mjs
scripts/desktop/run-forge-doctor-smoke.mjs
scripts/desktop/with-linux-os-keystore.sh
tests/desktop-e2e/specs/packaged-runtime.spec.mjs
docs/evidence/phase-02/chunk-12-doctor-clinic-location-staff-ui.md
```

This evidence file is the evidence commit after GitHub CI SUCCESS on
CI-gate HEAD `5911645` (run **35696845966**) with Forge practice
`skipped=false`.

## Remaining risks

- Packaged-window clinic operations are asserted by bridge-key presence, not a
  packaged GUI login against Core (`DOC-PKG-HTTP-001`).
- Headless GitHub Linux login depends on `gnome-keyring` + `dbus-run-session`
  so Electron `safeStorage` can select `gnome_libsecret`. Linux `basic_text`
  remains fail-closed; a keystore outage fails the required gate instead of
  skipping.
- Coordinate confirmation is a local checkbox plus numeric fields; there is no
  approved map provider.
- Invitation phone is write-only in the UI, but main-process idempotency
  fingerprints still hash the phone in memory (never logged).
- Patient/Pharmacy practice-nav denial is renderer-tested; Forge E2E logs in
  as Doctor A/B and a pending Doctor, not Patient/Pharmacy desktop binaries.

## Explicit status

Chunk 12 does not complete Phase 02.
Phase 02 remains **NOT PASS**.
This PR stays **Draft**. Independent review decides READY_TO_MERGE.
