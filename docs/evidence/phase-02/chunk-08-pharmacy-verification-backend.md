# Phase 02 chunk 08 — Pharmacy verification backend (not phase PASS)

> **Catalogue supersession (2026-09-24):** Phase 02 Verification Policy
> v1.0.1-phase02 replaces `organization_registration_evidence` and the
> ENGINEERING_DEFAULT reason catalogue recorded below. See
> `docs/evidence/phase-02/verification-policy-v1.0.1-phase02.md`.

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** extend the existing Verification pipeline so an
authenticated founding pharmacy owner can open/resume an own
`pharmacy_verification` case, upload `organization_registration_evidence`
through the existing quarantine/scanner path, submit, and have an Admin
reviewer queue/claim/review/decide that case. Approval atomically activates
the Phase-02 pharmacy organization, initial branch, and founding owner
membership. There is no second pharmacy verification subsystem.

**Explicitly deferred:** Pharmacy Electron onboarding/verification UX,
rendered Admin pharmacy queue/screens, additional branches, membership
invitation/revocation, Phase-10 operating mode / payment methods / business
capability matrix, inventory, purchasing, POS, catalog administration,
clinical capability, public listing, Clinics, doctor clinic locations,
scheduling / Phase 03, production deployment.

**SF-001** remains unresolved / unaccepted. `FEATURE_IDENTITY_PROFILE_CLAIM`
remains off. ADR 0014 and G-08-04 are not closed by this slice. Staging
`Deploy to staging` remains fail-closed. This chunk does not bypass those
gates.

- **Branch:** `cursor/phase-02-pharmacy-verification-backend-cc7f`
- **Base (GitHub `main` after merged PR #13):** `73c3f90c820fbed71d2d79e7d0f3d5f19edb8a10`
- **Recorded:** 2026-09-20
- **Environment:** host PHP 8.3.6 with `pdo_pgsql`, apt PostgreSQL 16,
  `postgresql-16-postgis-3`, database `clinic_test`, role `clinic_migrator`.
  No Docker Compose. Redis/MinIO were not provisioned; related existing
  skips remain.

## What was implemented

Pharmacy account + MFA → Chunk-07 organization/branch/pending owner →
`POST /api/v1/pharmacy-organizations/me/verification-cases` (open/resume
own draft) → existing Verification upload intent/complete/trusted scanner →
`POST /api/v1/pharmacy-organizations/me/verification-submissions` →
`pending_review` → Admin `case_type=pharmacy_verification` queue → claim →
safe reviewer detail → explicit document access → approve → one
`verification_decisions` row + `pharmacy.verification_decided.v1` →
organization `approved`+`active`, initial branch `active`, founding owner
membership `active`. Inventory, purchasing, POS, catalog, and clinical
capability names remain unknown to Access.

## Verification DB constraints

Forward migration `2026_09_20_220000_expand_verification_applicant_and_case_types.php`
(historical merged migrations were not edited):

- `applicant_type IN ('doctor', 'pharmacy')`
- `case_type IN ('doctor_verification', 'pharmacy_verification')`
- pairing CHECK: exactly `doctor`/`doctor_verification` or
  `pharmacy`/`pharmacy_verification`

Existing open-case uniqueness, queue keyset, and history indexes are
unchanged. Direct PostgreSQL inserts of `doctor`+`pharmacy_verification` and
`pharmacy`+`doctor_verification` are rejected. A valid pharmacy/pharmacy
insert succeeds. A second open pharmacy case for the same applicant is
rejected by the existing uniqueness constraint. Submitted pharmacy documents
remain frozen.

## Pharmacy document requirement policy

`pharmacy_verification` is on the closed case-type allowlist.
`organization_registration_evidence` is the only pharmacy requirement. It is
an **ENGINEERING_DEFAULT** synthetic technical requirement for exercising the
pipeline. It is **not** government verification, pharmacy licensing
sufficiency, commercial-registry validity, or legal approval.

`professional_id` remains valid only for `doctor_verification`.
`organization_registration_evidence` remains valid only for
`pharmacy_verification`. Unknown case types and requirement codes deny.
PDF/JPEG/PNG, 20 MiB, scanner trust, quarantine, 900s upload expiry,
reviewer access TTL 120s, and existing rejection-reason policy are unchanged.

## API contracts

Applicant writes are server-derived. Clients cannot assign applicant ID,
organization ID, case type, verification status, reviewer, membership role,
lifecycle status, or business capabilities.

- `POST /api/v1/pharmacy-organizations/me/verification-cases`
  (`openOwnPharmacyVerificationCase`) — empty closed body; Idempotency-Key
  required; compact `{status, organization_id, case_id, case_status,
  case_version, organization_version}` ≤ 255 bytes
- `POST /api/v1/pharmacy-organizations/me/verification-submissions`
  (`submitOwnPharmacyVerification`) — `case_version` + `organization_version`;
  compact write adds `organization_verification_status`
- `GET /api/v1/pharmacy-organizations/me/verification-status`
  (`getOwnPharmacyVerificationStatus`) — own-only, non-enumerating

Existing doctor upload HTTP (`POST /api/v1/verification-uploads` and
complete/status) is reused. Actor type and case type are derived from the
authenticated pharmacy/doctor actor and the authoritative case. Doctor
operationIds were not renamed.

Admin queue default remains `doctor_verification`.
`case_type=pharmacy_verification` is an explicit filter. Reviewer JSON is a
discriminated `oneOf` on `case_type`. Doctor schemas gained optional
`applicant_type`; required doctor fields are unchanged.

Event: `packages/contracts/events/pharmacy/verification_decided.v1.schema.json`
— `organization_id`, `branch_ids` (minItems 1, maxItems 1), `case_id`,
`decision`, `reason_code`. No legal registration, legal name, address, phone,
coordinates, documents, object IDs/keys, reviewer notes, HMAC, ciphertext,
key versions, or scanner payloads. No `pharmacy.verification_submitted`.

Local `npm run contracts:breaking -- origin/main`: no breaking changes.
TypeScript client regenerated. Dart constants are gitignored and produced in
CI.

## Pharmacy aggregate transition matrix

Verification never writes `pharmacy_*` tables. `PharmacyApplicantService::transition`
owns the CAS updates (lock order: membership → organization → branch).

| Verification target | organization.verification_status | organization.status | initial branch | founding owner membership |
| --- | --- | --- | --- | --- |
| Onboarding (pre-case) | draft | draft | draft | pending |
| submit → pending_review | pending_review | pending | pending | pending |
| approved | approved | active | active | active |
| changes_requested | changes_requested | pending | pending | pending |
| rejected | rejected | pending | pending | pending |

`changes_requested` and `rejected` never activate organization, branch, or
membership. Resubmission uses a **new** case because
`VerificationCaseStatus` cannot return from CR/rejected to `pending_review`
on the same row (`allowsNewVerificationCase` on draft/CR/rejected). Failed
submit leaves the onboarding draft/draft/pending aggregate.

Phase-02 `active` is verified identity/organization state only.
`PharmacyVerificationStatus::confersBusinessCapability()` is always false.
Access does not gain inventory, purchasing, POS, catalog, or clinical names.

## Upload / scanner integration

`VerificationUploadService` now accepts `AccountType::Doctor` or
`AccountType::Pharmacy`. Ownership checks derive applicant type from the
actor and the authoritative case. Method names `createDoctorUpload` /
`replayDoctorUploadCreate` / `completeDoctorUpload` are unchanged for
compatibility. `VerificationUploadIdempotencyReplayHydrator` still stores
only the compact upload pointer and issues signed URLs at replay time; it
rehydrates pharmacy creates through the same hydrator.

Trusted inspection/scanning remains `VerificationUploadProcessor` +
`ProcessingTrustedDocumentEvidenceIssuer`. No pharmacy storage tables,
buckets, scanner adapters, or file subsystem were added.

## Admin reviewer backend

Existing Admin verification HTTP supports pharmacy cases when the caller
passes `case_type=pharmacy_verification`. Default queue remains doctor.
Claim, safe detail, document metadata, explicit document access, and
decision reuse the same MFA/privileged capability, optimistic case version,
idempotency, assignment gating, and signed reviewer URL behavior.

Self-review is denied using the authoritative pharmacy founding-owner
`user_id` from `PharmacyApplicantService`. Unknown applicant types fail
closed (no Doctor fallback). Reviewer-document metrics use the actual
`case_type`. Reviewer-safe pharmacy fields come only from
`PharmacyReviewerService` (public name, statuses, initial-branch public
identity).

Admin-web: compile-safe type guards
(`isDoctorVerificationQueueItem` / `isDoctorVerificationCase`) so the
existing React Admin workspace still requests and renders
`doctor_verification` only. Guards apply to queue items, GET case detail,
and claim responses. They must **not** wrap the compact decision HTTP
outcome (`AdminVerificationDecisionResult` has no `case_type`); GitHub
run 35541145448 Admin Playwright failed for that reason and was fixed
without adding pharmacy UI. No pharmacy filters, screens, or rendering.

## Transaction / rollback / concurrency

Submit and decide run inside `VerificationService` `TransactionRunner`
units: case CAS, Pharmacies aggregate CAS, audit, outbox. A failure in
decision insert, illegal pharmacy transition, audit, or
`pharmacy.verification_decided` outbox rolls the entire unit back. No
partial active organization/branch/membership remains.

PostgreSQL proofs:

- concurrent HTTP case open with distinct idempotency keys → one open case
- submit with stale case version → 409; aggregate stays draft
- submit with stale organization version → 409; case stays draft
- two reviewers claiming one pharmacy case → one assignee
- concurrent distinct decisions → one `verification_decisions` row
- identical concurrent decisions → replay, one event
- documents frozen after submission
- one decision per case

## Security / BOLA / self-review

- Pharmacy owner cannot open, upload to, submit, or inspect another
  pharmacy’s case.
- Doctor cannot use a pharmacy case or `organization_registration_evidence`.
- Pharmacy cannot use a doctor case or `professional_id`.
- Admin reviewer cannot self-review a pharmacy organization they own.
- Unassigned reviewers cannot access pharmacy document evidence.
- Another reviewer cannot consume assigned evidence through Admin APIs.
- Expired / rebound / wrong-case reviewer document grants remain denied.
- Mass assignment of applicant type, case type, statuses, reviewer ID, and
  business capabilities is rejected (422/404).
- Synthetic legal registration/name/address/phone canaries do not appear in
  Verification events, reviewer responses, logs, metrics, cursors, URLs,
  idempotency pointers, or audit metadata.

## Architecture

Deptrac: Verification may import Pharmacies public services (same as Doctors).
`ArchitectureBoundaryTest`: Verification/Admin do not query `pharmacy_*`
tables; Pharmacies does not query Verification tables or emit
`pharmacy.verification_decided` (Verification owns the event). Dispatch is
explicit `match` on `ApplicantType` / `AccountType`; unknown types fail
closed. No generic applicant persistence model.

## Doctor regression

Existing doctor verification tests remain in the full Core suite and were
not weakened: case lifecycle, upload/quarantine/scanning, Admin
queue/detail/claim/decision, document access/download, events. Admin-web
still requests `case_type=doctor_verification`. Doctor React Admin Playwright
is the CI `admin-web` job (`--project=admin-verification`); it was not
re-run on this host (no Playwright/browser dual-server fixture here).

## Commands actually executed

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --test` | passed |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 errors |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations, 0 uncovered, **2489** allowed |
| `./vendor/bin/pest` (full Core suite) | **739 passed**, 18 skipped, 757 tests, 14645 assertions |
| Focused pharmacy+constraint+invariants Pest | **52 passed** (839 assertions) |
| `npm run contracts:lint` | OpenAPI valid |
| `npm run contracts:events` | **21** event schemas checked |
| `npm run contracts:breaking -- origin/main` | no breaking changes |
| `npm run contracts:generate:ts` | generated client committed |
| `python3 scripts/ci/run-isr015-validators.py` | **PASS** |
| `npm run admin:typecheck` / `admin:lint` / `admin:test` / `admin:build` | tsc, eslint, **44** vitest passed, vite build |
| `npm run desktop:typecheck` | doctor-desktop and pharmacy-desktop tsc passed |
| `npm run desktop:test` | **83** + **83** vitest passed |

GitHub `pull-request` CI on this HEAD is recorded after the Draft PR run
completes. Local host PHP is supplementary and does not replace that run.

Gitleaks, Trivy image scans, and OpenVEX were **not** weakened. ISR-015
static validators passed locally; container Gitleaks/Trivy remain CI jobs.

Phase 02 as a whole is **not** PASS.

## Residual (this chunk)

- Pharmacy document requirements are superseded by v1.0.1-phase02
  (`pharmacy_facility_license`, `commercial_register`,
  `responsible_pharmacist_license`). This chunk historically used
  `organization_registration_evidence`.
- Decision/reason catalogues are superseded by v1.0.1-phase02.
- Reviewer document TTL 120s and queue page size 25/100 remain
  ENGINEERING_CONTROL.
- Signed GET URLs remain application-owned and bearer-style while valid.
- Approval still grants no inventory, POS, purchasing, catalog, clinical, or
  Phase-10 capability.
- Pharmacy Electron verification UX and Admin pharmacy rendering remain
  deferred.
- `identity:rotate-keys` still does not rotate pharmacy legal-name /
  registration / address envelopes (same residual as chunk 07).
- SF-001 remains MERGE_ONLY / `promotion_allowed=false`.
- Staging remains unprovisioned; post-merge deploy stays fail-closed.
