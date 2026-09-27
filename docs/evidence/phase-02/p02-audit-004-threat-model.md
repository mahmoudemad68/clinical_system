# P02-AUDIT-004 — Phase 02 threat-model evidence (not phase PASS)

Engineering evidence for **P02-AUDIT-004** only. This file does **not** mark
Phase 02 complete, does **not** close P02-AUDIT-003, does **not** start
P02-AUDIT-005/006/007, and does **not** claim G-08-04 or production
promotion.

| Field | Value |
| --- | --- |
| Starting main | `d16fcde5b07844f69547a7ed7be52187a44800d8` |
| Threat model | [phase-02-onboarding.md](../../threat-models/phase-02-onboarding.md) |
| Entry-point catalog | [phase-02-entry-points.md](../../threat-models/phase-02-entry-points.md) |
| Methodology | STRIDE + privacy; Phase 00/01 register fields; structural parser (not substring completeness) |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` — **G-08-04: OPEN / EXTERNAL_HUMAN** |
| SF-001 | **OPEN / UNCHANGED** (`extract-zip@2.0.1`) |
| Profile-correction | **OUT OF PRODUCT-POLICY SCOPE** — P02-T45 `EXTERNAL_POLICY_INPUT_REQUIRED` routed to P02-AUDIT-003 |
| Profile-claim enablement | **OUT OF SCOPE** (P02-AUDIT-005) — P02-T46 OPEN |

AI/agent authoring is engineering evidence, not independent human approval.

## Completeness

| Item | Count |
| --- | --- |
| Actors | 19 |
| Assets | 23 |
| Trust-boundary / data-flow edges | 17 |
| Threats | 52 |
| MITIGATED | 39 |
| PARTIAL | 8 |
| OPEN | 3 |
| NOT_APPLICABLE | 2 |
| HTTP Phase 02 entry points | 44 |
| Electron domain IPC channels | 33 (17 doctor + 16 pharmacy) |
| Non-HTTP security entry points | 19 |

`STATUS_COUNTS MITIGATED=39 PARTIAL=8 OPEN=3 NOT_APPLICABLE=2 TOTAL=52`

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
| P02-T45 | OPEN | **P02-AUDIT-003 / EXTERNAL_POLICY_INPUT_REQUIRED** profile-correction policy |
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

Satisfied as **engineering draft** by `phase-02-onboarding.md`. P02-AUDIT-003
**remains OPEN** because profile-correction policy is missing (P02-T45) and
independent acceptance is G-08-04.

## Proposed verdict

**READY_FOR_RE-QA** as engineering P02-AUDIT-004 remediation.

Not CLOSED. Not READY_TO_MERGE. Not Phase 02 PASS. G-08-04 not self-approved.
