# P02-AUDIT-003 — Phase 02 patient profile-correction policy (not phase PASS)

Engineering evidence for **P02-AUDIT-003 / P02-T45** only. This file does
**not** mark Phase 02 complete, does **not** close P02-AUDIT-003, does
**not** start P02-AUDIT-005/006/007, and does **not** claim G-08-04 or
production promotion.

This change installs the controller-approved **Freeze Current Behavior**
policy as an immutable artifact plus runtime-alignment tests. It does
**not** change PATCH behavior, editable fields, validation, assurance,
revision storage, erasure, National ID mutability, or profile claim.

**v1.0.1-phase02** is the current approved policy. It **supersedes**
`v1.0.0-phase02` as an accuracy amendment only (QA-P02A003-001 request_id
origin and QA-P02A003-002 erasure/privacy residual disclosure). Freeze
Current Behavior is unchanged. v1.0.0 remains in tree as historical
evidence. Independent re-QA has **not** run.

| Field | Value |
| --- | --- |
| Starting main | `58cc9645c8858e883b2bd382f5a4b0727c2a2782` |
| Policy identity | Phase 02 Patient Profile Correction Policy |
| Current version | `v1.0.1-phase02` |
| Supersedes | `v1.0.0-phase02` |
| Amendment scope | Accuracy only: request_id/correlation origin and erasure residual disclosure. No policy behavior decision change. |
| Namespace | `phase02-patient-profile-correction` (not the verification-policy namespace) |
| Status | `APPROVED_PRODUCTION_POLICY` |
| Decision | `FREEZE_CURRENT_BEHAVIOR` |
| Release date | `2026-09-28` |
| Current artifact | [phase02-patient-profile-correction-policy.v1.0.1-phase02.json](reference-data/phase02-patient-profile-correction-policy.v1.0.1-phase02.json) |
| Current SHA-256 companion | [phase02-patient-profile-correction-policy.v1.0.1-phase02.sha256](reference-data/phase02-patient-profile-correction-policy.v1.0.1-phase02.sha256) |
| Current SHA-256 | `029f1fc5ee1246e2431ef5ddab77c305a84c8fceb5c3f1431261a35da093fb65` |
| Historical artifact (immutable) | [phase02-patient-profile-correction-policy.v1.0.0-phase02.json](reference-data/phase02-patient-profile-correction-policy.v1.0.0-phase02.json) |
| Historical SHA-256 | `b9efdd1d2a5b92e048f1eea33003da8d80b39160a8d183164091ee3eeca6e19a` |
| Independent QA | `PENDING` (re-QA not yet performed) |
| **G-08-04** | **`OPEN` / `EXTERNAL_HUMAN`** |
| SF-001 | **OPEN / UNCHANGED** (`extract-zip@2.0.1`) |
| Profile claim | **OUT OF SCOPE** (P02-AUDIT-005) — fail-closed/off |

AI/agent authoring is engineering evidence, not independent human approval.

## Canonical requirement

Phase 02 measurable exit gate:

> Threat-model, data-retention, document-requirement, reviewer-separation,
> and profile-correction policies have documented product/security/privacy
> evidence. Missing legal sign-off never blocks phase completion.

This artifact supplies the **profile-correction** policy evidence for that
gate. It does not close the other four items by itself. Threat-model
evidence for P02-AUDIT-004 is already on main. Data-retention,
document-requirement, and reviewer-separation live in the verification
policy (`v1.0.1-phase02`).

## Historical 003/008 label

The historical verification-policy evidence file
[`verification-policy-v1.0.1-phase02.md`](verification-policy-v1.0.1-phase02.md)
labels P02-AUDIT-003 as the verification requirement/reason catalogue and
P02-AUDIT-008 as related verification-policy alignment. Later QA maps
**P02-AUDIT-003 to profile-correction (P02-T45)** and treats the
verification catalogues as P02-AUDIT-008. That historical file is **not**
rewritten here. This document is the dedicated P02-AUDIT-003
profile-correction evidence.

## Approved Freeze Current Behavior decision

The controller approved **Freeze Current Behavior** as production policy
input for P02-T45. That decision **approves the currently implemented
Phase 02 self-correction surface**. It is **not** a mandate to add
stricter workflow (AAL2, rate limits, idempotency keys, staff correction,
re-verification, supporting documents, notifications, appeal, dual review,
or encryption rewrite).

v1.0.1 does **not** change that decision. It only corrects two accuracy
gaps in the v1.0.0 write-up.

## Accuracy amendment (v1.0.1)

| Finding | Engineering disposition | Independent QA |
| --- | --- | --- |
| QA-P02A003-001 (Low policy factual error: `request_id` origin) | CLOSED in v1.0.1 by factual request-id correction | `REMEDIATED_AWAITING_RE_QA` |
| QA-P02A003-002 (Low omitted privacy residual) | CLOSED in v1.0.1 by erasure/privacy residual disclosure | `REMEDIATED_AWAITING_RE_QA` |
| QA-P02A003-003 | Unchanged (Low tooling; non-blocking follow-up) | OPEN |
| QA-P02A003-004 | Unchanged (Low tooling; non-blocking follow-up) | OPEN |
| QA-P02A003-005 | Unchanged (Cosmetic tooling; non-blocking follow-up) | OPEN |
| Observations 006–010 | Unchanged (product/environment observations; not remediated here) | OPEN |

Engineering “CLOSED” means the inaccurate sentences were replaced in
v1.0.1. It is **not** independent QA closure. This amendment does **not**
claim re-QA has happened.

## Approval layers (must not be collapsed)

| Layer | Value | Meaning |
| --- | --- | --- |
| Controller-approved policy decision | `FREEZE_CURRENT_BEHAVIOR` | Product-policy decision for this freeze |
| Repository governance attribution | Product / Privacy / Security emails below | Same identities already used on the approved verification-policy artifact |
| Independent QA | `PENDING` | P02-AUDIT-003 is not closed by this PR; awaiting re-QA |
| G-08-04 | `OPEN / EXTERNAL_HUMAN` | Not claimed approved |

### Governance identities actually used

Verified against
[`phase02-verification-policy.v1.0.1-phase02.json`](reference-data/phase02-verification-policy.v1.0.1-phase02.json)
`approved_by` (the established Product/Privacy/Security attribution for
approved Phase 02 policy artifacts):

| Domain | Identity |
| --- | --- |
| Product | `prod-gov-lead@system.internal` |
| Privacy | `privacy-dpo@system.internal` |
| Security | `ciso-gov@system.internal` |

No new approver was invented. `docs/governance/accountable-owners.md`
records human owner concentration (Mahmoud) and lost assessor/remediator
separation; it is **not** used as a substitute G-08-04 approval.

## Mapping from policy to implementation

The freeze matches current runtime. Tests compare the artifact against
implementation sources, not against this Markdown.

| Policy | Runtime source |
| --- | --- |
| Closed seven-field allowlist | `UpdateOwnDemographics::EDITABLE`; `DemographicRules::patch()` minus `version`; OpenAPI `PatientDemographicsPatchRequest`; revision CHECK; `PatientDemographicRevisionRecorder::CREATION_FIELDS` |
| Unknown properties default-deny | `ClosedJsonValidator`; OpenAPI `additionalProperties: false` |
| National ID / identifiers / status / internals / caller reason-source excluded | PATCH schema and closed JSON; service never writes those columns from caller input |
| `NO_STAFF_OR_ADMIN_DEMOGRAPHIC_CORRECTION_SURFACE` | Only `PATCH /api/v1/patients/me/demographics`; no admin/doctor/pharmacy/reviewer demographic correction route |
| Name/DOB do not trigger re-verification | `UpdateOwnDemographics` does not call `VerificationService` |
| Reason `self_correction` (server-controlled) | Hardcoded in `UpdateOwnDemographics`; caller cannot supply `reason_code` |
| Source `self_onboarding` (server-controlled) | Hardcoded in `UpdateOwnDemographics`; caller cannot supply `source_type` |
| Actor identity (server-derived from authentication) | `PatientProfileController::actor()` reads authenticated `ActorContext`; revision `actor_id` is `$actor->userId` |
| Request / correlation ID | `AssignCorrelationId` may adopt a valid caller `X-Request-Id` (lowercase UUIDv7 via `Identifier::fromString`); otherwise the server generates one. `PatientProfileController::requestId()` reads request attribute `correlation_id`. Revision/audit paths persist that identifier. It is correlation metadata, not proof of caller identity or trustworthy provenance by itself. |
| AAL1 accepted; pending-phone denied; own linked profile | `DefaultDenyAuthorizer` + `findByUserId`; no AAL2 step-up on the PATCH |
| Version required; `VERSION_CONFLICT`; one success / one conflict | Service CAS + `PatientProfileRaceTest` |
| No HTTP idempotency key | PATCH route is **not** behind `platform.idempotency` (onboarding POST is) |
| Append-only revisions | Trigger `patient_demographic_revisions_no_update_delete`; app INSERT only; `clinic_app` has SELECT/INSERT only |
| `full_name` history protected; other editable fields plaintext | `UpdateOwnDemographics::diff()` |
| `full_name` supplied always revises | `diff()` returns a protected rewrite without a semantic-equality skip |
| Live-profile erasure | `PostgresPatientProfileStore::eraseLinkedProfiles`: unlink `user_id`, tombstone NID ciphertext/HMAC and name ciphertext, set status archived. Does **not** rewrite non-name plaintext demographics |
| Revision ledger erasure | `PatientSubjectHoldings` `PreserveSecurityAudit`; erasure does not UPDATE/DELETE revision rows |
| No status=`active` correction gate | `UpdateOwnDemographics` does not inspect profile status |
| Stored DOB may disagree with NID-encoded date | `NationalId` parses an encoded date for format only; correction does not compare it to `date_of_birth` |
| Profile claim remains off | `PlatformFeatures::IDENTITY_PROFILE_CLAIM` production force-off |

## Approved self-edit allowlist (closed / default-deny)

- `full_name`
- `gender`
- `date_of_birth`
- `height_cm`
- `weight_kg`
- `marital_status`
- `blood_type`

Unknown demographic properties remain rejected.

## Immutable / excluded on this PATCH

The demographic PATCH does **not** permit correction of:

- `national_id`
- `user_id`
- profile/patient identifiers (`patient_id`)
- `status`
- ciphertext / HMAC / key-version internals
- caller-supplied `reason_code`
- caller-supplied `source_type`

National ID remains the immutable identity-bound identifier on this
surface.

## Staff/admin correction

`NO_STAFF_OR_ADMIN_DEMOGRAPHIC_CORRECTION_SURFACE`

No doctor, secretary, pharmacy, admin, or reviewer correction workflow is
introduced. The existing absence is intentional for Phase 02.

## Identity re-verification

Changes to `full_name` or `date_of_birth` **do not** trigger identity
re-verification in Phase 02 because:

- those values remain self-asserted demographic attributes on this surface
- National ID remains the immutable identity-bound identifier
- this policy does **not** assert that name/DOB have been verified against
  an identity document

The policy does **not** claim DOB/NID consistency; code does not enforce
it.

## Reason and provenance

| Field | Phase 02 value |
| --- | --- |
| Correction reason | `self_correction` (**server-controlled**; not caller-supplied) |
| Source | `self_onboarding` (**server-controlled**; not caller-supplied) |
| Actor identity | authenticated user (`actor_type=user`), **server-derived from authentication** |
| Request / correlation ID | **may originate from a caller-supplied valid `X-Request-Id`**. Malformed values are replaced by a server-generated UUIDv7. This is **correlation metadata**, not proof of caller identity and not trustworthy provenance by itself. |

No Phase 02 reason catalogue or free-text correction reason is required.

## Authentication / assurance

- authenticated live session
- pending-phone denied
- own linked patient profile
- current AAL1/password assurance accepted
- no AAL2/step-up for Phase 02 demographic correction

## Concurrency and authorization

Retained: own-profile authorization, default-deny unknown JSON, closed
schema, required profile version, optimistic concurrency, concurrent
same-version → one success / one conflict. HTTP idempotency is **not**
added.

## Revision history and privacy

Append-only. Application update/delete of revision rows is prohibited.
The application role cannot UPDATE/DELETE revision rows. Every allowed
changed field can produce revision evidence. Actor / request / provenance
metadata is recorded as implemented. No revision read API is introduced.
No Phase 02 correction-history deletion or rewrite workflow exists.

| Field | History representation |
| --- | --- |
| `full_name` | protected/encrypted old/new; plaintext columns null |
| other currently editable fields | plaintext `old_plain` / `new_plain` (**named privacy residual**) |

No migration or encryption rewrite is authorized.

## Erasure / retention

Subject erasure **tombstones/unlinks the live patient profile as
implemented**. It does **not** remove all personal data.

Current engineering behavior:

- unlink `user_id` on the linked live profile
- tombstone National ID ciphertext/HMAC and live `full_name` ciphertext
- set profile `status` to archived
- **do not** rewrite non-name live-profile plaintext demographics
  (`gender`, `date_of_birth`, `height_cm`, `weight_kg`, `marital_status`,
  `blood_type`)
- **do not** rewrite or delete `patient_demographic_revisions`

Surviving classes after current erasure (accepted Phase 02 residuals):

- historical demographic revision records remain
- non-name demographic values in revision history may remain plaintext
- plaintext demographic values on the archived/tombstoned patient profile
  that current erasure does not rewrite may survive
- historical `actor_id` in revision records may survive erasure
- encrypted historical `full_name` values may survive
- revision history remains protected security/audit evidence
- application role cannot UPDATE/DELETE revision rows
- no Phase 02 correction-history deletion/rewrite workflow exists

Statutory/legal retention duration: **`OPEN_LEGAL_DECISION`**. That is
distinct from Phase 02 engineering retention (`PreserveSecurityAudit` /
irreversible live-profile tombstone). Missing legal sign-off does **not**
block the canonical Phase 02 gate.

## Deferred (not required for Phase 02)

- supporting documents for demographic correction
- staff-assisted correction
- dual review
- correction appeal/dispute workflow
- duplicate-profile merge workflow
- correction notifications
- velocity-specific controls
- identity re-verification workflow
- correction-specific AAL2
- client-selected reason catalogue

## Accepted residuals (not fixed)

All classified `ACCEPTED_PHASE02_RESIDUAL`:

1. no server-side `status=active` correction gate
2. no PATCH idempotency key
3. no correction-specific velocity/rate control
4. AAL1 rather than AAL2
5. stored DOB may disagree with the date encoded in National ID
6. `full_name` may produce revision/version activity when supplied even if semantically unchanged
7. non-name revision values may remain plaintext
8. encrypted historical name may survive subject erasure
9. archived/tombstoned live-profile plaintext demographics that erasure does not rewrite may survive
10. historical revision `actor_id` may survive subject erasure
11. no staff-assisted correction path
12. no correction notification
13. no identity re-verification after name/DOB correction

## Profile-claim boundary

Profile correction policy does **not** enable profile claim.

**P02-AUDIT-005: OPEN**

`FEATURE_IDENTITY_PROFILE_CLAIM` remains fail-closed/off according to
current production policy.

## Other audit boundaries

| ID | Status |
| --- | --- |
| P02-AUDIT-006 / SF-001 | **OPEN / UNCHANGED** (`extract-zip@2.0.1`) |
| P02-AUDIT-007 / G-08-04 | **OPEN / EXTERNAL_HUMAN** |

This policy does not accept either. This amendment does **not** satisfy
G-08-04.

## Tests

Runtime-alignment tests load the JSON artifact and compare it to
implementation constants, schema, routes, middleware, erasure code, and
(where needed) HTTP behavior. They do **not** pass merely because Markdown
repeats JSON.

- `apps/core-api/tests/Support/ProfileCorrection/Phase02PatientProfileCorrectionPolicyArtifact.php`
- `apps/core-api/tests/Unit/Patients/Phase02PatientProfileCorrectionPolicyAlignmentTest.php`
- `apps/core-api/tests/Feature/Patients/Phase02PatientProfileCorrectionPolicyHttpAlignmentTest.php`

Existing patient profile / race / authorization / revision / erasure tests
remain the behavioral proof for concurrency, append-only storage, and
subject erasure.

## Relationship to P02-T45 / P02-AUDIT-003

| Item | Status after this change |
| --- | --- |
| P02-T45 | `APPROVED_POLICY_EVIDENCE_IMPLEMENTED_AWAITING_RE_QA` |
| P02-AUDIT-003 | `READY_FOR_RE_QA` (not CLOSED, not READY_TO_MERGE) |
| QA-P02A003-001 | `REMEDIATED_AWAITING_RE_QA` |
| QA-P02A003-002 | `REMEDIATED_AWAITING_RE_QA` |
| QA-P02A003-003 / 004 / 005 | OPEN (non-blocking tooling/cosmetic; not in this cycle) |
| Observations 006–010 | OPEN / UNCHANGED |
| P02-AUDIT-005 | OPEN |
| P02-AUDIT-006 | OPEN / UNCHANGED |
| P02-AUDIT-007 | OPEN / EXTERNAL_HUMAN |

Not CLOSED. Not READY_TO_MERGE. Not Phase 02 PASS. G-08-04 not
self-approved. Independent re-QA has not happened.

Phase 02: **NOT PASS**
