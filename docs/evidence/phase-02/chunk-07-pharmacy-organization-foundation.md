# Phase 02 chunk 07 — Pharmacy organization foundation (not phase PASS)

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** canonical `Pharmacies` module (not a second
`PharmacyOrganizations` module) with one authoritative onboarding transaction
that creates a draft pharmacy organization, its initial branch, and the
founding owner membership; protected legal name / legal registration /
address / phone; purpose-bound blind HMAC lookup; PostGIS
`geography(Point, 4326)` with a GiST index; compact
`POST /api/v1/pharmacy-organizations/onboarding`; own-only
`GET /api/v1/pharmacy-organizations/me`; find-only Verification-facing
`PharmacyApplicantService` and `PharmacyReviewerService`; Identity
`PharmacySubjectPrivacy` adapter. A draft organization grants no inventory,
purchasing, POS, catalog-administration, clinical, or public-activation
capability.

**Explicitly deferred:** pharmacy verification cases, submissions, documents,
decisions, `pharmacy.verification_decided`, Admin pharmacy review UI, Pharmacy
Electron onboarding UI, additional-branch management, membership
invitation/revocation, Phase-10 operating mode / payment methods / business
capability matrix, inventory, purchasing, POS, catalog, public listing,
Clinics, doctor clinic locations, scheduling / Phase 03, production
deployment.

**SF-001** remains unresolved / unaccepted. `FEATURE_IDENTITY_PROFILE_CLAIM`
remains off. ADR 0014 and G-08-04 are not closed by this slice. Staging
`Deploy to staging` remains fail-closed. This chunk does not bypass those
gates.

- **Branch:** `cursor/phase-02-pharmacy-organization-foundation-cc7f`
- **Base (GitHub `main` after merged PR #12):** `832ae591ba2a982d18c61552de3c7e3b9f6741eb`
- **Local candidate HEAD:** `504c2d12e14a54ef841518b16b4f3dacb0b4b32c`
- **Recorded:** 2026-09-20
- **Environment:** host PHP 8.3.6 with `pdo_pgsql`, apt PostgreSQL 16,
  `postgresql-16-postgis-3` 3.4.2, database `clinic_test`, role
  `clinic_migrator`. No Docker Compose. Redis/MinIO were not provisioned;
  related existing skips remain.

## What was implemented

Authenticated pharmacy actor (`AccountType::Pharmacy`,
`ClientClass::PharmacyDesktop`) → create organization draft → create initial
branch → create owner membership in one `TransactionRunner` unit of work.

Module catalog / Phase 10 docs now state the lifecycle explicitly: Phase 02
chunk 07 establishes the canonical organization/branch/membership identity;
Phase 10 later extends the same `Pharmacies` module. There is no duplicate
Phase-02 / Phase-10 aggregate.

## Tables / contracts / services

**Tables** (`2026_09_20_180000_create_pharmacy_organization_tables.php`):

- `pharmacy_organizations` — UUIDv7 id; `legal_name_ciphertext` + key
  version; `public_name`; `registration_ciphertext` + `registration_lookup_hmac`
  + key version; `verification_status`; `status`; optimistic `version`;
  timestamps. Unique registration HMAC. Active requires approved. `version >= 1`.
- `pharmacy_branches` — UUIDv7 id; organization FK; public name;
  `address_ciphertext`; ISO-2 `country_code` (schema country-ready; V1 app
  requires `EG`); `phone_ciphertext`; `geography(Point, 4326)` with legal
  lat/lng CHECK and GiST; status; version; timestamps.
- `pharmacy_memberships` — UUIDv7 id; organization FK; user FK; nullable
  branch FK; role `owner`/`branch_operator` (only `owner` is written);
  status; invitation/revocation timestamps; version. Unique `(organization_id, user_id)`.
  Partial unique founding owner per user and per organization where
  `role = owner` and `status IN ('pending','active')`. Owner rows require
  `branch_id IS NULL`.

Least-privilege grants match Doctors: `clinic_app` DML; worker/reporter
revoked; backup SELECT.

**HTTP / OpenAPI:**

- `POST /api/v1/pharmacy-organizations/onboarding` (`onboardPharmacyOrganization`)
- `GET /api/v1/pharmacy-organizations/me` (`getOwnPharmacyOrganization`)
- Event `pharmacy.organization_created.v1` (personal identifier-only)

**Services:** `RegisterPharmacyOrganization` (ApprovedCoordinators),
`GetOwnPharmacyOrganization`, `PharmacyApplicantService` (find-only),
`PharmacyReviewerService` (find-only), `PostgresPharmacySubjectPrivacy`.

**Capabilities (AUTHENTICATED_SELF, deny-by-default unknown names):**
`pharmacies.onboarding.submit`, `pharmacies.organization.read_own`.
`inventory.adjust`, `pos.sale.complete`, `purchasing.order.create`, and
`catalog.medication.publish` remain unknown.

## Transaction boundary

`RegisterPharmacyOrganization` runs organization insert, PostGIS branch
insert, owner membership insert, audit append, and
`pharmacy.organization_created` outbox record inside one
`TransactionRunner` closure. A unique-constraint collision is mapped to
`DuplicateIdentity` and either resumes the caller's own organization or
returns generic `manual_review_required`. No partial organization / branch /
membership row survives rollback.

## Concurrency behavior

Writers take `pg_advisory_xact_lock(hashtext(?))` on the registration HMAC
and on `owner:{user_id}` before lookup. Unique HMAC and partial unique
founding-owner indexes remain the invariants.

Race tests:

- Same pharmacy actor, two concurrent onboarding requests with distinct
  idempotency keys → one organization / branch / membership; statuses in
  `{200, 201}` including one `201`.
- Two pharmacy actors, same legal registration → one authoritative
  organization; one `201` and one `200` with `manual_review_required`.

## Idempotency behavior

Platform `EnforceIdempotency` plus the existing 255-byte compact pointer.

- Same Idempotency-Key + same canonical body → original compact result
  (`201` replay of `organization_ready` with the same ids/version).
- Same key + different body → `409 IDEMPOTENCY_KEY_REUSED`.
- New key for an already-linked founding owner → `200 organization_ready`
  of the existing organization (no second row).

The stored pointer contains organization/branch/membership ids and status,
not legal registration, legal name, address, phone, HMAC, or ciphertext.

## Protected-field strategy

Follows Patients/Doctors Identity protection services:

- Encrypt purposes: `legal_name`, `legal_registration`, `physical_address`,
  existing `phone`.
- HMAC purpose: `legal_registration_lookup`.
- Canonicalization `ENGINEERING_DEFAULT`: trim, collapse whitespace,
  uppercase. No commercial-registry checksum is invented.

HTTP, outbox, audit metadata, Monolog `TestHandler` behind
`RedactingLogTap`, in-process HTTP spans, and `PlatformMetrics::render()`
were exercised with synthetic registration / legal-name / address / phone
canaries. Legal registration is **not** added to Platform `PatternRedactor`
(architecture forbids Platform pharmacy business vocabulary).

Own GET omits legal name, registration, address, phone, coordinates,
ciphertext, HMAC, key versions, and `user_id`.

## Non-enumeration behavior / residual

Duplicate registration for a different pharmacy actor returns generic
`manual_review_required` without organization/branch ids, without the
other actor's user id, and without the registration identifier.

This is **not** perfect non-enumeration: an eligible pharmacy actor can
still distinguish `organization_ready` from `manual_review_required`. That
outcome oracle is the same residual already accepted for Patients National
ID and Doctors National ID / syndicate collisions.

## PostGIS evidence

- Extension created in the pharmacy migration (`CREATE EXTENSION IF NOT EXISTS postgis`).
- Column `pharmacy_branches.geography_point geography(Point, 4326) NOT NULL`.
- CHECK: legal longitude/latitude and SRID 4326.
- GiST index `pharmacy_branches_geography_point_gix`.
- Insert uses parameterized `ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography`.
- Happy-path test reads `ST_X`/`ST_Y`/`ST_SRID` as Cairo `31.2357`/`30.0444`/`4326`.
- Invalid WGS-84, Paris (outside Egypt bbox), `country_code=US`, and
  multi-country extra fields return 422 and leave zero rows.
- Egypt bbox is application `ENGINEERING_DEFAULT`; schema stays country-ready.

## Authorization / BOLA / mass assignment

- Unauthenticated onboarding → 401.
- Pending-phone pharmacy and non-pharmacy (patient) callers → 404
  (`FeatureUnavailable`), no rows.
- GET `/pharmacy-organizations/me` is server-derived from the founding owner
  membership. Another pharmacy with no membership → 404. GET-by-id and
  GET verification-status routes do not exist → 404 even for the owner.
- Closed JSON validation rejects `user_id`, `organization_id`,
  `verification_status`, `status`, `role`, `membership_status`, `version`,
  `account_type`, ciphertext/HMAC/key-version fields, and `operating_mode`.
- Capabilities after onboarding include only the two Phase-02 pharmacy
  self-service names; inventory/POS/purchasing/catalog/clinical names are
  absent and unknown to Access.

## Architecture-boundary evidence

- Deptrac `Pharmacies` layer may import Platform, Identity, Access, Audit
  only. Platform does not import Pharmacies types (`ApprovedCoordinators`
  stores the class-string).
- `ArchitectureBoundaryTest`: Pharmacies must not query foreign tables or
  import Patients/Doctors/Auth/Verification/Admin; Verification/Admin/
  Identity/Platform must not query pharmacy tables; catalog peak is
  `sensitive`; event remains personal identifier-only; no
  `PharmacyOrganizations` module heading.
- Identity erasure/export merge `PharmacySubjectPrivacy`; Phase-01 holdings
  still must not name pharmacy tables.

## Commands actually executed

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations, 0 uncovered, **2375** allowed |
| `php artisan migrate --database=pgsql_migrator --force` | applied `2026_09_20_180000_create_pharmacy_organization_tables` |
| `./vendor/bin/pest tests/Feature/Pharmacies tests/Unit/Pharmacies` | **20 passed** (364 assertions) |
| `./vendor/bin/pest tests/Feature/Pharmacies tests/Unit/Pharmacies tests/Unit/Platform/ArchitectureBoundaryTest.php tests/Unit/Identity/IdentityRulesTest.php tests/Feature/Identity` | **145 passed** (4339 assertions) |
| `./vendor/bin/pest` (full Core suite) | **704 passed**, 18 skipped, 722 tests (13783 assertions) |
| `npm run contracts:lint` | OpenAPI valid |
| `npm run contracts:events` | **20** event schemas checked |
| `npm run contracts:generate:ts` | generated client matches this tree |
| `npm run contracts:breaking` | no breaking changes against `origin/main` |
| `python3 scripts/ci/run-isr015-validators.py` | **PASS** (path-filters, license-gate, OpenVEX including gRPC S2, catalog S3/S4, Gitleaks NID static, SF-001, promotion isolation) |
| `npm run admin:typecheck` | tsc `--noEmit` passed |
| `npm run desktop:typecheck` | doctor-desktop and pharmacy-desktop tsc `--noEmit` passed |

Pharmacies-focused tests (20):

- `PharmacyOrganizationFlowsTest` — onboarding, protected fields, PostGIS,
  events/audit/logs/metrics canaries, capability deny, applicant/reviewer
  projections, idempotent replay/conflict, owner retry, mass assignment,
  invalid coordinates/country, authz, collision non-disclosure, BOLA GET me
  and GET-by-id 404, uniqueness, active-without-approved, version 0,
  erasure tombstone
- `PharmacyOrganizationRaceTest` — same-user concurrent create; two-user
  same registration
- `PharmacyPostgresPrivilegeTest` — worker/reporter denied; `clinic_app` DML;
  backup SELECT
- `PharmacyOrganizationInvariantsTest` — no status/role confers business
  capability; registration canonicalize; coordinate rejection

Gitleaks, Trivy image scans, and OpenVEX were **not** weakened. ISR-015
static validators passed locally; container Gitleaks/Trivy remain CI jobs.

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- Legal registration identifiers are trimmed/collapsed/uppercased only; no
  commercial-registry checksum (none is specified in repository policy).
- Egyptian National ID checksum remains ADR 0014 / synthetic-test policy.
- Egypt service-area bounding box is `ENGINEERING_DEFAULT`.
- `branch_operator` is reserved in the CHECK constraint and is not written.
- `GET /pharmacy-organizations/{id}/verification-status` and additional-branch
  POST remain unimplemented (404).
- `identity:rotate-keys` still rotates Identity/Auth protected columns;
  pharmacy legal-name / registration / address envelopes are a deferred
  follow-on (same class of residual as patient `full_name` and doctor
  syndicate).
- Creating a pharmacy organization does not activate the organization,
  approve verification, or grant business capabilities.
- Residual outcome oracle: eligible actors can distinguish
  `organization_ready` from `manual_review_required`.
- Pharmacy verification, Admin pharmacy review, and Pharmacy Electron UX
  remain later Phase 02 work.
- Staging remains unprovisioned; the post-merge deploy gate stays fail-closed.
