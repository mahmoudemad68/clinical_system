# P02-AUDIT-004 — Phase 02 threat-model evidence (not phase PASS)

Engineering evidence for **P02-AUDIT-004** only. This file does **not** mark
Phase 02 complete, does **not** close P02-AUDIT-003, does **not** start
P02-AUDIT-005/006/007, and does **not** claim G-08-04 or production
promotion.

P02-AUDIT-004 originally closed as the Phase 02 threat-model engineering
register. The block below records that original register. A later
**QA-P02A003-014** section reconciles P02-T45 after the profile-correction
policy merge. This file is **not** rewritten as though the T45
reconciliation was the original P02-AUDIT-004 closer.

| Field | Value |
| --- | --- |
| Starting main | `d16fcde5b07844f69547a7ed7be52187a44800d8` |
| Threat model | [phase-02-onboarding.md](../../threat-models/phase-02-onboarding.md) |
| Entry-point catalog | [phase-02-entry-points.md](../../threat-models/phase-02-entry-points.md) |
| Methodology | STRIDE + privacy; Phase 00/01 register fields; structural parser (not substring completeness) |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` — **G-08-04: OPEN / EXTERNAL_HUMAN** |
| SF-001 | **OPEN / UNCHANGED** (`extract-zip@2.0.1`) |
| Profile-correction | **RECONCILED** — P02-T45 MITIGATED by merged v1.0.2 Freeze Current Behavior; Product/Privacy/Security evidence exists; P02-AUDIT-003 `READY_FOR_RE_QA` (not CLOSED). Original P02-AUDIT-004 closure predated this reconciliation. |
| Profile-claim enablement | **OUT OF SCOPE** (P02-AUDIT-005) — P02-T46 OPEN |

AI/agent authoring is engineering evidence, not independent human approval.

## Completeness

| Item | Count |
| --- | --- |
| Actors | 19 |
| Assets | 23 |
| Trust-boundary / data-flow edges | 17 |
| Threats | 52 |
| MITIGATED | 40 |
| PARTIAL | 8 |
| OPEN | 2 |
| NOT_APPLICABLE | 2 |
| HTTP Phase 02 entry points | 44 |
| Electron domain IPC channels | 33 (17 doctor + 16 pharmacy) |
| Non-HTTP security entry points | 19 |

`STATUS_COUNTS MITIGATED=40 PARTIAL=8 OPEN=2 NOT_APPLICABLE=2 TOTAL=52`

Counts are **derived** from parsed threat rows by
`Phase02ThreatRegisterParser`. The human-readable summary must match.

## OPEN / PARTIAL threats (every one)

| ID | Status | Why not MITIGATED |
| --- | --- | --- |
| P02-T12 | PARTIAL | Membership revoke is tested; same-bearer stale capability after clinic/pharmacy revoke is not |
| P02-T23 | PARTIAL | Trailing-payload/active-PDF tests exist; no named polyglot corpus |
| P02-T25 | PARTIAL | Zip rejected; PDF page bound; no zip-bomb/PDF resource-bomb corpus |
| P02-T30 | PARTIAL | Isolation Vitest exists; packaged XSS-to-IPC not re-run. Non-blocking for P02-AUDIT-004 completeness; no invented audit ID |
| P02-T31 | PARTIAL | Origin allowlist unit tests; no live hostile `senderFrame`. Non-blocking for P02-AUDIT-004 completeness |
| P02-T33 | PARTIAL | Handle clear on logout-equivalent; not full IPC after `auth.logout`. Non-blocking for P02-AUDIT-004 completeness |
| P02-T39 | PARTIAL | No public geo search (reduces exposure); no adversarial PostGIS corpus |
| P02-T50 | PARTIAL | Claim/no-bulk-export tested; reviewer volume alerts not proven |
| P02-T46 | OPEN | **P02-AUDIT-005** claim enablement |
| P02-T49 | OPEN | **P02-AUDIT-006 / SF-001** unchanged |

P02-T47 dual approval is **NOT_APPLICABLE** (optional; policy v1.0.1 configures
no high-risk category). P02-T48 appeal is **NOT_APPLICABLE** (no route).

## MITIGATED evidence map

Every MITIGATED row points at a test or contract, not “looks safe”:

| ID | Evidence (primary) |
| --- | --- |
| T01 | `PatientProfileRaceTest`; `DoctorProfileRaceTest`; `PharmacyOrganizationRaceTest`; `AdminCreatedDoctorRaceTest` |
| T02 | Owned-NID collision tests; `IdentityAccessPortsTest` FeatureUnavailable |
| T03 | Non-disclosure assertions; registration-bound NID `matchesBoundIdentity`; `RedactionCanaryTest` |
| T04 | HMAC field 422; Phase 01 `IdentityKeyLifecycleTest` |
| T05 | Mass-assignment 422 suites listed in the threat model |
| T06 | Cross-doctor/pharmacy/patient IDOR 404 tests |
| T07 | `IdentityRulesTest`; AAL1 claim 404 |
| T08 | Own `/me` only tests |
| T09 | `ClinicLocationFlowsTest`; `ClinicStaffInvitationFlowsTest` |
| T10 | `PharmacyStaffInvitationFlowsTest` BOLA + FK 23503 |
| T11 | Invite/accept/revoke flows |
| T13 | Self-review HTTP/service tests (doctor + pharmacy + admin) |
| T14 | `AdminCreatedDoctorHttpTest`; Playwright two-admin split |
| T15 | Privileged AAL2 matrices |
| T16 | `ArchitectureBoundaryTest`; admin DTO allowlist |
| T17 | Server-owned status; capability denial for pending |
| T18 | Race/claim/decide tests |
| T19 | `VERSION_CONFLICT` 409 tests |
| T20 | `ApprovedVerificationPolicyV1Test`; `VerificationPolicyV1HttpTest` |
| T21 | `VerificationUploadFlowsTest`; `BoundedDocumentInspectorTest` |
| T22 | Magic vs extension tests |
| T24 | EICAR + DisabledScanObject fail-closed; Secure-file CI `36348605976` |
| T26 | HMAC signer tests; expiry/tamper/rebind 404 |
| T27 | Electron renderer omits PUT target; **admin browser holds** represented `upload_target.url`; `CreateDoctorPage.test.tsx` |
| T28 | Reconciler expire + rejected DELETE + AVAILABLE ingress DELETE; never scanning; never AVAILABLE canonical |
| T29 | Disabled issuer in API process; worker-only mint |
| T32 | `evidence-handles.test.ts` TTL/inode/path-free errors |
| T34 | `trust-boundary.test.ts` |
| T35 | Event/log canaries; redaction tap. **Metrics labels not claimed.** |
| T36 | Notes ciphertext; applicant/desktop omit notes |
| T37 | Address ciphertext; no public search |
| T38 | Cross-doctor location 404 + version |
| T40 | Concurrent onboard/unlinked race |
| T41 | Idempotent onboarding/upload tests |
| T42 | Historical decision rows retained |
| T43 | Rollback-on-audit-failure tests |
| T44 | `VerificationWorkerAuditIdentityTest` |
| T45 | `docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.v1.0.2-phase02.json` SHA-256 `1961be59aa3ea0ab2e712ebc854d15a23343ca03485d81155aa4c36627c35e37`; `docs/evidence/phase-02/p02-audit-003-profile-correction-policy.md`; `Phase02PatientProfileCorrectionPolicyAlignmentTest`; `Phase02PatientProfileCorrectionPolicyHttpAlignmentTest` |
| T51 | Anonymous deny; source-built MinIO on merged main |
| T52 | Unlinked services have no HTTP; capability default-deny |

## Planned vs implemented (summary)

Canonical short list in the phase document is implemented under `/api/v1`.
Additional implemented routes (case-open, upload status, branch/membership
CRUD, invitation accept, admin claim/document-access/represented-doctor,
HMAC reviewer download, specialties) are catalogued in the entry-point file.
Intentionally internal: patient exact-match / unlinked create. Deferred:
appeal, profile-claim HTTP attach. Absent: public geo search, Inertia
verification pages. Dual-approval is optional and currently **not configured**.
`pharmacy.verification_submitted` is **NOT_EMITTED / NOT_CANONICALLY_REQUIRED**.

## Validator

ISR-016 repository completeness for Phase 02 is
`Phase02CompletenessValidator`: parsed threat tables, derived status counts,
HTTP/IPC set equality against `routes/api.php` and
`desktop_bridge_contracts`, jobs/storage/scanner entries, repository evidence
path existence, and G-08-04 OPEN wording. There is no separate
`scripts/ci/isr016*.py`. Negative mutations live in
`Phase02ThreatModelCompletenessValidatorTest`.

## P02-AUDIT-003 threat-model component

**Original P02-AUDIT-004 closure (historical):** the threat-model register was
an engineering draft. At that time P02-AUDIT-003 remained open because
profile-correction Product/Privacy/Security policy evidence had not yet been
merged. That original 004 closure is **not** restated by this file as a new
event, and this reconciliation PR is **not** the original closer of
P02-AUDIT-004.

**Current after QA-P02A003-014 (evidence-only):** profile-correction policy
v1.0.2 Freeze Current Behavior is merged
(`docs/evidence/phase-02/reference-data/phase02-patient-profile-correction-policy.v1.0.2-phase02.json`,
SHA-256 `1961be59aa3ea0ab2e712ebc854d15a23343ca03485d81155aa4c36627c35e37`;
`docs/evidence/phase-02/p02-audit-003-profile-correction-policy.md`).
P02-T45’s missing-policy condition is **resolved** (MITIGATED). Product,
Privacy, and Security policy evidence now exists. The policy reflects current
runtime behavior (`FREEZE_CURRENT_BEHAVIOR`). `OPEN_LEGAL_DECISION` remains
non-blocking under the canonical Phase 02 gate.

**P02-AUDIT-003 is `READY_FOR_RE_QA`**, not CLOSED. Final audit closure
remains subject to independent QA of this reconciliation. Independent
acceptance of the threat model remains G-08-04 OPEN / EXTERNAL_HUMAN.

## Proposed verdict

**Original P02-AUDIT-004:** engineering register **CLOSED** before this work
(READY_FOR_RE-QA as the original 004 remediation, then independently
accepted as the threat-model component). This PR does **not** re-close
P02-AUDIT-004 and does **not** recast T45 reconciliation as that original
closure.

**QA-P02A003-014 (this change):** evidence-only T45 reconciliation
`REMEDIATED_AWAITING_RE_QA`. P02-T45 `RECONCILED_AWAITING_RE_QA`.
P02-AUDIT-003 `READY_FOR_RE_QA`.

Not READY_TO_MERGE. Not Phase 02 PASS. G-08-04 not self-approved.
P02-AUDIT-005 remains OPEN. P02-AUDIT-006 remains OPEN / UNCHANGED.
P02-AUDIT-007 remains OPEN / EXTERNAL_HUMAN.

## Current linked authority after later SF-001 / P02-AUDIT-006 acceptance

This section is later than the original P02-AUDIT-004 register and later than
QA-P02A003-014. It does **not** change P02-T49 Status `OPEN` in the snapshot
table above, and it does **not** recast QA-P02A003-014 as having closed
P02-AUDIT-006.

- **P02-T49 historical state:** `OPEN` at snapshot. The OPEN table still lists
  P02-T49 OPEN next to P02-T46 (`OPEN=2`).
- **Current linked authority:** `infra/security/exceptions/SF-001.json`
  (graph ABSENT; P02-AUDIT-006 CLOSED). See
  [p02-audit-006-sf001-human-acceptance.md](p02-audit-006-sf001-human-acceptance.md).
  The original register identity for **SF-001** in this file remains
  `OPEN / UNCHANGED`.
- **G-08-04:** remains OPEN / EXTERNAL_HUMAN.
- **P02-AUDIT-007:** remains OPEN / EXTERNAL_HUMAN.
- **P02-AUDIT-005 / Profile Claim:** remains OPEN / disabled. Closing SF-001
  does not enable Profile Claim.
