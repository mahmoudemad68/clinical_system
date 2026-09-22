# Phase 02 chunk 15 — Pharmacy branch and membership Electron UI (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** Pharmacy Electron Phase-02 additional-branch and
`branch_operator` membership UI on the already-merged Chunk 14 Pharmacies HTTP
contracts. Product flow for an authenticated, active, approved owning Pharmacy:

Pharmacy desktop login → MFA when required → Pharmacy workspace → Branches →
create an EG Cairo additional branch → authoritative list contains the branch →
open details → edit public name / address / coordinates with `expected_version`
→ second authorized client updates the same branch → Electron PATCH returns
`VERSION_CONFLICT` → Refresh latest loads N+1 without auto-resubmit → Team →
invite a `branch_operator` with a write-only phone → observe generic pending
invitation → the intended Pharmacy actor accepts through the existing Core
`AcceptPharmacyStaffInvitation` path (not a Pharmacy renderer action) → owner
clicks Refresh memberships → active `branch_operator` is visible → owner
confirms revoke → revoked state is visible.

**Recipient acceptance UI is deferred.** Chunk 14 sends no SMS/email and there
is no recipient-side pending-invitation list API. This chunk does not invent
an invitation-ID text box, generic invite lookup, recipient search, or fake
acceptance workflow in the Pharmacy renderer. Owner E2E acceptance uses the
Core test helper `apps/core-api/tests/Support/bin/accept-chunk15-pharmacy-invitation.php`.

**Explicitly deferred / still open:**

- Payment-method metadata
- Public branch directory/listing
- Pharmacy broad erasure lifecycle residual
- Identity key-rotation command coverage for Pharmacy protected columns
- Revoked `branch_operator` re-invitation limitation (UNIQUE org+user includes
  revoked rows; Chunk 14 residual)
- DEF-SEC-MFA-001
- SF-001
- G-08-04 / ADR 0014 / profile-claim
- Staging provisioning
- Phase 03
- Phase 10 inventory, POS, purchasing, catalog, operating modes, Pharmacy Home,
  and the OWNER/PHARMACIST/CASHIER/CONNECTOR role matrix

`designs/**` was not modified. No Pharmacy backend business-rule change. Three
Core test helpers seed/accept/bump synthetic actors for Forge GUI E2E only.

Chunk 14 is CLOSED (merged PR #24). **Phase 02 remains NOT PASS.**

- **Branch:** `cursor/phase-02-chunk-15-pharmacy-branch-membership-ui-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/25
- **Baseline (GitHub `main`):** `cc8f9be7df683624ed9323f2dd357fadc9bc7d13`
- **CI-proven implementation HEAD:** `0dd78616885947bd00830996cac7c7b5bc572ea3`
  (`pull-request` run **35766687221** SUCCESS)
- **Evidence HEAD (GitHub CI SUCCESS):** `f2304fee104f0f772f03cdc5ccef47e154028a1d`
  (`pull-request` run **35767496247** SUCCESS)
  https://github.com/mahmoudemad68/clinical_system/actions/runs/35767496247
  — 17 success, 1 skipped (AI service path filter). Forge Pharmacy practice
  E2E passed with `skipped=false` and all mandatory journey booleans true.

Do **not** mark READY_TO_MERGE from this note. Independent review decides that.

- **Recorded:** 2026-09-22
- **Environment:** host Node 22 workspace, PHP 8.3 Core against local
  PostgreSQL `clinic_test`. Forge Pharmacy GUI uses live Core at
  `http://localhost:8080`. Packaged Electron E2E is the GitHub
  `desktop-packaged-e2e` matrix on the same implementation HEAD; packaged
  Pharmacy does not connect to local HTTP (same packaged-origin policy as
  Doctor). Dedicated branch-management GUI E2E runs on Ubuntu CI with
  `CLINIC_REQUIRE_PHARMACY_PRACTICE_E2E=1`. Cross-OS packaged smoke/security
  remains required on ubuntu/macos/windows.

## Baseline

`origin/main` at start of this chunk was
`cc8f9be7df683624ed9323f2dd357fadc9bc7d13` (merge of PR #24, Chunk 14 Pharmacy
branch/membership foundation). Chunk 14 is CLOSED. Work started on a new
branch from that commit. No prior Phase-02 feature branch was continued.
`designs/**` was not modified.

## What was implemented

Pharmacy Electron adds a Phase-02 practice workspace while keeping session
controls, language switching, health, MFA, and the existing onboarding /
verification surfaces. Branch-management navigation is shown only when
authoritative `/me` plus own Pharmacy organization report:

- `accountType === 'pharmacy'`
- `status === 'active'`
- `assuranceLevel` in `aal2_totp` | `aal2_recovery_code`
- organization `membership.role === 'owner'` and `membership.status === 'active'`
- organization `verificationStatus === 'approved'` and `status === 'active'`

Capability strings such as `pharmacies.branch.write` are not treated as a
client grant. Hidden UI is not authorization; Core still enforces owner /
approved pharmacy / BOLA rules. Pending / changes_requested / rejected
organizations remain on the existing verification workflow.

No Pharmacies backend business-rule change.

## Pharmacy routes / screens

Hash routes (address and invitation/branch-contact phone are never placed in
the URL):

| Route | Screen |
| --- | --- |
| `#/practice/branches` | Authoritative branch list |
| `#/practice/branches/new` | Create additional branch |
| `#/practice/branches/:id` | Branch details (Details tab) |
| `#/practice/branches/:id/location` | Branch edit (coordinates confirmed locally) |
| `#/practice/branches/:id/team` | Team invite + memberships |

Phase 02 navigation is **Verification** and **Branches** only. Details,
Location, and Team tabs are the only branch surfaces. There is no Inventory,
POS, Purchasing, Catalog, Alerts, Sales, AI, NATIVE/INTEGRATED, or Pharmacy
Home teaser. `PHASE_10_HASH_FRAGMENTS` parse as home.

Recipient invitation acceptance remains out of the renderer.

## Bridge operations added

Pharmacy-only IPC in `packages/typescript/desktop_bridge_contracts/src/pharmacy.ts`.
Doctor does not register these channels. There is no generic `invoke`.

| Channel | Operation |
| --- | --- |
| `clinic:pharmacy.branches.list` | `listBranches({ cursor?, limit? })` |
| `clinic:pharmacy.branch.create` | `createBranch({ publicName, address, countryCode: 'EG', latitude, longitude, phone })` |
| `clinic:pharmacy.branch.get` | `getBranch({ branchId })` |
| `clinic:pharmacy.branch.update` | `updateBranch({ branchId, expectedVersion, ... })` |
| `clinic:pharmacy.branch.inviteOperator` | `inviteOperator({ branchId, phone })` |
| `clinic:pharmacy.branch.memberships` | `listMemberships({ branchId })` |
| `clinic:pharmacy.branch.revokeMembership` | `revokeMembership({ branchId, membershipId })` |

`organizationId` is resolved in the main-process gateway from
`GET /api/v1/pharmacy-organizations/me`. The renderer never supplies
organization IDs, roles, statuses, capabilities, URLs, methods, or headers.

## Gateway mappings

`apps/pharmacy-desktop/src/main/pharmacy-gateway.ts` maps Chunk 14 snake_case
envelopes to renderer-safe camelCase views:

- Branch private view: `branchId`, `publicName`, `countryCode`, `status`,
  `version`, `createdAt`, `updatedAt`, optional owner `address` / `latitude` /
  `longitude`. Phone, phone HMAC, ciphertext, key version, user IDs, and
  `organization_id` are stripped.
- Create result: `{ branchId, status, version }` (compact Core create).
- Membership: `membershipId`, `role: 'branch_operator'`, `status`, `version`,
  `invitedAt`, `acceptedAt`, `revokedAt`. No phone, user_id, or owner rows.
- Invite: `{ invitationId, status, expiresAt, existingPending }` where
  `existingPending` is HTTP 200 (reconciled pending) vs 201 (created).

Errors use `GatewayError` codes only. Backend exception/SQL/phone text does
not reach the renderer.

## Owner gating result

Approved active owner + AAL2 sees Verification | Branches. Pending / rejected /
non-pharmacy accounts do not. UI visibility is not authorization.

## Branch list / create / edit

List comes from `GET /pharmacy-organizations/{id}/branches` and shows public
name, country, status, version. Selection is from server-returned cards only
(no free-form Branch ID). Switching branch remounts detail/team state.

Create form: public name, address, Egypt/EG, latitude, longitude, write-only
phone. No status, verification, ownership, version override, operating mode,
inventory, POS, or role fields. Manual coordinate confirmation; no geocoding
claim.

Edit sends `expected_version` from the loaded projection. Phone is write-only
replacement input and is never prefilled.

## Branch-create idempotency

Main-process `IntentKeyStore` intent `pharmacy.branch.create`. Fingerprint is
`{organizationId, publicName, countryCode, latitude, longitude}` — not phone
or address. Same logical create reuses the key across uncertain retry.
Materially changed name/coords mint a new key. The key is retired on delivered
success or `VALIDATION_FAILED` / `PERMISSION_DENIED`. Timeout/upstream keeps
the key.

## Invite idempotency

Intent `pharmacy.branch.inviteOperator`. Fingerprint is
`{organizationId, branchId, phone}` in memory only. Plaintext phone is not
logged. Same logical invite after timeout reuses the key.

## Real VERSION_CONFLICT

Stale PATCH with version N after a second authorized client wrote N+1 maps
Core 409 `VERSION_CONFLICT` to a visible `role="alert"` with **Refresh
latest**. The renderer does not retry, does not last-write-wins, and does not
auto-resubmit after refresh.

## Invite / phone privacy / pending replay

Invite form accepts phone only. Fixed copy: `Role: branch_operator`. No role
dropdown. After terminal response the phone input is cleared, never echoed
into the membership table, route, hash, query, or `localStorage`. Generic
success: “Invitation pending.” HTTP 200 replay: “Invitation already pending.”
No disclosure of whether an account exists.

## Membership refresh after real acceptance

Owner invites → Core helper accepts → owner Refresh memberships → active
`branch_operator` row. E2E does not insert membership rows for the primary
journey.

## Revoke

Explicit confirmation dialog. Founding owner is not listed (Chunk 14 does not
return owner as `branch_operator`). After DELETE the UI refetches; revoked
history remains if Core returns it.

## Logout / account isolation

Logout clears TanStack Query, practice hash, and main-process Pharmacy intents
(`clearPharmacySession`). Owner A → Owner B must not flash A’s
organization/branch/address/memberships/drafts.

## Arabic / RTL / accessibility

Complete EN+AR strings for the new surfaces. `dir=rtl` via existing locale
effect and MUI theme direction. Keyboard-reachable controls, labeled fields,
visible validation/conflict alerts, dialog `autoFocus` on confirm, status not
color-only, primary actions remain at 200% text size.

## IPC / security boundary

Pharmacy renderer can invoke only registered Pharmacy operations. Doctor
`DOCTOR_ALL_CHANNELS` does not contain the new channels. Shared `ALL_CHANNELS`
does not. Unknown channels, oversized/malformed payloads, and role/status mass
assignment are rejected. No generic invoke.

## No Phase-10

No inventory/POS/purchasing/catalog/alerts/sales/AI/Home navigation or teaser
cards. Trust-boundary tests forbid `inventory-nav`, `pos-nav`, `pharmacist`,
and `cashier` in the Pharmacy renderer.

## Core-backed Electron E2E evidence

Evidence path: `tests/desktop-e2e/logs/pharmacy-forge-practice-e2e.json`

Required CI flag: `CLINIC_REQUIRE_PHARMACY_PRACTICE_E2E=1`
(`CI=true` alone does not enable required mode).

Mandatory fields:

```json
{
  "kind": "forge-development-pharmacy-practice-e2e",
  "skipped": false,
  "core_health": "operational",
  "branch_created": true,
  "branch_edited": true,
  "version_conflict": true,
  "invited": true,
  "accepted": true,
  "membership_visible": true,
  "revoked": true,
  "account_isolation": true,
  "phone_absent_after_submit": true,
  "no_phase10_navigation": true,
  "no_generic_invoke": true
}
```

`scripts/desktop/assert-forge-pharmacy-practice-e2e-evidence.mjs` fails if
`skipped !== false` or any mandatory boolean/`core_health` is missing/false.

The dedicated GitHub job `desktop-pharmacy-practice-e2e` provisions
PostgreSQL/PostGIS, Redis, Core migrations, Core HTTP on
`http://localhost:8080`, Pharmacy Forge, and a Linux GNOME/libsecret keystore.

Artifact `chunk-15-pharmacy-practice-e2e` from run **35766687221**
(`tests/desktop-e2e/logs/pharmacy-forge-practice-e2e.json`):

```json
{
  "kind": "forge-development-pharmacy-practice-e2e",
  "skipped": false,
  "required": true,
  "apiBase": "http://localhost:8080",
  "coreApi": {
    "reachable": true,
    "status": 200,
    "health": "operational"
  },
  "core_health": "operational",
  "branch_created": true,
  "branch_edited": true,
  "version_conflict": true,
  "invited": true,
  "accepted": true,
  "membership_visible": true,
  "revoked": true,
  "account_isolation": true,
  "phone_absent_after_submit": true,
  "no_phase10_navigation": true,
  "no_generic_invoke": true
}
```

CI also printed `Pharmacy practice E2E evidence executed (skipped=false)`.

Packaged Electron E2E remains the ubuntu/macos/windows matrix (startup,
sandbox/CSP, credential transport, existing verification). Full new
branch-management GUI E2E is the Ubuntu Forge job; that is documented, not a
silent skip. The dedicated E2E cannot pass with `skipped=true` in CI.

## Exact changed files

Relative to `cc8f9be7df683624ed9323f2dd357fadc9bc7d13`:

```
.github/path-filters.yaml
.github/workflows/pull-request.yaml
apps/core-api/tests/Support/bin/accept-chunk15-pharmacy-invitation.php
apps/core-api/tests/Support/bin/bump-chunk15-pharmacy-branch.php
apps/core-api/tests/Support/bin/seed-chunk15-pharmacy-practice.php
apps/pharmacy-desktop/src/main/capabilities.ts
apps/pharmacy-desktop/src/main/pharmacy-gateway.test.ts
apps/pharmacy-desktop/src/main/pharmacy-gateway.ts
apps/pharmacy-desktop/src/main/platform-gateway.ts
apps/pharmacy-desktop/src/preload/index.ts
apps/pharmacy-desktop/src/renderer/App.test.tsx
apps/pharmacy-desktop/src/renderer/App.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/BranchCreate.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/BranchDetails.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/BranchForm.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/BranchList.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/CoordinatePreview.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/PharmacyPractice.test.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/PracticeWorkspace.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/TeamSection.tsx
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/egyptCoordinates.ts
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/eligibility.ts
apps/pharmacy-desktop/src/renderer/features/pharmacy-practice/practiceRoute.ts
apps/pharmacy-desktop/src/renderer/strings.ts
apps/pharmacy-desktop/src/shared/content-security-policy.test.ts
apps/pharmacy-desktop/src/shared/content-security-policy.ts
apps/pharmacy-desktop/src/shared/trust-boundary.test.ts
apps/pharmacy-desktop/src/renderer/index.html
apps/pharmacy-desktop/webpack.renderer.config.ts
apps/pharmacy-desktop/forge.config.ts
apps/pharmacy-desktop/src/main/index.ts
docs/evidence/phase-02/chunk-15-pharmacy-branch-membership-ui.md
package.json
packages/typescript/desktop_bridge_contracts/src/bridge-contracts.test.ts
packages/typescript/desktop_bridge_contracts/src/index.ts
packages/typescript/desktop_bridge_contracts/src/pharmacy.ts
scripts/desktop/assert-forge-pharmacy-practice-e2e-evidence.mjs
scripts/desktop/forge-pharmacy-practice-e2e.test.mjs
scripts/desktop/run-forge-pharmacy-practice-e2e.mjs
scripts/desktop/with-linux-os-keystore.sh
tests/desktop-e2e/specs/packaged-runtime.spec.mjs
```

`designs/**` was not modified.

## Local test counts

Host Node 22 / PHP 8.3, this agent:

| Suite | Result |
| --- | --- |
| `apps/pharmacy-desktop` Vitest | 15 files, **165 passed** |
| `apps/doctor-desktop` Vitest (regression) | 15 files, **182 passed** |
| `@clinic/desktop-bridge-contracts` | 1 file, **7 passed** |
| `npm run packages:test` | admin 46, doctor 182, pharmacy 165, api-client 2, bridge 7, encrypted-local-store 16, error-handling 3, localization 2 — all passed |
| `node --test scripts/desktop/forge-pharmacy-practice-e2e.test.mjs` | **6 passed** |
| `npm run typecheck` pharmacy + doctor | pass |
| PHP lint of three Chunk 15 helpers | pass |

Dedicated Core-backed Forge Pharmacy practice E2E ran in GitHub job
`desktop-pharmacy-practice-e2e` on runs **35766687221** (`0dd7861`) and
**35767496247** (`f2304fe`) (required mode, `skipped=false`).

## Exact final-head GitHub CI

Evidence HEAD `f2304fee104f0f772f03cdc5ccef47e154028a1d` has GitHub
`pull-request` run **35767496247** SUCCESS
(https://github.com/mahmoudemad68/clinical_system/actions/runs/35767496247).

Implementation HEAD `0dd78616885947bd00830996cac7c7b5bc572ea3` has GitHub
`pull-request` run **35766687221** SUCCESS
(https://github.com/mahmoudemad68/clinical_system/actions/runs/35766687221).

Both runs: 17 success, 1 skipped (AI service). Forge Pharmacy practice E2E
required-mode passed (`skipped=false`). Packaged Electron E2E passed on
ubuntu/macos/windows.

| Job | 35766687221 (`0dd7861`) | 35767496247 (`f2304fe`) |
| --- | --- | --- |
| Detect changed areas | success | success |
| Supply-chain policy | success | success |
| Security scans | success | success |
| Contracts | success | success |
| Core API | success | success |
| Electron desktops | success | success |
| Admin web | success | success |
| Secure-file providers | success | success |
| Forge Pharmacy practice E2E | success | success |
| Forge Doctor practice E2E | success | success |
| Packaged Electron E2E (ubuntu-latest) | success | success |
| Packaged Electron E2E (macos-latest) | success | success |
| Packaged Electron E2E (windows-latest) | success | success |
| Flutter | success | success |
| Flutter Patient profile E2E | success | success |
| Runtime image scan (core-api) | success | success |
| Runtime image scan (ai-service) | success | success |
| AI service | skipped | skipped |

Earlier on this PR:

- `02d52e4bd2919e0b4e9b11c3e8ec1522648127bd` (**35761681211**) failed Forge
  Pharmacy practice E2E: packaged CSP overwrote Forge webpack-dev-server
  (`connect-src 'none'` + missing `'unsafe-eval'`), blanking the login shell.
- `c57c2b848f9a6b46d7b93e2f38db49eedaecaa68` (**35762899892**) mounted the
  login shell; the owner journey completed, then doctor MFA wait failed
  because `pharmacy_desktop` is not compatible with doctor accounts
  (`AuthenticationFailed` / client mismatch). Fixed in `0dd7861`.

This SHA-recording commit follows `f2304fe` and does not change product
code. Chunk-only evidence. Phase 02 is **NOT PASS**. Independent review
decides `READY_TO_MERGE`. This chunk does not mark READY_TO_MERGE and does
not merge.

## Remaining risks

- Forge development previously overwrote webpack-dev-server CSP with the
  packaged `connect-src 'none'` / `script-src 'self' clinic-pharmacy-app:`
  policy and used eval-source-map, which blanked the login shell (CI run
  35761681211). Pharmacy now matches Doctor: `devtool: 'source-map'`,
  development CSP without `'unsafe-eval'`, packaged CSP only when packaged.
- Coordinate confirmation is a local checkbox plus numeric fields; there is no
  approved map provider.
- Invitation phone is write-only in the UI, but main-process idempotency
  fingerprints still hash the phone in memory (never logged).
- Recipient acceptance UI remains deferred (no pending-invitation list API,
  no SMS/email).
- Revoked `branch_operator` cannot be re-invited while UNIQUE(org, user)
  includes revoked rows (Chunk 14 residual).
- Patient and hypothetical authenticated non-pharmacy sessions are
  renderer-tested (`account-denied`). Forge E2E proves a real Doctor password
  against Pharmacy Electron cannot mint a `pharmacy_desktop` session: Core
  `ClientClass::compatibleWith` fails closed before MFA, and the login form
  shows `login-error` with no workspace, practice nav, or MFA.

## Explicit status

Chunk 15 does not complete Phase 02.
Phase 02 remains **NOT PASS**.
Inventory, POS, purchasing, catalog, operating modes,
the Phase-10 role matrix, and Pharmacy Home remain out of scope.
This PR stays **Draft**. Independent review decides READY_TO_MERGE.
