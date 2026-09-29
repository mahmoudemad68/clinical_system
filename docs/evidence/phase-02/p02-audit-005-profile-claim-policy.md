# P02-AUDIT-005 — Phase 02 Profile-Claim Policy v1 (not phase PASS)

Engineering evidence for **P02-AUDIT-005 / P02-T46** only. This file
records the Controller-frozen **Hybrid Profile Claim Policy v1**. It does
**not** mark Phase 02 complete, does **not** close P02-AUDIT-005, does
**not** change T46 from OPEN, and does **not** implement a claim ceremony, does
**not** enable `FEATURE_IDENTITY_PROFILE_CLAIM`, does **not** remove the
production hard-off, does **not** start P02-AUDIT-006/007, and does **not**
claim G-08-04 or production promotion.

This change installs an immutable policy artifact plus alignment tests. It
does **not** change `LinkVerifiedPatientAccount`, onboarding attach
behavior, OTP verification, migrations, routes, clients, monitoring, or
runbooks.

**v1.0.0-phase02** is the first recorded Profile-Claim policy version. It
has never been merged to `main`. Closed independent-QA items QA-P02A005-001,
QA-P02A005-003, QA-P02A005-004, and QA-P02A005-005 remain closed. Remaining
alignment-tooling findings are corrected **in place** on this same unmerged
artifact. There is no `v1.0.1`.

| Field | Value |
| --- | --- |
| Starting main | `4a1d8a844fd7c0cb7883aa6bf81e0cfe0bd7abdb` |
| Starting tree | `ac49e2a758024600552f23195a10a7b97d730af9` |
| Policy identity | Phase 02 Profile Claim Policy |
| Version | `v1.0.0-phase02` |
| Namespace | `phase02-profile-claim` (not the profile-correction or verification namespaces) |
| Model | `HYBRID_PROFILE_CLAIM` |
| Decision state | `CONTROLLER_POLICY_V1_FROZEN` |
| Status | **not** `APPROVED_PRODUCTION_POLICY` |
| Classification | `POLICY_DECISIONS_RECORDED_AWAITING_EXTERNAL_GOVERNANCE_AND_IMPLEMENTATION` |
| Controller | Mahmoud |
| Release date | `2026-09-28` |
| Artifact | [phase02-profile-claim-policy.v1.0.0-phase02.json](reference-data/phase02-profile-claim-policy.v1.0.0-phase02.json) |
| SHA-256 companion | [phase02-profile-claim-policy.v1.0.0-phase02.sha256](reference-data/phase02-profile-claim-policy.v1.0.0-phase02.sha256) |
| SHA-256 | `3f704817002c0b169bb80cf0026dad0a78e0da950919d8fb52c64de5b42e4396` |
| Superseded unmerged digest | `36a7a20cc96b18cf421209ec69de5b0554a59d0a3f8ddbfd85a396309ae5050a` (not authoritative) |
| Production enablement | `NOT_AUTHORIZED` |
| Feature state | `DISABLED` |
| Product approval | `PENDING_EXTERNAL` |
| Security approval | `PENDING_EXTERNAL` |
| Privacy approval | `PENDING_EXTERNAL` |
| Support/Operations approval | `PENDING_EXTERNAL` |
| **P02-AUDIT-005** | **`OPEN`** |
| **T46** | **`OPEN`** |
| Independent enablement | `EXTERNAL_HUMAN` |
| **G-08-04** | **`OPEN` / `EXTERNAL_HUMAN`** |
| SF-001 / P02-AUDIT-006 | **OPEN / UNCHANGED** (`extract-zip@2.0.1`) |
| P02-AUDIT-007 | **OPEN / EXTERNAL_HUMAN** |

AI/agent authoring is engineering evidence, not independent human approval.

## Why this audit needed policy input

Discovery for P02-AUDIT-005 found that T46 stayed OPEN because a live claim
ceremony without an approved assurance policy is out of scope, and because
Product/Security/Privacy/Support had not recorded the missing PC-001
through PC-022 decisions. Repository evidence returned
`EXTERNAL_POLICY_INPUT_REQUIRED`.

This artifact converts that gap into:

`POLICY_DECISIONS_RECORDED_AWAITING_EXTERNAL_GOVERNANCE_AND_IMPLEMENTATION`

Independent engineering QA of the first unmerged candidate returned
`POLICY_V1_REMEDIATION_REQUIRED`. This in-place correction is:

`POLICY_V1_REMEDIATED_AWAITING_INDEPENDENT_RE_QA`

It does **not** convert T46 to MITIGATED or CLOSED. T46 still lacks
ceremony implementation, production-reachable attach, implementation
evidence, independent QA, and external enablement/governance evidence.

## Four layers (must not be collapsed)

| Layer | State after this PR |
| --- | --- |
| A. Controller policy freeze | Completed by this artifact |
| B. Engineering implementation | **Not** completed |
| C. Independent engineering QA | **Not** completed (re-QA of this remediation is pending) |
| D. External governance / human review | Product, Security, Privacy, and Support/Operations remain `PENDING_EXTERNAL`. **G-08-04 / P02-AUDIT-007 remains `OPEN` / `EXTERNAL_HUMAN`** |

Controller selection of Hybrid Policy v1 is **not** independent
Security/Privacy approval and is **not** Product/Security/Privacy/Support
sign-off for production. A Controller freeze is not Product approval, is
not Security approval, is not Privacy approval, and is not
Support/Operations approval.

Independently frozen Pest expected maps under
`apps/core-api/tests/Support/ProfileClaim` are test oracles only. They are
not governance evidence and do not approve this policy.

## This policy does not inherit Profile-Correction approval

P02-AUDIT-003 recorded Freeze Current Behavior for demographic
self-correction (`v1.0.2-phase02`, SHA-256
`1961be59aa3ea0ab2e712ebc854d15a23343ca03485d81155aa4c36627c35e37`). That
artifact explicitly states that it does not enable profile claim.

This Profile-Claim artifact:

- uses a distinct namespace (`phase02-profile-claim`)
- is **not** labelled `APPROVED_PRODUCTION_POLICY`
- does **not** copy Product/Privacy/Security approver identities from the
  correction or verification policies as if those identities approved
  Profile Claim
- does **not** reuse or inherit that approval

## Hybrid model

High-confidence cases may **eventually** use self-service attach.

Anything missing, invalid, expired, reused, ambiguous, rate-limited,
risk-flagged, ineligible, or otherwise outside the high-confidence path
must go to internal Manual Review using the existing non-enumerating
client contract (`manual_review_required`, or hidden `NOT_FOUND` where
that envelope already applies).

**Current runtime remains dark.** Recording Hybrid is not a live control.
Feature state remains `DISABLED`. Production enablement remains
`NOT_AUTHORIZED`.

## Why the additional proof is a clinic-issued claim credential

ADR 0011 already forbids treating National ID plus a newly verified phone
as sufficient entitlement to an existing candidate. That pair authenticates
a new account; it does not prove possession of a historical walk-in row.
The accepted residual is SIM-swap plus National ID knowledge.

Date of birth, full name, and gender are printed on or derivable from the
Egyptian National ID (`NationalId` already parses the encoded date). They
are **not** independent additional proof. Blood type and other
demographics are also rejected as the v1 high-confidence factor.
Patient verification-document upload is not the v1 high-confidence path.

Policy v1 therefore requires a **clinic-issued, profile-bound, single-use
claim credential** in addition to National ID HMAC match, a **non-empty
matching account-bound National-ID identity**, and a fresh `profile_claim`
OTP. That extends ADR 0011; it does not weaken it.

## PC-002 high-confidence proof bundle

High-confidence self-service requires **all four** factors:

1. Canonical National-ID HMAC resolves exactly one Active unlinked target
   profile.
2. The claimant account has a **non-empty bound National-ID identity**
   representation, and that bound identity matches the target profile
   identity.
3. A fresh consumed `profile_claim` OTP exists on the claimant's
   already-verified phone.
4. A valid clinic-issued, profile-bound, single-use claim credential
   exists for that exact profile.

A missing account-bound National ID is **not** high-confidence and routes
to internal Manual Review with the same generic client contract. Treating
`matchesBoundIdentity` as true when the stored HMAC is null is **not** the
approved high-confidence rule.

DOB, name, gender, blood type, NID-derived demographics, NID+OTP alone,
and patient verification-document upload remain insufficient as independent
additional proof.

## Option B credential

| Rule | Policy v1 |
| --- | --- |
| Option | B |
| Encoding | Crockford Base32 |
| Length | **16** characters (~80-bit) |
| Alphabet | `0123456789ABCDEFGHJKMNPQRSTVWXYZ` |
| Excluded | `I L O U` |
| Display-only | `XXXX-XXXX-XXXX-XXXX` |
| Canonical secret | 16 characters, no separators |
| Generation | cryptographically secure random |
| Issuer | clinic |
| Bound to | exactly one patient profile |
| Comparison | case canonicalization; display-separator stripping; hyphens never enter the canonical secret or hash input |
| Show/print/send-once | once at issuance only |
| TTL | 30 days from issuance |
| Uses | 1 successful use |
| Storage | peppered-hash-only |
| Plaintext persistence | false |
| Issuance | future approved walk-in issuance only; not retroactive |

Plaintext of the claim credential is prohibited in: persistence, logs,
urls, audit_metadata, events, metrics, analytics, telemetry.

Option A (~50-bit) was rejected as too weak if hashes leak or rate limits
fail. Option C (~100-bit) was rejected as unnecessary typing cost.

**These rules are recorded. They are not implemented in this PR.**

## Non-enumeration

The client must not learn:

- nid_exists
- nid_does_not_exist
- profile_is_linked
- profile_is_unlinked
- credential_exists
- credential_does_not_exist
- credential_is_wrong
- credential_is_expired
- credential_is_reused
- profile_is_disputed
- profile_is_restricted
- profile_is_archived
- rate_limit_or_risk_rule_caused_review

Prohibited client-visible states remain `wrong_claim_code`,
`profile_exists`, `profile_already_linked`, `claim_code_expired`, and
`national_id_not_found`.

Generic pending is `manual_review_required`. Hidden denial is `NOT_FOUND`.

## Legacy unlinked profiles

Existing unlinked patient profiles with **no** issued credential remain
**MANUAL_REVIEW_ONLY**. The client still sees generic
`manual_review_required`. Automatic or retroactive credential generation
is **not** authorized.

## Already-bound profiles

Already-bound profiles cannot be reclaimed, overwritten, automatically
reassigned, or transferred. Existing `user_id` remains unchanged. Do not
disclose that the profile is already bound.

## PC-001 through PC-022

The JSON artifact lists each decision exactly once, with
`policy_decision` text distinct from `implementation_status`.

| ID | Frozen decision | Current implementation status | Blocks production enablement |
| --- | --- | --- | --- |
| PC-001 | Patient + Active only may start a ceremony | `PARTIALLY_ENFORCED` (onboarding gates; no ceremony) | yes |
| PC-002 | Four-factor bundle including non-empty matching account-bound National-ID | `NOT_IMPLEMENTED` | yes |
| PC-003 | `ial2_verified_link` / `ial2_proof_pending` / `ial3_operator` | `NOT_IMPLEMENTED` | yes |
| PC-004 | New `profile_claim` OTP; AAL1 session insufficient | `NOT_IMPLEMENTED` | yes |
| PC-005 | Active account only; no sensitive eligibility disclosure | `PARTIALLY_ENFORCED` | yes |
| PC-006 | Profile Active and `user_id IS NULL` for self-service | `PARTIALLY_ENFORCED` (lookup filter; no attach) | yes |
| PC-007 | No self-reclaim, overwrite, reassignment, or transfer of a bound profile | `PARTIALLY_ENFORCED` | yes |
| PC-008 | One user ↔ one profile (`patient_profiles_user_id_unique`) | `ALREADY_ENFORCED` | no |
| PC-009 | One authoritative profile ↔ one user (HMAC unique where `status <> merged`) | `ALREADY_ENFORCED` | no |
| PC-010 | Credential attempts: 5/hour then 15 min cooldown; 15/24h → internal MR lock | `NOT_IMPLEMENTED` | yes |
| PC-011 | Ceremony-start and OTP numeric limits | `PARTIALLY_ENFORCED` (OTP config exists; claim start keys do not) | yes |
| PC-012 | Non-enumerating client contract | `PARTIALLY_ENFORCED` | yes |
| PC-013 | OTP is part of attach; no OTP-only completed claim | `NOT_IMPLEMENTED` | yes |
| PC-014 | Hybrid routing | `NOT_IMPLEMENTED` | yes |
| PC-015 | Dispute freeze uses existing `disputed` status; no automatic reassignment | `NOT_IMPLEMENTED` | yes |
| PC-016 | Notify bind, lockout, dispute freeze; no secrets in content | `NOT_IMPLEMENTED` | yes |
| PC-017 | PC-017 is a production-enablement blocker: metrics, alerts, dashboard, monitored cohorts before PC-020 | `PARTIALLY_ENFORCED` (unused counter only) | yes |
| PC-018 | Dedicated incident/runbook before enablement; stale registry sentence must be corrected later | `EVIDENCE_REQUIRED` | yes |
| PC-019 | PC-019 is a production-enablement blocker: verified kill switch that stops new claims without unlinking valid links | `PARTIALLY_ENFORCED` | yes |
| PC-020 | Production hard-off remains; env flag alone cannot enable production; this artifact is not enablement | `ALREADY_ENFORCED` | yes |
| PC-021 | OTP recency 10 minutes; no patient TOTP requirement in v1 | `NOT_IMPLEMENTED` | yes |
| PC-022 | PC-022 is a production-enablement blocker until post-hard-off env/config kill-switch behavior is demonstrated | `PARTIALLY_ENFORCED` | yes |

PC-015 mapping is **not** ambiguous: `patient_profiles.status` already
allows `disputed` (`PatientStatus::Disputed`). The freeze **workflow** is
still unimplemented. No new status is invented.

PC-020 is **not** performed in this PR. Prerequisites before any later
production hard-off removal:

- policy recorded
- ceremony implementation complete
- tests complete
- observability complete
- monitored rollout/cohort controls ready
- verified kill switch
- external governance approvals
- independent engineering QA
- applicable independent-human gates

After a separately authorized PC-020 change, setting
`FEATURE_IDENTITY_PROFILE_CLAIM=false` must prevent new claims without a
code deployment; a config or process reload may still be required. That
post-hard-off env/config behavior is not demonstrated yet, so PC-022
blocks production enablement.

## Numeric Policy v1

| Control | Policy v1 |
| --- | --- |
| OTP length | 6 digits |
| OTP TTL | 300 seconds |
| OTP max verification attempts | 5 |
| OTP resend cooldown | 60 seconds |
| OTP requests / phone-HMAC / hour | 5 |
| OTP requests / IP / hour | 20 |
| Global OTP requests / hour | 200 |
| Claim credential length | 16 Crockford Base32 characters |
| Claim credential entropy | approximately 80 bits |
| Claim credential TTL | 30 days |
| Claim credential successful uses | 1 |
| Credential failures / account+NID-HMAC / hour | 5 |
| Cooldown after hourly credential budget | 15 minutes |
| Credential failures / account+NID-HMAC / 24h | 15 → internal Manual Review lock |
| Claim ceremony starts / account / hour | 5 |
| Claim ceremony starts / IP / hour | 20 |
| Claim ceremony starts / NID-HMAC / hour | 5 |
| `profile_claim` OTP recency at attach | 10 minutes |
| Feature default | `false` |
| Production state | hard-off pending separate PC-020 gate |

## Current runtime (must stay dark)

`PlatformFeatures::IDENTITY_PROFILE_CLAIM` defaults to false. When
`APP_ENV=production`, the resolver returns false even if the env flag is
true. `LinkVerifiedPatientAccount` throws `FeatureUnavailable` while the
flag is off. Isolated flag-on tests still do not attach `user_id`.

This PR does not change that behavior. The env flag alone cannot enable
production.

## T46 remains OPEN

The P02-T46 threat-register entry still records status **OPEN**, owner
P02-AUDIT-005. Policy recording closes **policy-selection uncertainty**.
It does not close the threat. Status counts in the threat-model file are
unchanged.

## Next engineering stage (not this PR)

After independent re-QA of this Policy v1 artifact, a **later** task may
implement the ceremony **behind the existing disabled flag**: credential
issuance at unlinked create, hashed storage, OTP purpose wiring,
`attachAccount` CAS, abuse counters, notifications, and client collection
of the claim code.

That later task still must **not** set `FEATURE_IDENTITY_PROFILE_CLAIM=true`
and must **not** remove the production hard-off (PC-020 is a separately
authorized change).

## Recommended QA action

Independent re-QA of this evidence-only PR: confirm the JSON/SHA/tests
match the frozen Hybrid decisions, confirm no runtime source changed, and
confirm T46 / P02-AUDIT-005 remain OPEN.

## Final blocker state after this evidence PR

Current material state: P02-AUDIT-005 is OPEN.

Current material state: T46 is OPEN.

`P02-AUDIT-005: OPEN`

`T46: OPEN`

`P02-AUDIT-006: OPEN / UNCHANGED`

`P02-AUDIT-007: OPEN / EXTERNAL_HUMAN`

`G-08-04: OPEN / EXTERNAL_HUMAN`

`Production enablement: NOT_AUTHORIZED`

`Feature state: DISABLED`

`FEATURE_IDENTITY_PROFILE_CLAIM=false`

`Production hard-off: PRESENT / UNCHANGED`

`Ceremony implementation: NOT IMPLEMENTED`

`Phase 02: NOT PASS`
