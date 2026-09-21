# Phase 02 chunk 09 — Pharmacy verification UI surfaces (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** user-facing Pharmacy Electron onboarding/verification
and React Admin pharmacy queue/detail rendering on top of Chunks 07 and 08.
The synthetic flow is: pharmacy login + MFA → own organization onboarding →
open/resume verification case → main-owned opaque evidence selection →
main-owned signed-target upload → quarantine/scanning UX → submit → Admin
explicitly opens the pharmacy queue → claim → explicit document access →
approve/reject/changes-requested → Pharmacy refreshes authoritative status.

**Explicitly deferred:** Clinics / clinic locations / clinic staff, Doctor
Electron verification UX, Patient Flutter Phase-02 completion, additional
pharmacy branches, membership invitation/revocation, payment methods,
operating modes, Inventory, Purchasing, POS, Medication Catalog, public
listing, clinical capability, scheduling / Phase 03, external government
verification, external map/geocoding, generic filesystem/HTTP IPC, generic
role editor, inline Admin document viewer, production deployment, staging
provisioning.

**SF-001** remains unresolved / unaccepted (`MERGE_ONLY`,
`promotion_allowed=false`). `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.
ADR 0014 and G-08-04 are not closed by this slice. Staging `Deploy to staging`
remains fail-closed. This chunk does not bypass those gates.

- **Branch:** `cursor/phase-02-pharmacy-verification-ui-cc7f`
- **Base (GitHub `main` after merged PR #14):** `06a3f0551872f96ffd02093ce054a8486a612e37`
- **Recorded:** 2026-09-21
- **Environment:** host Node 22 workspace, PHP 8.3 Core Pest against local
  PostgreSQL `clinic_test`. Packaged Electron E2E and Admin Playwright run in
  GitHub `pull-request` CI on this HEAD.

## What was implemented

Pharmacy Electron replaces the signed-in Auth-only shell with a Phase-02
onboarding/verification workspace while keeping session controls, language
switching, health, and MFA. React Admin keeps the default queue on
`doctor_verification` and adds an explicit case-type selector for
`pharmacy_verification`.

`organization_registration_evidence` remains an **ENGINEERING_DEFAULT**
synthetic requirement. No test or UI copy claims government or license
verification.

## Pharmacy Electron IPC capabilities

Shared Auth/Platform channels remain on `ALL_CHANNELS` /
`CAPABILITY_REGISTRY` and are registered by both desktops.

Pharmacy domain channels live in
`packages/typescript/desktop_bridge_contracts/src/pharmacy.ts` and are
**not** members of `ALL_CHANNELS`. Executable registration is only in
`apps/pharmacy-desktop` (`PHARMACY_REGISTRY` =
`CAPABILITY_REGISTRY` ∪ `PHARMACY_CAPABILITY_REGISTRY`).

| Channel | Capability |
| --- | --- |
| `clinic:pharmacy.organization.getOwn` | GET own organization projection |
| `clinic:pharmacy.organization.onboard` | POST onboarding |
| `clinic:pharmacy.verification.openCase` | POST open/resume case |
| `clinic:pharmacy.verification.status` | GET verification status |
| `clinic:pharmacy.verification.submit` | POST submission |
| `clinic:pharmacy.evidence.select` | native file chooser → opaque handle |
| `clinic:pharmacy.evidence.clear` | invalidate handle |
| `clinic:pharmacy.evidence.upload` | main-owned create + PUT + complete |
| `clinic:pharmacy.upload.status` | GET upload projection |

There is no generic `invoke`, HTTP, filesystem, or upload primitive. The
renderer never receives bearer tokens, signed upload URLs, storage locators,
object IDs, or absolute paths.

## Doctor desktop isolation

`apps/doctor-desktop` still registers `REGISTERED_CHANNELS = ALL_CHANNELS`
only. Source tests assert Doctor `capabilities.ts`, `preload/index.ts`, and
`renderer/index.tsx` do not contain `clinic:pharmacy.`, `PHARMACY_CHANNELS`,
or `window.clinic.pharmacy`. Packaged-runtime E2E asserts
`window.clinic.pharmacy` is `undefined` when `CLINIC_DESKTOP_PRODUCT` is
Clinic Doctor, and is an object with the pharmacy methods when the product
is Clinic Pharmacy.

Unknown/unregistered channels remain unreachable: preload maps named methods
to constants; main validates sender, origin, size, and schema.

## Evidence-file handle lifecycle

`PharmacyEvidenceHandleStore` (main process):

1. Renderer requests `selectEvidence`.
2. Main opens `dialog.showOpenDialog` (PDF/JPEG/PNG, 20 MiB).
3. Main inspects with `lstat` + `realpath` + magic-byte sniff and stores the
   absolute path privately.
4. Renderer receives only `{ handleId, displayName, sizeBytes, candidateMediaType }`.
5. Handle is unguessable (`base64url` 24 random bytes), TTL 15 minutes,
   one-at-a-time, purpose-bound to pharmacy verification evidence.
6. `readForUpload` re-stats: deleted → `FILE_MISSING`; size/mtime/dev/ino/
   realpath/symlink/sniff change → `FILE_CHANGED`; directory/unsupported/
   oversize → `UNSUPPORTED_FILE`.
7. Successful upload invalidates the handle. Logout and window `closed`
   clear the store.

Bytes never cross IPC as a renderer payload.

## Signed upload target confinement

Main `uploadEvidence`:

1. `POST /api/v1/verification-uploads` with a main-owned idempotency key.
2. `parseIssuedUploadTarget` accepts only PUT, https (or unpackaged localhost
   HTTP), no credentials, no `file:`/`javascript:`, no Host/Connection/
   Transfer-Encoding headers.
3. `putIssuedUploadBytes` uses Electron `net.fetch` with `redirect: 'error'`
   and **without** the session Bearer header.
4. `POST /api/v1/verification-uploads/{id}/complete`.
5. Renderer receives only the safe upload projection (`uploadId`, state,
   rejectionReason, timestamps). Strict Zod rejects `upload_target`.

The target URL is not logged, not persisted, and not returned over IPC.

## Upload / scanner UX

Renderer polls `uploadStatus` every 2s while state is `requested`,
`uploading`, `quarantined`, `validating`, or `scanning`. Polling stops on a
terminal state, query disable, unmount, logout, or session failure. `complete`
is **not** treated as available. Submit stays disabled until server
`available` + `clean`. Status text is explicit (not color-only) with
`aria-live`.

## Idempotency / retry / reconciliation

`IntentKeyStore` lives in main. Same logical fingerprint reuses the key;
a changed payload mints a new key. Success (and some validation/conflict
outcomes) clear the key. Uncertain network outcomes keep the key so a retry
cannot create a second server intent. `VERSION_CONFLICT` / `STATE_CONFLICT`
refetch authoritative status and show a safe stale-state message. Bridge
errors are a closed taxonomy; raw backend bodies and stacks do not cross IPC.

Covered mutations: onboarding, open case, create upload, complete upload,
submit.

## Renderer persistence policy

Legal name, registration identifier, address, and phone exist only as
transient React state. They are cleared after a successful onboard (including
generic `manual_review_required`). Renderer source is forbidden from
`localStorage`, `sessionStorage`, `indexedDB`, authenticated `fetch`, and
token names. Packaged E2E asserts empty Chromium storage on boot.

## Onboarding and verification screen states

Onboarding: organization fields → initial branch/public location (Egypt
country locked, explicit copy that these fields become the stored branch
location, no map provider) → submit. Fail-closed unless `/me`
`account_type = pharmacy` (main + renderer). Duplicate detection stays
server-owned; `manual_review_required` is generic.

Verification: draft, upload in progress, quarantined, validating/scanning,
available, pending review, changes requested, rejected, approved (authoritative
org/branch/membership status), stale/version conflict, expired/rejected
upload, session expiration. Changes requested / rejected open a **new** case
via the server open/resume endpoint; the terminal historical case is not
mutated. No Inventory/POS/Purchasing/Catalog navigation.

## React Admin pharmacy queue / detail

- Default heading and query remain `doctor_verification`.
- Explicit Doctor / Pharmacy toggle. Changing type clears cursor pagination
  and uses a TanStack Query key that includes `caseType` so doctor/pharmacy
  result sets are not merged.
- Pharmacy rows/detail render only Chunk-08 safe fields (public name,
  verification/lifecycle status, initial branch public name/country/status,
  case status, assignment, submitted time, case version).
- Doctor rows, claim, `DocumentList`, signed document access, `DecisionForm`,
  version handling, and closed reason validation are unchanged.
- Pharmacy approval copy states the server is authoritative and this
  workspace does not activate operational capabilities. No optimistic
  active-state writes.

## Security canaries

Prohibited strings (legal name, registration, address, phone, coordinates,
absolute path, signed upload URL / query signature, storage locator, object
ID, reviewer notes, bearer/refresh tokens) are asserted absent from:

- Pharmacy IPC-safe projections and Zod schemas
- Pharmacy renderer DOM + `localStorage` / `sessionStorage` / cookies after
  onboard/upload transitions
- Bridge error serialization (no stacks, no target URLs)
- Admin queue/detail DOM
- Admin Playwright body after pharmacy claim/download/decision
- Packaged Chromium storage on boot

## Accessibility and Arabic/RTL

Keyboard-accessible wizard, explicit labels, status text plus color,
focus on error alerts, `aria-live` for upload/decision transitions, disabled
submit with a reason, `dir`/`lang` on `<html>` for Arabic. Packaged E2E still
asserts English → Arabic sets `dir=rtl`. Admin Arabic specialty/RTL tests
remain.

## Packaged Electron evidence

`tests/desktop-e2e/specs/packaged-runtime.spec.mjs` still covers privileged
custom origin, no Node globals, no generic invoke, navigation/new-window
denial, empty renderer storage, and Arabic RTL. This chunk adds pharmacy
bridge presence only on Clinic Pharmacy and absence on Clinic Doctor.

Windows/Linux/macOS packaged lanes are unchanged. Tests still run against
packaged artifacts via `scripts/desktop/run-packaged-e2e.mjs`, not
`electron-forge start`.

## Admin browser E2E

Existing doctor Playwright assertions remain (default heading “Pending
doctor verification”, claim, document access, decision). After that doctor
decision, the same reviewer session returns to the queue, switches to
Pharmacy verification, asserts canaries absent, claims, downloads, and
approves the seeded `E2E Pharmacy Review` case. Pharmacy coverage stays in
that one session so the reviewer TOTP is not replayed in the same 30s
window (`last_used_counter`). The browser seeder still creates the pending
pharmacy case.

## Local tests and counts

| Command | Result |
| --- | --- |
| `npm run typecheck --workspace apps/pharmacy-desktop` | passed |
| `npm run test --workspace apps/pharmacy-desktop` | **108 passed** (12 files) |
| `npm run typecheck --workspace apps/doctor-desktop` | passed |
| `npm run test --workspace apps/doctor-desktop` | **84 passed** (7 files) |
| `npm run test --workspace packages/typescript/desktop_bridge_contracts` | **3 passed** |
| `npm run admin:typecheck` | passed |
| `npm run admin:lint` | eslint `--max-warnings 0` passed |
| `npm run admin:test` | **46 passed** (9 files) |
| `npm run admin:build` | `tsc -b && vite build` passed |
| Core `pint --test` on touched PHP | passed |
| Core Pest `SeedAdminVerificationBrowserFixtureTest.php` | **3 passed**, 19 assertions |

GitHub `pull-request` CI on this HEAD is recorded after the Draft PR run
completes. Packaged Electron E2E and Admin Playwright are CI jobs.

Gitleaks, Trivy, and OpenVEX were **not** weakened. Contracts were not
changed in this chunk.

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- `organization_registration_evidence` remains ENGINEERING_DEFAULT, not a
  legal/regulatory catalogue.
- Clinics / location / staff foundation remains.
- Doctor Electron Phase-02 verification UX remains.
- Patient Flutter Phase-02 completion remains.
- Pharmacy membership invitation/revocation remains.
- Additional branch management remains.
- Pharmacy Phase-10 operational capability matrix remains.
- SF-001 remains MERGE_ONLY / production promotion blocked.
- G-08-04 / ADR 0014 / profile-claim gate remain unchanged.
- Staging remains unprovisioned.
- Pharmacy envelope key rotation remains deferred (`identity:rotate-keys`
  still does not rotate pharmacy legal-name / registration / address
  envelopes).
- Existing pharmacy subject-erasure lifecycle/documentation residual remains
  open; this UI chunk does not change erasure semantics.
- Document requirement and reason catalogues remain ENGINEERING_DEFAULT.
- Reviewer document TTL 120s and queue page size 25/100 remain
  ENGINEERING_DEFAULT.
- Signed GET URLs remain application-owned and bearer-style while valid.
