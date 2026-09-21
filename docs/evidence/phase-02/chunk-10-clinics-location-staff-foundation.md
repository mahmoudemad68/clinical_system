# Phase 02 chunk 10 — Clinics location and staff foundation (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** a real Nwidart `Clinics` module delivering the Phase-02
authoritative backend foundation for clinic locations owned by approved
doctors, PostGIS geography, optimistic-concurrency location updates,
secretary invitation / acceptance / revocation, clinic staff profiles and
location-scoped memberships, Identity subject-privacy inversion, safe
events/audit/OpenAPI, and narrow read ports for Phase 03. `active` means
authoritative location readiness only. It does **not** mean public directory
listing, schedules, booking, clinical capability, or patient visibility.

**Explicitly deferred:** Doctor Electron clinic-location UI, Doctor Electron
verification UX, Patient Flutter Phase-02 completion, Pharmacy additional
branches and membership invite/revoke, schedules, appointment types, prices,
availability, booking/walk-ins, queue/realtime, encounters/clinical records,
public clinic search / maps / geocoders, patient current-location storage,
generic role editor, Admin clinic management, Phase 03, SF-001, G-08-04 /
ADR 0014 / profile-claim, staging provisioning, pharmacy envelope rotation,
and the existing Pharmacy subject-erasure residual.

**SF-001** remains unresolved / unaccepted (`MERGE_ONLY`,
`promotion_allowed=false`). `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.
ADR 0014 and G-08-04 are not closed by this slice. Staging `Deploy to staging`
remains fail-closed. This chunk does not bypass those gates.

- **Branch:** `cursor/phase-02-clinics-location-staff-foundation-cc7f`
- **Base (GitHub `main` after merged PR #15 / Chunk 09):** `ac244532fd63c7d839374bc796fcf6edad19401c`
- **Independently reviewed HEAD:** `fbb0594aa6fc8d6730a96d25a588d000cd4cfe7d`
  (`pull-request` **35574600596** SUCCESS)
- **Invitation-blocker HEAD:** `c3ff0264bae3be99ffcaa9dc21845c040b7376c7`
- **GitHub CI:** `pull-request` run **35577105427** SUCCESS on that exact HEAD
  (https://github.com/mahmoudemad68/clinical_system/actions/runs/35577105427)
- **Recorded:** 2026-09-21
- **Environment:** host PHP 8.3 with `pdo_pgsql`, PostgreSQL + PostGIS
  `clinic_test`. No Docker Compose. Redis/MinIO were not required for this
  backend slice.

## Baseline verification

`git fetch origin main` then `git merge-base --is-ancestor
ac244532fd63c7d839374bc796fcf6edad19401c origin/main` succeeded.
`origin/main` is still `ac244532fd63c7d839374bc796fcf6edad19401c`.
Work started from that commit on a new branch. No prior Phase-02 feature
branch was continued.

## What was implemented

Authenticated approved doctor (privileged MFA / AAL2) → create Cairo clinic
location (server-owned `active`) → private GET (decrypted address +
coordinates) → PATCH with `expected_version` → invite secretary by phone →
intended secretary accepts → exactly one active location-scoped membership →
owner lists memberships → other doctor denied → revoke → membership no longer
resolves active → audit + outbox facts only.

Pending/rejected/suspended/unverified doctors, Patient, Pharmacy, Secretary,
and Admin cannot create doctor-owned locations. Doctor A cannot
GET/PATCH/invite/revoke Doctor B's location (404). Clients cannot submit
`doctor_id`, `status`, `version`, actor IDs, or arbitrary roles.

## Clinics module architecture

New Nwidart module `apps/core-api/Modules/Clinics/` (priority 53). Conventional
folders only (`Http`, `Services`, `Support`, `Enums`, `Events`, `Providers`).
No `Domain/` / `Application/` / `Infrastructure/`.

**Allowed Deptrac direction:**

```
Clinics → Platform, Identity, Access, Audit, Doctors, Framework
```

Doctors, Platform, Identity, Pharmacies, Patients, Auth, Verification, and
Admin must not import Clinics types. Identity must not query clinic tables.
Clinics application SQL must not query `doctor_profiles`, `patient_*`,
`pharmacy_*`, `verification_*`, `clinical_*`, or `appointment_*`. A database
FK from `clinic_locations.doctor_id` to `doctor_profiles.id` exists because
the Phase-02 schema requires it; application ownership checks go through
`PracticeOwnerEligibilityService`.

`modules_statuses.json` enables `Clinics`. ArchitectureBoundaryTest covers
the table allowlist and foreign-table access. Deptrac: 0 violations, 2801
allowed.

## Doctor ownership / approval contract

`Modules\Doctors\Services\PracticeOwnerEligibilityService` returns only:

- `doctor_id`
- `user_id`
- `verification_status`
- profile `version`

It does not expose National ID, syndicate number, encrypted fields, specialty
internals, verification documents, or `public_status`. Clinic create/mutation
requires authenticated active `account_type=doctor`,
`assuranceLevel->satisfiesPrivilegedSession()` (AAL2 TOTP/recovery), an
authoritative Doctor profile for that user, and `verification_status=approved`.
Public listing is not required.

A capability name in `/me/capabilities` is never treated as proof of clinic
ownership. `ClinicOwnerGuard` additionally checks actor type, approval, and
exact resource relationship.

## Location state-transition choice

There is no clinic-document Verification case and no Admin clinic approval.

**Create → server-owned `active`** when public name, encrypted address,
`country_code=EG`, and legal in-Egypt coordinates all pass in one
transaction. There is no externally observable partially created active
location.

Reserved server-owned states: `draft`, `pending`, `suspended`, `closed`.
This chunk writes `active` on successful create/update and `closed` on
owning-doctor subject erasure. Clients cannot submit status.

`active` = location readiness only.

## Database tables / constraints / indexes

Forward migration
`apps/core-api/database/migrations/2026_09_21_120000_create_clinic_location_and_staff_tables.php`.
Historical migrations were not edited.

### `clinic_locations`

- UUIDv7 PK, `doctor_id` NOT NULL FK → `doctor_profiles(id)`
- `public_name varchar(200)` with length CHECK 1–200
- `address_ciphertext` bytea + `address_key_version` (envelope
  `physical_address`; never plaintext)
- `country_code char(2)` default `EG`, ISO-2 CHECK; V1 app allows EG only
- `geography(Point, 4326)` NOT NULL; WGS-84 lat/lng + SRID CHECK
- `status` CHECK `draft|pending|active|suspended|closed`
- `version` bigint default 1, CHECK `>= 1`
- indexes: BTREE `(doctor_id, status)`, GiST `(geography_point)`
- parameterized PostGIS writes:
  `ST_SetSRID(ST_MakePoint(:lng,:lat),4326)::geography`
- Clinics-specific `ClinicCoordinates` (does not import PharmacyCoordinates).
  Egypt bbox `lat 22–31.7`, `lng 24.7–36.9` is **ENGINEERING_DEFAULT**.

### `clinic_staff_profiles`

- UUIDv7 PK, unique `user_id` FK → `users(id)`
- Linked identity only. Personal staff location is never collected.

### `clinic_staff_memberships`

- UUIDv7 PK; FKs to staff profile + location
- role CHECK `doctor|secretary` (`doctor` reserved; owner is
  `clinic_locations.doctor_id`; this chunk does not write doctor memberships)
- status CHECK `pending|active|suspended|revoked`
- `version >= 1`
- unique active/pending grant: `(location_id, staff_profile_id) WHERE status IN ('pending','active')`
- inviter/revoker user FKs

### `clinic_staff_invitations`

- UUIDv7 PK; location FK
- role CHECK `secretary` only
- status CHECK `pending|consumed|expired|cancelled`
- `target_phone_lookup_hmac` + key version (Identity HMAC; no plaintext phone)
- unique pending `(location_id, target_phone_lookup_hmac) WHERE status = 'pending'`
- `expires_at`, `consumed_at`, `inviter_user_id`

Least-privilege: `clinic_app` DML; `clinic_worker` / `clinic_reporter`
revoked; `clinic_backup` SELECT.

## Location APIs and projections

Private authenticated only. No public search / radius / directory.

| Method | Path | Notes |
| --- | --- | --- |
| `POST` | `/api/v1/clinic-locations` | Idempotency-Key required. Compact `{location_id,status,version}`. |
| `GET` | `/api/v1/clinic-locations` | Cursor-paginated private list. Owner gets decrypted address/coords; staff does not. |
| `GET` | `/api/v1/clinic-locations/{location_id}` | Owner private projection includes address/coords. Staff-safe omits them. |
| `PATCH` | `/api/v1/clinic-locations/{location_id}` | Requires `expected_version`. Stale → `VERSION_CONFLICT` 409. |

Create replay: same key + same body returns the committed result. Same key +
different body → `IDEMPOTENCY_KEY_REUSED`. Address never appears in events,
logs, metrics labels, cache keys, URLs, audit metadata, or idempotency
pointers.

## Staff invitation model and identity binding

`POST /api/v1/clinic-locations/{location_id}/staff-invitations`

Only the approved owning doctor of that exact location may invite. Inviteable
role is **secretary only**. Clients cannot submit clinic scope, doctor id,
membership status, user id, inviter id, capabilities, or role.

Identity binding: `InvitationRecipientService::bindPhone()` canonicalizes
the submitted phone without consulting the user directory and returns:

- current `phoneLookupHmac` + `hmacVersion` for **storage** of a new row
- every configured lookup HMAC for that canonical phone, exposed only as
  `InvitationPhoneBinding::orderedLookupHmacs()` (stable `strcmp(bin2hex)`
  order, unique)

Clinics never queries `users` and never learns whether an account exists.
Unknown phones receive the same created shape as known secretaries. Phone
is not in URLs. HMAC/token material is not in events, logs, metrics, audit
metadata, or HTTP projections (`ClinicInvitationOutcome` is IDs/status/
`expires_at` only).

**Invitation TTL:** `clinics_module.invitation_ttl_hours = 72`
**ENGINEERING_DEFAULT**. No SMS/email in this chunk (Phase 09).

Pending uniqueness remains `(location_id, target_phone_lookup_hmac) WHERE
status = 'pending'`. Expiration is **not** part of the unique index.
`InviteClinicStaff` therefore:

1. Acquires `pg_advisory_xact_lock` for `invite:{locationId}:{hex(hmac)}`
   for **every** ordered lookup candidate (so v1 and v2 of the same phone
   serialize in one lock order).
2. Loads pending rows at that location whose target HMAC matches any
   candidate (`findPendingInvitationsForHmacs`).
3. If an unexpired pending row exists, replay it (HTTP 200, `created=false`,
   same invitation id). Extra matching pending rows are transitioned to
   `expired` so at most one current pending grant remains.
4. If matching pending rows are expired (`expires_at <= now`), transition
   them to authoritative `status=expired` **in place** (history preserved;
   no delete). Then insert a **new** pending row with a new UUIDv7, new
   TTL, and **only** the current HMAC + current key version (HTTP 201).
5. Acceptance of the expired id continues to fail closed (`AuthorizationDenied`
   / HTTP 404). The replacement can be accepted.

A still-valid pending invite is replay-safe. An expired pending invite is
not a grant candidate and cannot permanently block re-invitation.

HMAC key rotation: creating with the current digest while a previous-key
pending row still exists must **not** insert a second logical pending
invite. Lookup/dedup uses all configured lookup HMAC candidates. The unique
index is not weakened. Acceptance already matched via
`subjectPhoneLookupHmacs` / `actorMatchesInvitationHmac`.

Concurrent expire-then-replace collapses to one new pending row plus the
preserved expired history row. Concurrent first-invite still collapses to
one pending row.

## Invitation acceptance semantics

`POST /api/v1/clinic-staff-invitations/{invitation_id}/accept`

Invitation ID is not authorization. The authenticated actor must be an
active secretary whose current/rotated phone HMACs match the stored
invitation HMAC. No invented TOTP requirement for Secretary.

Atomic transaction: lock invitation → pending + unexpired → intended
identity → create/find staff profile → exactly one active location
membership → consume invitation → audit → outbox. Concurrent double
accept: one 200 + one safe 404 (or unique-grant recovery), one active
membership. Expired / consumed / stolen / wrong-user / non-secretary →
generic 404 `AuthorizationDenied`. Phone/account is not revealed.

HTTP cookie acceptance is covered by `ClinicStaffInvitationFlowsTest`.
The synthetic E2E calls the same `AcceptClinicStaffInvitation` coordinator
with a server-derived secretary `ActorContext` so bearer `identity.session`
traffic from earlier doctor writes cannot poison the cookie CSRF harness.

## Membership revoke semantics

`GET /api/v1/clinic-locations/{location_id}/memberships` — owner-only safe
projection: `membership_id`, `role`, `status`, `version`, `invited_at`,
`accepted_at`, `revoked_at`. No phone/HMAC/auth/device/clinical fields.

`DELETE /api/v1/clinic-locations/{location_id}/memberships/{membership_id}`

Only the owning approved doctor of that exact location. Cross-clinic /
cross-doctor → 404. Sets server-owned `status=revoked`, `revoked_at`,
`revoker_user_id`, `version += 1`. Repeated revoke is stable (same version,
status revoked). History is not hard-deleted. No realtime consumers exist
yet (Phase 04/09); the authoritative `clinic.membership_changed` outbox
event is emitted.

## Subject-erasure / privacy integration

Identity-owned `ClinicSubjectPrivacy` contract with default
`UnavailableClinicSubjectPrivacy`. Clinics rebinds
`PostgresClinicSubjectPrivacy`. Identity does not import Clinics
implementations or query clinic tables. Clinics does not query Auth tables.

**Secretary erased/closed:** active/pending memberships revoked; pending
invitations for that subject's lookup HMACs cancelled (tombstoned HMAC).
Acceptance after erasure fails closed.

**Subject export vs erasure:** `exportCounts()` counts **distinct**
`clinic_staff_invitations` rows where the subject is the inviter **and/or**
the target HMAC matches any configured lookup HMAC for the subject
(`countInvitationsLinkedToSubject`). A secretary who is only the target of
a pending invitation (no membership yet) therefore exports
`clinic_staff_invitations = 1`. Doctor-owner export still counts invitations
they sent plus owned `clinic_locations`. Phone, HMAC values, invitation
secrets, and other users' identities are not in the export payload. Erasure
already discovered target invitations through
`InvitationRecipientService::subjectPhoneLookupHmacs`.

**Owning doctor erased/closed:** owned locations transition to `closed`,
public name tombstoned to `erased`, address ciphertext replaced with random
bytes; attached memberships revoked; pending location invitations cancelled.
Closed locations are not an active authorization/publication source.
`GetClinicLocation::isActive()` is false. No `clinic.location_changed` event
is emitted from erasure (privacy transition, not a location edit).

Pharmacy subject-erasure residual is **not** fixed in this chunk.

## Event and audit payloads

Outbox via existing `TransactionRunner` after commit.

`clinic.location_changed` v1:

```json
{ "location_id": "<uuidv7>", "doctor_id": "<uuidv7>", "version": 1, "change_type": "created|updated|closed" }
```

`clinic.membership_changed` v1:

```json
{ "membership_id": "<uuidv7>", "scope_type": "clinic_location", "scope_id": "<location uuidv7>", "status": "pending|active|suspended|revoked" }
```

Never included: address, lat/lng, phone, phone HMAC, invitation target,
invitation secret, staff identity details, National ID, syndicate number,
clinical data.

Audit events (IDs/reason codes only): `clinic.location_created`,
`clinic.location_updated`, `clinic.staff_invitation_created`,
`clinic.staff_invitation_accepted`, `clinic.staff_membership_revoked`.
Denied attempts follow existing generic 404 mapping; this chunk does not
invent a new denial-audit channel. Audit/outbox failure rolls back location
and membership writes (`FailOnceAppendAuditEvent` tests).

## Access capabilities

Purpose-specific only, in `AUTHENTICATED_SELF`, pending-phone forbidden:

- `clinics.location.write`
- `clinics.location.read_own`
- `clinics.staff.invite`
- `clinics.staff.accept`
- `clinics.membership.read_own`
- `clinics.membership.revoke`

No scheduling, booking, clinical-record, queue, or patient capabilities.

## Narrow Phase-03 ports

- `GetClinicLocation` → `ClinicLocationPortDto` (`locationId`, `doctorId`,
  `status`, `version`, `countryCode`, `isActive()`). No address/coords,
  no Eloquent.
- `ResolveActiveClinicMembership` → `ActiveClinicMembershipDto`
  (`membershipId`, `locationId`, `userId`, `role`, `status`) or null.

Scheduling/appointments/public geo search are not implemented.

## BOLA / mass-assignment protections

Closed JSON rejects extra fields (`doctor_id`, `status`, `version`,
`created_by`, verification flags, invitation `role`/`user_id`/`status`/
`inviter_id`). Cross-owner GET/PATCH/invite/revoke → 404. Unapproved
doctors and non-doctor account types → 404. Invalid country, illegal
WGS-84, and outside-Egypt coordinates → 422. Capability names are
insufficient without `ClinicOwnerGuard`.

## PostgreSQL / PostGIS / concurrency / rollback evidence

- Encrypted address at rest; plaintext canaries absent from event/audit/log/
  metric/idempotency output (`ClinicLocationFlowsTest`).
- `ST_X`/`ST_Y`/`ST_SRID` = longitude, latitude, 4326.
- GiST index `clinic_locations_geography_point_gix` `USING gist`.
- `EXPLAIN` of `ST_DWithin(geography_point, ST_SetSRID(ST_MakePoint(lng,lat),4326)::geography, 5000)`
  is inspectable. No public search endpoint. No production p95 claim from
  this fixture. Full location-query p95/load validation remains a Phase-02
  closeout residual.
- Stale PATCH `expected_version` → 409 `VERSION_CONFLICT`; concurrent PATCH
  one 200 + one 409; version = 2.
- Concurrent accept → one active membership.
- Concurrent invite → one pending invitation.
- Concurrent re-invite of an expired pending invitation → one `expired`
  history row + one new `pending` row (new id).
- Unique active grant constraint proven.
- Audit-append failure rolls back create and accept.

## Local test counts

Host Pest against PostgreSQL `clinic_test` (not SQLite):

| Suite | Result |
| --- | --- |
| `tests/Feature/Clinics` + `tests/Unit/Clinics` + `ArchitectureBoundaryTest` + `tests/Unit/Identity` + `EraseSubjectServiceTest` | **79 passed, 5673 assertions** |
| `tests/Feature/Doctors` + `tests/Unit/Doctors` | 25 passed, 361 assertions |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 errors |
| Deptrac | 0 violations, 2813 allowed |
| Pint `--test` | passed |

## OpenAPI / generated clients / breaking check

Additive `clinics` tag and private endpoints only. Address and invitation
phone are `writeOnly`. No public clinic-directory contract. No existing
Doctor/Pharmacy/Patient/Admin operation IDs renamed.

- `npm run contracts:lint` — valid
- `npm run contracts:events` — 23 schemas including
  `clinic/location_changed.v1` and `clinic/membership_changed.v1`
- `npm run contracts:generate:ts` — `schema.d.ts` regenerated
- `npm run contracts:generate:dart` — 55 operations (generated dart remains
  gitignored per repository convention)
- `npm run contracts:breaking -- origin/main` — **No breaking contract
  changes against origin/main**

## GitHub CI

Independently reviewed foundation HEAD `fbb0594aa6fc8d6730a96d25a588d000cd4cfe7d`
had `pull-request` run **35574600596** SUCCESS
(https://github.com/mahmoudemad68/clinical_system/actions/runs/35574600596).

Invitation-blocker HEAD `c3ff0264bae3be99ffcaa9dc21845c040b7376c7` has
`pull-request` run **35577105427** SUCCESS
(https://github.com/mahmoudemad68/clinical_system/actions/runs/35577105427).

All required checks passed, including Core API, Contracts, Admin web,
Electron desktops, packaged Electron E2E, security scans, and supply-chain
policy. AI service and Flutter were skipped by path filters.

Earlier on this PR:

- `64034b5f19bbc383fd13e444fda1fc26c28743e4` (**35573585941**) failed
  Core API PHPStan; fixed in `84cfc48`.
- `84cfc482875cf7ea6acf32cb072af88d90551635` (**35574002563**) SUCCESS.
- `492fc18fef62283e1e7f544101027f7132b353b0` (**35576808921**) failed
  Core API: HMAC-rotation tests still used the doctor bearer token after
  `session_token` hashing switched to HMAC v2. Fixed in `c3ff026` by
  re-inviting through `InviteClinicStaff` + `clinicDoctorActor`.

Chunk-only evidence. Phase 02 is **NOT PASS**.

## Invitation lifecycle / privacy follow-up (Draft PR #16)

Independently reviewed HEAD `fbb0594aa6fc8d6730a96d25a588d000cd4cfe7d`
had GitHub CI `pull-request` **35574600596** SUCCESS. Three invitation
blockers were then fixed on the same Draft PR without starting a new chunk.
Code HEAD `c3ff0264bae3be99ffcaa9dc21845c040b7376c7` has
`pull-request` **35577105427** SUCCESS.

### Expired pending → replacement

Regression
`expires a stale pending invitation and lets the same doctor invite again`
(`ClinicStaffInvitationFlowsTest`):

- valid pending invite is replayed (same id, still one row)
- after `expires_at` is in the past, re-invite marks the old row `expired`
- same doctor creates a new invitation (new id, new TTL, HTTP 201)
- old invitation cannot be accepted (404)
- new invitation can be accepted
- exactly one current `pending` row exists (history row retained)

### Current/previous HMAC dedup

Regressions:

- `dedups a pending invitation stored under a previous HMAC after key rotation`
- `replaces an expired previous-HMAC invitation with a current-HMAC row`
- `orders unique lookup HMAC candidates deterministically`
  (`InvitationPhoneBindingTest`)

After switching `identity.hmac.current_version` to 2 and rebinding
`HmacHasher` / `NationalIdProtector`, inviting the same canonical phone
finds the v1 pending row, does not insert a second pending row, and
acceptance still succeeds. Once that v1 row is expired, it is marked
`expired` and a replacement under the current HMAC/key version is created.
Clinics public projections still omit phone/HMAC.

### Deterministic concurrency locking

`InviteClinicStaff` locks every `orderedLookupHmacs()` candidate in
hex-sorted order before pending lookup. Concurrent expire-and-replace is
covered by
`replaces an expired pending invitation once when re-invited concurrently`.
Existing concurrent first-invite still yields one pending row; that path
now also locks every configured lookup candidate for the canonical phone,
so a v1/v2 key-transition pair cannot create two logical pendings.
Doctor HTTP bearer tokens hash `session_token` with the **current** HMAC
key, so rotation tests re-invite through `InviteClinicStaff` +
`clinicDoctorActor` after switching `identity.hmac.current_version`.
Secretary accept after rotation uses a fresh cookie login (new session
hash under the current key).

### Target-invitation subject export

Regression
`exports a secretary-targeted pending invitation and erasure blocks acceptance`:

- Secretary has no membership yet, receives a pending clinic invitation
- `ExportSubjectDataService` → `clinic_staff_invitations` count is 1
- Doctor-owner export still counts the invitation they sent and the owned
  location
- Export JSON has no phone, HMAC hex, or the other user's id
- `EraseSubjectService` cancels/tombstones the target binding
- subsequent accept fails closed

### Exact regression tests added

| Test | File |
| --- | --- |
| expires a stale pending invitation and lets the same doctor invite again | `tests/Feature/Clinics/ClinicStaffInvitationFlowsTest.php` |
| dedups a pending invitation stored under a previous HMAC after key rotation | same |
| replaces an expired previous-HMAC invitation with a current-HMAC row | same |
| exports a secretary-targeted pending invitation and erasure blocks acceptance | same |
| replaces an expired pending invitation once when re-invited concurrently | `tests/Feature/Clinics/ClinicConcurrencyTest.php` |
| orders unique lookup HMAC candidates deterministically | `tests/Unit/Identity/InvitationPhoneBindingTest.php` |

Accepted Chunk-10 architecture, PostGIS, Doctor ownership, optimistic
concurrency, secretary acceptance, membership revoke, and subject-erasure
behavior are unchanged.

## Residuals (keep visible)

- `organization_registration_evidence` remains ENGINEERING_DEFAULT.
- Doctor Electron Phase-02 UX remains.
- Patient Flutter Phase-02 completion remains.
- Pharmacy additional branches and membership management remain.
- Public location discovery remains Phase 08.
- Scheduling remains Phase 03.
- SF-001 remains MERGE_ONLY / production promotion blocked.
- G-08-04 / ADR 0014 / profile-claim gate remain unchanged.
- Staging remains unprovisioned.
- Pharmacy envelope key rotation remains deferred.
- Existing Pharmacy subject-erasure residual remains open.
- Clinic invitation TTL (72h) and Egypt service-area bbox remain
  ENGINEERING_DEFAULT.
- Phase-02 closeout still needs location-query p95/load evidence; this
  chunk only proves storage, GiST presence, and representative
  `ST_DWithin` plan compatibility.
