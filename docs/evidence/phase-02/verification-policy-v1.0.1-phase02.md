# Phase 02 Verification Policy v1.0.1-phase02

This file records installation of the independently approved Phase 02
Verification Policy. It does **not** mark Phase 02 complete, does **not**
close P02-AUDIT-003 or P02-AUDIT-008, and does **not** claim production
promotion.

This is **not** Chunk 18. It does not start P02-AUDIT-004 threat-model
evidence, P02-AUDIT-005 profile-claim enablement, P02-AUDIT-006 / SF-001,
P02-AUDIT-007 / G-08-04, Chunk 17 performance work, or the approved
specialty catalogue from PR #28.

## Classification

- P02-AUDIT-003 — verification requirement / reason catalogue
- P02-AUDIT-008 — related verification-policy alignment

Both remain **OPEN** until independent QA closes them. This change only
installs the approved policy as an immutable artifact plus config/UI/API
adaptations that preserve the existing verification architecture.

These are **product-, privacy-, and security-approved Phase 02 catalogues**.
They are **not** government, syndicate, licensing-authority, or
registry-provider approval. The platform does **not** perform automated
government, syndicate, license, or commercial-register verification.

## Approved source

| Field | Value |
| --- | --- |
| Version | `v1.0.1-phase02` |
| Supersedes | `v1.0.0-phase02` |
| Release date | `2026-09-24` |
| Status | `APPROVED_PRODUCTION_POLICY` |
| Product approver | `prod-gov-lead@system.internal` |
| Privacy approver | `privacy-dpo@system.internal` |
| Security approver | `ciso-gov@system.internal` |
| Artifact | `docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.json` |
| SHA-256 companion | `docs/evidence/phase-02/reference-data/phase02-verification-policy.v1.0.1-phase02.sha256` |
| SHA-256 | `a5cdab95e446224b57407fb017a7c5b32a8f494a7cade6b0d0e274ceb1ea281d` |
| Supersession reason | alignment with Phase 02 append-only architecture and existing authorization/retention boundaries |

Runtime code does not load the repository JSON. PHP
(`ApprovedVerificationPolicyV1`) and TypeScript
(`@clinic/verification-policy`) mirror the catalogues. Exact correspondence
is proven by tests.

## Doctor requirements

| Code | Required | English label |
| --- | --- | --- |
| `medical_license` | yes | Professional Medical License |
| `national_id_or_passport` | yes | Government Photo ID |
| `syndicate_card` | no | Medical Syndicate Membership Card |

Unknown requirement codes deny. Submit cannot proceed until every required
requirement has AVAILABLE + clean evidence. Missing optional `syndicate_card`
does not block submit.

## Pharmacy requirements

All three are required:

- `pharmacy_facility_license`
- `commercial_register`
- `responsible_pharmacist_license`

## Decision / reason catalogue

| Reason | Allowed decisions |
| --- | --- |
| `approved` | `approved` |
| `docs_blurry_or_illegible` | `changes_requested` |
| `missing_required_docs` | `changes_requested` |
| `identity_mismatch` | `changes_requested` only (not `rejected`) |
| `license_expired` | `changes_requested` |
| `fraudulent_or_altered_doc` | `rejected` |
| `unauthorized_entity` | `rejected` |

Withdrawn codes (`evidence_incomplete`, `documents_illegible`, and any other
code absent from v1.0.1) are rejected for **new** decisions. Historical
`verification_decisions.reason_code` rows remain readable and are not
rewritten. Applicant UI presents the approved `applicant_safe_explanation`
(Arabic source string from the artifact) rather than a raw code.
Reviewer notes stay private.

## Resubmission, appeal, reviewer authorization, retention

Resubmission remains append-only: the decided case stays immutable; a new
draft case is opened; a new submission is created. No attempt counters, no
waiting-period scheduler, no reopening of decided cases.

Appeal is **deferred** and unsupported in Phase 02 (`allowed: false`). No
appeal endpoint, table, state machine, role, or UI is introduced.

Reviewer authorization is unchanged: Admin account type +
`verification.case.review` + privileged AAL2. Self-review and creator-review
remain prohibited. No reviewer RBAC/qualification subsystem is introduced.

Quarantine abandoned/expired/failed/rejected objects remain cleanup-eligible
after **86400** seconds. Canonical submitted evidence is retained. Decisions
and reviewer notes remain append-only. Long-term lifecycle policy (multi-year
timers, archive tiers, legal hold, canonical deletion) is deferred.

## Technical upload limits

| Limit | Classification |
| --- | --- |
| Max document size 20_971_520 | ENGINEERING_CONTROL |
| MIME PDF / JPEG / PNG | APPROVED_AS_POLICY |
| Upload grant expiry 900s | ENGINEERING_CONTROL |
| Max active uploads per requirement 3 | APPROVED_AS_POLICY |
| Reviewer document access TTL 120s | ENGINEERING_CONTROL |

## Backward compatibility

No schema redesign and no policy-version-per-case column were required.

- New grants and new draft/submit paths accept only v1.0.1 requirement codes.
- Document processor still **recognizes** historical
  `professional_id` / `organization_registration_evidence` so already-issued
  scans can complete. Those codes cannot be used for new grants.
- Submit requires the live v1.0.1 required set. Unsubmitted drafts that only
  have a withdrawn code cannot submit until the new required evidence is
  uploaded.
- Already-submitted pending-review cases keep the snapshot accepted at
  submit. `recordDecision` does **not** re-apply the live required-document
  catalogue, so a pending case with only `professional_id` remains
  reviewable. Claim does not re-check required documents.
- Historical documents and decisions remain readable because
  `requirement_code` and `reason_code` are format-checked strings, not closed
  database enums. Historical rows are not rewritten.

This is a small compatibility change, not a state-machine redesign.

## Tests

- `apps/core-api/tests/Unit/Verification/ApprovedVerificationPolicyV1Test.php`
- `apps/core-api/tests/Feature/Verification/VerificationPolicyV1HttpTest.php`
- `@clinic/verification-policy` correspondence tests
- Doctor / Pharmacy desktop and Admin web catalogue tests
- Existing AAL2, self-review, creator-review, retention, and live-flow
  regressions remain in the Verification / Admin suites

## Phase 02 status

Phase 02: **NOT PASS**

P02-AUDIT-003: **READY_FOR_INDEPENDENT_QA** (OPEN; not closed by this change)

P02-AUDIT-008: **READY_FOR_INDEPENDENT_QA** (OPEN; not closed by this change)
