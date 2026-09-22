# Phase 02 chunk 14 — Pharmacy branch and membership foundation (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** Phase-02 Pharmacies backend/contracts foundation for
an approved pharmacy owner to create an additional branch, list/read own
branches, PATCH location/profile with optimistic concurrency, invite one
fixed-scope `branch_operator`, accept through the intended Pharmacy actor,
list branch memberships, and revoke `branch_operator` membership. Persistence
and business logic stay inside `Modules/Pharmacies`. OpenAPI is updated.
Pharmacy Electron additional-branch and membership UI is **not** implemented.

**Explicitly deferred / still open:**

- Pharmacy Electron additional-branch UI (Chunk 15)
- Pharmacy Electron membership-management UI (Chunk 15)
- Payment-method metadata
- Public branch directory/listing
- Pharmacy broad erasure lifecycle residual
- Identity key-rotation command coverage for Pharmacy protected columns
- DEF-SEC-MFA-001
- SF-001
- G-08-04 / ADR 0014 / profile-claim
- Staging provisioning
- Phase 03
- Phase 10 inventory, POS, purchasing, catalog, operating modes, and the
  OWNER/PHARMACIST/CASHIER/CONNECTOR role matrix

**SF-001** remains unresolved / unaccepted (`MERGE_ONLY`,
`promotion_allowed=false`). `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.
Staging `Deploy to staging` remains fail-closed. This chunk does not bypass
those gates.

Chunk 13 is CLOSED (merged PR #23). **Phase 02 remains NOT PASS.**

- **Branch:** `cursor/phase-02-chunk-14-pharmacy-branch-membership-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/24
- **Base (GitHub `main` after merged PR #23 / Chunk 13):**
  `bdf9da87cee59597fa27a959e0d1d03379c4640a`
- **Implementation SHA (pre-evidence):**
  `2eaeaadd17e6ed83cef28279beb63df2d59553ea`
- **Recorded:** 2026-09-22
- **Environment:** host PHP 8.3 with `pdo_pgsql`, PostgreSQL + PostGIS
  `clinic_test`, role `clinic_migrator`. No Docker Compose. Redis/MinIO were
  not required for this backend slice. Live local clamd on `:3310` remains
  the known EICAR-signature miss recorded in Chunk 11.

## Baseline verification

Work started from GitHub `main` at
`bdf9da87cee59597fa27a959e0d1d03379c4640a`. Chunk 13 is merged. No prior
Phase-02 feature branch was continued.

`designs/07_pharmacy_setup_and_inventory.md` was inspected read-only for
Screen 4 memberships/branch roles at the Phase-02 foundation level. The
Phase-10 role matrix was not implemented. `designs/**` was not modified.

## What was implemented

Authenticated approved pharmacy owner (privileged MFA / AAL2) → create Cairo
additional branch (server-owned `active`) → private GET (decrypted address +
coordinates; phone omitted) → PATCH with `expected_version` → invite
`branch_operator` by phone → intended Pharmacy actor accepts → exactly one
active organization-scoped membership on that branch → owner lists
memberships → other owner denied → revoke → membership no longer resolves
active → audit + outbox facts only.

Pending/rejected/changes_requested/suspended/closed organizations, Patient,
Doctor, anonymous, and `branch_operator` cannot create additional branches.
Owner A cannot GET/PATCH/invite/list/revoke Owner B resources (404). Clients
cannot submit organization ownership, status, version override, actor IDs,
operating mode, inventory/POS flags, or arbitrary roles.

## Architecture

All persistence and workflows remain in `Modules/Pharmacies`. No second
organization/tenancy module. Pharmacies does not import Verification,
Identity tables, Auth tables, or another module's Eloquent models.

**Allowed Deptrac direction (unchanged):** Pharmacies → Platform, Identity,
Access, Audit, Framework.

`VerificationService::pharmacyApplicantStatusForOrganization` lives in
Verification so Pharmacies stays free of Verification imports. The HTTP
route is owner-scoped and reuses the existing applicant projection.

ArchitectureBoundaryTest allowlists `pharmacy_staff_invitations` next to the
existing pharmacy tables. Deptrac: 0 violations, 3108 allowed.

Transaction/idempotency/audit/outbox conventions match Chunk 07 and Clinics
Chunk 10: `TransactionRunner`, `AppendAuditEvent`, `tx->recordEvent`,
platform `EnforceIdempotency` (255-byte compact create pointer),
`pg_advisory_xact_lock` on HMAC candidates.

## Additional-branch authorization rule

Server-authoritative. Creating or managing additional branches and
memberships requires all of:

1. authenticated `AccountType::Pharmacy` that `canAccessBusinessEndpoints()`
2. `assuranceLevel->satisfiesPrivilegedSession()` (AAL2)
3. Access capability for the specific action (`pharmacies.branch.write`,
   `pharmacies.branch.read_own`, `pharmacies.staff.invite`,
   `pharmacies.membership.read_own`, `pharmacies.membership.revoke`)
4. active founding `owner` membership for **this exact** organization
5. organization `verification_status = approved`
6. organization `status = active`
7. for branch-scoped writes: the branch belongs to that organization and is
   not `closed` or `suspended`

A capability name in `/me/capabilities` is never treated as proof of
ownership. `PharmacyOwnerGuard` additionally checks actor type, approval,
and exact resource relationship. Clients cannot supply a role.

Pending professional accounts are denied (`pendingForbidden` in
`DefaultDenyAuthorizer`). Cross-organization IDs return non-enumerating 404
(`AuthorizationDenied` / `FeatureUnavailable` → `ErrorCode::NotFound`).

GET one branch additionally allows an active `branch_operator` of that
exact branch (staff-safe projection: no address, phone, or coordinates).
List, create, PATCH, invite, membership list, and revoke remain owner-only.

## Additional-branch initial-status rule

The repository contains **no** configured additional-branch verification
requirement and **no** second pharmacy verification case type. This chunk
did not fabricate document requirements.

Additional branches inherit the already-approved organization identity.
Required location/contact fields must be valid (`public_name`, protected
address, `country_code=EG`, legal in-Egypt coordinates, protected phone).
Branch `status` is server-owned **`active`** on successful create.

`active` means Phase-02 location/contact readiness only. It is **not** a
Phase-10 inventory, POS, purchasing, catalog, or operating-mode grant.
`PharmacyBranchStatus::confersBusinessCapability()` remains false for every
case, including `active`. `branch_operator` remains
`confersBusinessCapability() = false`.

Reserved server-owned states (`draft`, `pending`, `suspended`, `closed`)
are not client-writable. Clients cannot submit status.

## Endpoints added

Organization-scoped routes (preferred pharmacy shape; Clinics remains
doctor-scoped `/clinic-locations` because clinic locations are owned by a
doctor, not an organization aggregate):

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/api/v1/pharmacy-organizations/{organization_id}/branches` | Idempotency-Key; compact `{branch_id,status,version}` |
| GET | `/api/v1/pharmacy-organizations/{organization_id}/branches` | Owner private list |
| GET | `/api/v1/pharmacy-organizations/{organization_id}/branches/{branch_id}` | Owner private or operator staff-safe |
| PATCH | `/api/v1/pharmacy-organizations/{organization_id}/branches/{branch_id}` | `expected_version` required |
| POST | `/api/v1/pharmacy-organizations/{organization_id}/branches/{branch_id}/staff-invitations` | Idempotency-Key; body `{phone}` only |
| GET | `/api/v1/pharmacy-organizations/{organization_id}/branches/{branch_id}/memberships` | `branch_operator` rows only |
| DELETE | `/api/v1/pharmacy-organizations/{organization_id}/branches/{branch_id}/memberships/{membership_id}` | `branch_operator` only |
| POST | `/api/v1/pharmacy-staff-invitations/{invitation_id}/accept` | Idempotency-Key; empty closed body |
| GET | `/api/v1/pharmacy-organizations/{organization_id}/verification-status` | Owner-scoped reuse of existing projection |

There is **no** public branch lookup/search. There is **no** broader
GET-by-organization-id organization endpoint. Invitation URLs contain only
the invitation UUID.

OpenAPI operation IDs: `createPharmacyBranch`, `listOwnPharmacyBranches`,
`getOwnPharmacyBranch`, `updatePharmacyBranch`, `invitePharmacyStaff`,
`listPharmacyBranchMemberships`, `revokePharmacyBranchMembership`,
`acceptPharmacyStaffInvitation`, `getPharmacyOrganizationVerificationStatus`.
Request objects use `additionalProperties: false`.

## Migrations / tables added

`apps/core-api/database/migrations/2026_09_22_140000_create_pharmacy_staff_invitations_table.php`

New table `pharmacy_staff_invitations`:

- UUIDv7 `id`
- `organization_id`, `branch_id`
- `role` CHECK `= branch_operator` (server-written; no API role selector)
- `status` CHECK `pending|consumed|expired|cancelled`
- `target_phone_lookup_hmac` + `target_phone_key_version` (Identity HMAC;
  no plaintext phone as lookup key)
- `expires_at`, `invited_at`, `accepted_at`, `consumed_at`
- `inviter_user_id`
- `version >= 1`

Constraints:

- FK `organization_id → pharmacy_organizations(id)`
- composite FK `(organization_id, branch_id) → pharmacy_branches(organization_id, id)`
- FK `inviter_user_id → users(id)`
- partial unique pending
  `(organization_id, branch_id, target_phone_lookup_hmac) WHERE status = pending`

Existing `pharmacy_organizations`, `pharmacy_branches`, and
`pharmacy_memberships` are reused. `UNIQUE (organization_id, user_id)` on
memberships is **not** weakened. The existing composite membership FK is
unchanged. Branch transfer is out of scope.

Least privilege: `clinic_app` SELECT/INSERT/UPDATE/DELETE;
`clinic_worker` / `clinic_reporter` none; `clinic_backup` SELECT.
`clinic_worker` / `clinic_audit_writer` privileges were not changed.

## Branch create / read / update

Allowed create fields: `public_name`, `address`, `country_code`, `latitude`,
`longitude`, `phone`. Organization scope comes from the route + authenticated
owner context.

Create result: HTTP 201 compact `{branch_id, status=active, version=1}`.
Address and phone are encrypted with existing Identity purposes
(`physical_address`, `phone`). Location stored as `geography(Point, 4326)`
with the existing GiST index `pharmacy_branches_geography_point_gix`.

Owner GET includes `branch_id`, `organization_id`, `public_name`,
`country_code`, decrypted `address`, `latitude`, `longitude`, `status`,
`version`, `created_at`, `updated_at`. Phone is write-only / omitted.
Ciphertext, HMAC, key versions, and user ids are never returned.

Operator GET omits address and coordinates.

Idempotency: same Idempotency-Key + same body → original 201 replay
(`meta.idempotent_replay`) and still exactly one additional branch (founding
branch + one extra). Same key + different body remains platform
`IDEMPOTENCY_KEY_REUSED`.

PATCH requires `expected_version`. Allowed editable fields only:
`public_name`, `address`, `country_code`, `latitude`, `longitude`, `phone`,
`expected_version`. Successful update increments version. Stale write
returns **409 `VERSION_CONFLICT`**. No last-write-wins. No client status
change.

## PostGIS / Egypt

Reuse of Chunk 07 `PharmacyCoordinates::assertEgyptServiceArea`. ENGINEERING_DEFAULT
bbox: latitude 22.0–31.7, longitude 24.7–36.9. `country_code` must be `EG`.

Rejected without clamping or rewrite:

- Paris coordinates (48.8566, 2.3522) → 422, no extra row
- `country_code=SA` → 422, no extra row

Proof in
`PharmacyAdditionalBranchFlowsTest`:
`ST_X`/`ST_Y`/`ST_SRID` = (31.2357, 30.0444, 4326); GiST `USING gist`;
`EXPLAIN ST_DWithin` plan is non-empty. No Google Maps / Mapbox / OSM.

## Invitation recipient protection

Invite body is `{phone}` only. Role is server-written `branch_operator`.
Identity `InvitationRecipientService::bindPhone` produces the HMAC binding.
Responses contain only `invitation_id`, `status`, `expires_at`. Phone is
not echoed. The API does not disclose whether the phone belongs to an
account.

HMAC-rotation-safe dedup: `orderedLookupHmacs()` candidates are locked in
stable order via `pg_advisory_xact_lock` **before** pending lookup. A live
pending row stored under HMAC v1 is replayed after switching
`identity.hmac.current_version` to 2; no second pending row is inserted.
An expired v1 row is marked `expired` and replaced under the current HMAC.

Same organization + branch + intended recipient + `branch_operator` while a
valid pending invitation exists converges to one pending row.

Acceptance proves: authenticated Pharmacy actor, Access
`pharmacies.staff.accept`, HMAC match via
`actorMatchesInvitationHmac`, invitation pending and unexpired, branch still
belongs to the organization, organization still approved/active, branch not
closed/suspended. Creates exactly one `pharmacy_membership`
`role=branch_operator` `branch_id=invitation.branch`. No org/branch input
on the accept body (`additionalProperties: false` empty object).

Wrong recipient, expired, cancelled, consumed (replay with a new
Idempotency-Key), and forged `branch_id`/`role` in the accept body are
denied (404 or 422). Replay of the same Idempotency-Key after success is
platform replay of the original 200.

`UNIQUE (organization_id, user_id)`: a live same-branch `branch_operator`
acceptance converges; any other same-org membership (owner, other branch,
or a revoked row that still occupies the unique key) returns **409
`STATE_CONFLICT`**. The user is not silently moved. Founding `owner` cannot
be created through this path. PostgreSQL CHECK `role = branch_operator`
blocks an invitation-row owner.

Re-invite of a previously revoked operator to the same organization is
blocked by the existing unique membership key (revoked rows are retained).
That is current V1 semantics, not weakened here. Branch transfer remains
out of scope.

## Membership list / revoke

List projection: `membership_id`, `branch_id`, `role`, `status`, `version`,
`invited_at`, `accepted_at`, `revoked_at`. No phone, HMAC, National ID,
email, `user_id`, credentials, or inventory permissions. Founding owner
membership does not appear as a `branch_operator` row.

Revoke: only `branch_operator`. Founding owner membership on this route is
404. Status becomes `revoked`, `revoked_at` is server time, `revoker_user_id`
is the server actor, `version += 1`. History stays (no hard delete).
Repeated revoke returns the stable revoked state **without version churn**.
`ResolveActivePharmacyMembership` returns null immediately. Operator GET of
the branch after revoke is 404. There are no inventory/POS capabilities to
revoke. No realtime infrastructure was added.

## Events / audit

Events (IDs/state only; schema v1; `additionalProperties: false`):

- `pharmacy.branch_changed` — `{branch_id, organization_id, version, change_type}`
- `pharmacy.membership_changed` — `{membership_id, scope_type, scope_id, status}`

Audit names: `pharmacy.branch_created`, `pharmacy.branch_updated`,
`pharmacy.staff_invitation_created`, `pharmacy.staff_invitation_accepted`,
`pharmacy.staff_membership_revoked`. Metadata is IDs/state/`reason_code`
only.

Canary proof: synthetic address/phone never appear in HTTP create body
(compact), logs (`RedactingLogTap` + `TestHandler`), audit metadata, or
outbox payloads. Coordinates `30.0444` do not appear in the create outbox
payload. Invitation phone / `target_phone_lookup_hmac` do not appear in
invite HTTP, membership list, audit metadata, or outbox payloads.

## Transaction rollback

`FailOnceAppendAuditEvent` collaborator:

| Transition | Audit name | Rolled back |
| --- | --- | --- |
| additional branch create | `pharmacy.branch_created` | extra branch row; `pharmacy.branch_changed` outbox |
| invitation issue | `pharmacy.staff_invitation_created` | invitation row |
| invitation accept | `pharmacy.staff_invitation_accepted` | membership; invitation stays `pending`; membership outbox |
| membership revoke | `pharmacy.staff_membership_revoked` | status stays `active`; version stays 1; no extra membership outbox |

## Concurrency (real PostgreSQL)

| Race | Result |
| --- | --- |
| Same Idempotency-Key concurrent additional-branch create | one extra branch (founding + 1); statuses include 201 |
| Concurrent PATCH `expected_version=1` | one 200, one 409; version = 2 |
| Concurrent invite same org+branch+phone | one pending invitation |
| Concurrent accept | one active `branch_operator`; invitation `consumed` |
| Concurrent revoke | one revoked membership; version = 2 |

## BOLA / BFLA

Covered in additional-branch flows, invitation flows, and synthetic E2E:

- Owner A cannot list/read/update Owner B branch (404)
- Owner A cannot invite into Owner B, or list/revoke Owner B membership (404)
- Owner cannot act on a branch ID belonging to another organization (404)
- `branch_operator` cannot create another branch, cannot list memberships (404)
- Pending pharmacy cannot create branches (404)
- Patient / Doctor denied (404)
- Anonymous denied (401)
- Direct status/role mass assignment rejected (422)
- Cross-org verification-status is 404; own org-id verification-status is 200

## Subject privacy / erasure

`pharmacy_staff_invitations` is added to `PharmacySubjectHoldings` and
export counts. Erase of the intended recipient cancels pending invitations
and tombstones the HMAC. Subsequent accept is `AuthorizationDenied` (the
erased actor is also disabled at Identity). No new plaintext contact value
survives. Memberships still follow the **existing** Pharmacy subject-erasure
policy (this chunk does not revoke memberships or close organizations on
erase).

**The broader Pharmacy erasure lifecycle residual is not closed.**

## PostgreSQL privileges

`PharmacyPostgresPrivilegeTest` loops
`pharmacy_organizations`, `pharmacy_branches`, `pharmacy_memberships`,
`pharmacy_staff_invitations`:

- `clinic_worker` / `clinic_reporter`: no SELECT/INSERT/UPDATE/DELETE
- `clinic_app`: INSERT + UPDATE
- `clinic_backup`: SELECT yes, INSERT no

No new cross-module table permissions.

## Synthetic backend E2E

`PharmacySyntheticE2ETest` (HTTP, not direct DB writes for the primary
flow; fixtures seed approved actors/organizations):

approved organization A + active owner → create Branch B → GET/list Branch B
→ PATCH with `expected_version` → invite intended Pharmacy actor → wrong
recipient 404 → intended actor accepts → operator GET is staff-safe (no
address) → operator cannot list memberships → owner lists membership →
Owner B cannot GET Owner A branch → `branch_operator` cannot create another
branch → owner revokes → `ResolveActivePharmacyMembership` is null →
revoked operator GET is 404 → outbox `pharmacy.branch_changed` and
`pharmacy.membership_changed` exist.

## OpenAPI / generated clients / breaking check

Additive pharmacy private endpoints only. Phone is write-only. No public
directory contract. No existing Patient/Doctor/Admin/Clinic operation IDs
renamed. No Pharmacy Electron bridge methods.

- `npm run contracts:lint` — valid
- `npm run contracts:events` — 25 schemas including
  `pharmacy/branch_changed.v1` and `pharmacy/membership_changed.v1`
- `npm run contracts:generate:ts` — `schema.d.ts` regenerated
- `npm run contracts:generate:dart` — generated Dart remains gitignored
- `npm run contracts:breaking` — **No breaking contract changes against origin/main**

## Exact local test counts

Host PHP 8.3, PostgreSQL `clinic_test`, sequential Pest (do not parallelize
RefreshDatabase with `CommittedDatabaseTestCase`).

| Suite | Result |
| --- | --- |
| `tests/Feature/Pharmacies` + `tests/Unit/Pharmacies` | **46 passed**, 921 assertions |
| Pharmacy verification flows + races + Admin pharmacy review/rollback | **27 passed**, 694 assertions |
| `IdentityRulesTest` + `InvitationPhoneBindingTest` + `EraseSubjectServiceTest` + `ArchitectureBoundaryTest` | **46 passed**, 5334 assertions |
| `ClinicStaffInvitationFlowsTest` (HMAC/invitation regression) | **12 passed**, 252 assertions |
| PHPStan | 0 errors |
| Deptrac | 0 violations, 3108 allowed |
| Pint | passed |
| Focused first-pass (flows+org+rollback+E2E+privilege, no races) | 34 passed, 773 assertions |
| Additional-branch + staff-invitation + organization races | 7 passed, 101 assertions |

New/extended Chunk 14 Pest files:

| File | Tests |
| --- | --- |
| `PharmacyAdditionalBranchFlowsTest.php` | 5 |
| `PharmacyAdditionalBranchRaceTest.php` | 2 |
| `PharmacyStaffInvitationFlowsTest.php` | 8 |
| `PharmacyStaffInvitationRaceTest.php` | 3 |
| `PharmacyMembershipRollbackTest.php` | 4 |
| `PharmacySyntheticE2ETest.php` | 1 |
| `PharmacyPostgresPrivilegeTest.php` | 2 (extended to invitations) |

Chunk-14-named new tests: **23** (5+2+8+3+4+1). Privilege + existing
Chunk 07/08/09 Pharmacies and verification tests stayed green.

Full Core Pest (`./vendor/bin/pest` as CI Core API): **838 tests**,
**829 passed**, **8 skipped**, **1 failed**. The single failure is
`ClamdScanObjectTest` live EICAR → expected `infected`, observed `clean`.
That is the known local clamd signature/environment miss recorded in Chunks
11; CI secure-file starts digest-pinned clamd. It is not this pharmacy
change.

## Exact changed files (vs baseline `bdf9da8`)

53 files before this evidence document (5317 insertions / 40 deletions at
`2eaeaad`):

```
apps/core-api/Modules/Access/app/Services/DefaultDenyAuthorizer.php
apps/core-api/Modules/Access/app/Support/Capabilities.php
apps/core-api/Modules/Pharmacies/app/Enums/PharmacyBranchChangeType.php
apps/core-api/Modules/Pharmacies/app/Enums/PharmacyInvitationStatus.php
apps/core-api/Modules/Pharmacies/app/Enums/PharmacyMembershipScopeType.php
apps/core-api/Modules/Pharmacies/app/Events/PharmacyBranchChanged.php
apps/core-api/Modules/Pharmacies/app/Events/PharmacyMembershipChanged.php
apps/core-api/Modules/Pharmacies/app/Http/Controllers/PharmacyBranchController.php
apps/core-api/Modules/Pharmacies/app/Http/Controllers/PharmacyStaffInvitationController.php
apps/core-api/Modules/Pharmacies/app/Providers/PharmaciesServiceProvider.php
apps/core-api/Modules/Pharmacies/app/Services/AcceptPharmacyStaffInvitation.php
apps/core-api/Modules/Pharmacies/app/Services/Adapters/PostgresPharmacySubjectPrivacy.php
apps/core-api/Modules/Pharmacies/app/Services/CreatePharmacyBranch.php
apps/core-api/Modules/Pharmacies/app/Services/GetOwnPharmacyBranches.php
apps/core-api/Modules/Pharmacies/app/Services/InvitePharmacyStaff.php
apps/core-api/Modules/Pharmacies/app/Services/ManagePharmacyMemberships.php
apps/core-api/Modules/Pharmacies/app/Services/Persistence/PostgresPharmacyOrganizationStore.php
apps/core-api/Modules/Pharmacies/app/Services/ResolveActivePharmacyMembership.php
apps/core-api/Modules/Pharmacies/app/Services/UpdatePharmacyBranch.php
apps/core-api/Modules/Pharmacies/app/Support/ActivePharmacyMembershipDto.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyBranchPrivateProjection.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyBranchProjector.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyBranchRules.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyInvitationOutcome.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyMembershipProjection.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyMembershipRecord.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyOrganizationRowFactory.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyOwnerGuard.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacyStaffInvitationRecord.php
apps/core-api/Modules/Pharmacies/app/Support/PharmacySubjectHoldings.php
apps/core-api/Modules/Pharmacies/config/config.php
apps/core-api/Modules/Platform/app/Services/Coordinators/ApprovedCoordinators.php
apps/core-api/Modules/Verification/app/Http/Controllers/PharmacyVerificationController.php
apps/core-api/Modules/Verification/app/Services/VerificationService.php
apps/core-api/database/migrations/2026_09_22_140000_create_pharmacy_staff_invitations_table.php
apps/core-api/routes/api.php
apps/core-api/tests/Feature/Pharmacies/PharmacyAdditionalBranchFlowsTest.php
apps/core-api/tests/Feature/Pharmacies/PharmacyAdditionalBranchRaceTest.php
apps/core-api/tests/Feature/Pharmacies/PharmacyMembershipRollbackTest.php
apps/core-api/tests/Feature/Pharmacies/PharmacyOrganizationFlowsTest.php
apps/core-api/tests/Feature/Pharmacies/PharmacyPostgresPrivilegeTest.php
apps/core-api/tests/Feature/Pharmacies/PharmacyStaffInvitationFlowsTest.php
apps/core-api/tests/Feature/Pharmacies/PharmacyStaffInvitationRaceTest.php
apps/core-api/tests/Feature/Pharmacies/PharmacySyntheticE2ETest.php
apps/core-api/tests/Support/pharmacyHttpHelpers.php
apps/core-api/tests/Unit/Identity/IdentityRulesTest.php
apps/core-api/tests/Unit/Pharmacies/PharmacyOrganizationInvariantsTest.php
apps/core-api/tests/Unit/Platform/ArchitectureBoundaryTest.php
docs/architecture/module-catalog.md
packages/contracts/events/pharmacy/branch_changed.v1.schema.json
packages/contracts/events/pharmacy/membership_changed.v1.schema.json
packages/contracts/openapi/openapi.yaml
packages/typescript/api_client/src/generated/schema.d.ts
```

Plus this evidence file after commit.

No `apps/pharmacy-desktop` UI or bridge operations. No `designs/**` edits.

## Exact final-head GitHub CI

Pending the evidence-commit HEAD on Draft PR #24. Local Core API static
analysis and Pest above are recorded. Independent review decides
`READY_TO_MERGE`. This chunk does not mark READY_TO_MERGE and does not merge.

## Residuals (keep visible)

- Pharmacy Electron additional-branch UI remains Chunk 15.
- Pharmacy Electron membership-management UI remains Chunk 15.
- Payment-method metadata remains.
- Public branch directory/listing remains later (not Phase 02 public search).
- Pharmacy broad erasure lifecycle residual remains open.
- Identity key-rotation command coverage for Pharmacy protected columns
  remains open.
- DEF-SEC-MFA-001 remains.
- SF-001 remains MERGE_ONLY / production promotion blocked.
- G-08-04 / ADR 0014 / profile-claim remain unchanged.
- Staging remains unprovisioned.
- Phase 03 remains.
- Phase 10 inventory, POS, purchasing, catalog, operating modes, and the
  wider pharmacy role matrix remain out of scope.
- `UNIQUE (organization_id, user_id)` including revoked rows means a revoked
  `branch_operator` cannot be re-invited to another (or the same) branch of
  that organization without a later designed transfer/rehire workflow.
- Invitation TTL (72h) and Egypt service-area bbox remain
  ENGINEERING_DEFAULT.
- Local clamd EICAR signature miss is environmental (CI digest-pinned
  clamd).

**Chunk 14 does not complete Phase 02.**
**Phase 02 remains NOT PASS.**
Pharmacy Electron additional-branch and membership-management UI remains
for the next bounded chunk.
Inventory, POS, purchasing, catalog, operating modes, and the wider
Phase-10 pharmacy role matrix remain out of scope.
