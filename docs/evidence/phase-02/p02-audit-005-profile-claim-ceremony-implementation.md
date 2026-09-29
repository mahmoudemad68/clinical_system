# P02-AUDIT-005 — Profile Claim ceremony engineering implementation

ENGINEERING IMPLEMENTATION ONLY — PRODUCTION ENABLEMENT NOT AUTHORIZED.

This file is engineering evidence for the hybrid Profile Claim ceremony
implemented behind the still-disabled feature flag. It is **not**
Product, Security, Privacy, Support/Operations, independent QA, G-08-04,
or P02-AUDIT-007 approval. It does **not** close P02-AUDIT-005. It does
**not** change P02-T46 from OPEN. It does **not** authorize
`FEATURE_IDENTITY_PROFILE_CLAIM=true`, removal of the production hard-off,
promotion, or Phase 02 PASS.

| Field | Value |
| --- | --- |
| Scope | Engineering implementation and isolated non-production tests |
| Authoritative policy | `docs/evidence/phase-02/reference-data/phase02-profile-claim-policy.v1.0.0-phase02.json` |
| Policy SHA-256 | `7a13fbbd1a36bb789f53d22f30335a926ced3160d720962c3d52405972ca55e5` |
| Decision | `HYBRID_PROFILE_CLAIM` |
| Implementation state | `IMPLEMENTATION_COMPLETE` (engineering, flag remains off) |
| Production authorized | **false** (`PRODUCTION_AUTHORIZED=false`) |
| Feature state | **DISABLED** |
| Canonical enablement | `PlatformFeatures::IDENTITY_PROFILE_CLAIM` |
| Production hard-off | Unchanged (`APP_ENV=production` forces false) |
| **P02-AUDIT-005** | **`OPEN`** / `IMPLEMENTATION_AWAITING_INDEPENDENT_QA_AND_EXTERNAL_GOVERNANCE` |
| **P02-T46** | **`OPEN`** |
| **P02-AUDIT-006** | `CLOSED_POSTMERGE_VERIFIED` (unchanged by this work; frozen Policy v1 still records `OPEN / UNCHANGED`) |
| **G-08-04** | **`OPEN` / `EXTERNAL_HUMAN`** |
| **P02-AUDIT-007** | **`OPEN` / `EXTERNAL_HUMAN`** |
| **G-01-21** | **`OPEN`** |
| Phase 02 | **NOT PASS** |

Controller authorization used for this change (engineering and testing only):

> Authorize implementation of the frozen Profile Claim Policy v1 behind the existing disabled feature flag and production hard-off. This authorization permits engineering implementation and testing only. It does not authorize production enablement, removal of the production hard-off, setting FEATURE_IDENTITY_PROFILE_CLAIM=true, promotion, or closure of P02-AUDIT-005 / P02-T46. Product, Security, Privacy, Support/Operations, independent QA, G-08-04, and P02-AUDIT-007 approvals remain separately required.

The frozen Policy v1 JSON, its SHA-256 companion, and
`docs/evidence/phase-02/p02-audit-005-profile-claim-policy.md` were **not**
edited. Frozen historical statuses inside that artifact, including
P02-AUDIT-006 `OPEN / UNCHANGED` and P02-T49 Status OPEN, remain as frozen.

## Layer split

| Layer | State |
| --- | --- |
| A. Controller policy freeze | Completed by the frozen v1 artifact |
| B. Engineering implementation | Completed by this change, still dark |
| C. Independent engineering QA | **Not** completed |
| D. External governance | **PENDING_EXTERNAL** |

`IMPLEMENTATION_COMPLETE` is not `PRODUCTION_AUTHORIZED`.

## What was implemented

Walk-in issuance (`CreateUnlinkedPatientProfile`) returns one Option B
Crockford Base32 credential (alphabet `0123456789ABCDEFGHJKMNPQRSTVWXYZ`,
16 characters, 30-day TTL, peppered HMAC purpose `profile_claim_credential`).
Plaintext is shown once and is not stored. Existing rows never receive a
retroactive credential.

`LinkVerifiedPatientAccount` is gated by `PlatformFeatures` first. When the
flag is explicitly true in a non-production isolated test, attach requires
the PC-002 four-factor bundle. Anything else returns generic
`manual_review_required`. Production (`APP_ENV=production`) remains hard-off
even if the env flag is true.

## PC-001 through PC-022 (engineering)

| ID | Engineering status | Notes |
| --- | --- | --- |
| PC-001 | IMPLEMENTED | Patient + Active; others `FeatureUnavailable` / hidden 404 |
| PC-002 | IMPLEMENTED | Four-factor bundle; missing bound NID is pending |
| PC-003 | IMPLEMENTED_PARTIAL | `ial2_verified_link` / `ial2_proof_pending` recorded; `ial3_operator` bind is not implemented (policy does not authorize operator workflow) |
| PC-004 | IMPLEMENTED | Fresh `profile_claim` OTP; AAL1 session alone is not enough |
| PC-005 | IMPLEMENTED | Non-Active stays hidden/generic |
| PC-006 | IMPLEMENTED | Unlinked Active only |
| PC-007 | IMPLEMENTED | No steal / overwrite of an already-bound row |
| PC-008 | PRESERVED | `patient_profiles_user_id_unique` unchanged |
| PC-009 | PRESERVED | Authoritative HMAC uniqueness unchanged |
| PC-010 | IMPLEMENTED | 5/hour cooldown; 15/24h lock; generic client envelope |
| PC-011 | IMPLEMENTED | Ceremony-start budgets map to pending, not 429; OTP budgets reused |
| PC-012 | IMPLEMENTED | No existence or credential oracles |
| PC-013 | IMPLEMENTED | OTP verify is `otp_verified`, not attach |
| PC-014 | IMPLEMENTED | Hybrid routing to pending whenever a factor is missing |
| PC-015 | IMPLEMENTED | `FreezeDisputedPatientProfile` → `disputed`; no reassignment |
| PC-016 | IMPLEMENTED | Inbox `successful_profile_bind` / `failed_proof_lockout` / `dispute_freeze` without NID/phone/credential |
| PC-017 | IMPLEMENTED_ENGINEERING | Metrics, duplicate-match alert, dashboard, empty cohort; not a production rollout |
| PC-018 | IMPLEMENTED_ENGINEERING | `docs/runbooks/disputed-profile-link.md` matches live registry behavior; Product/Security/Privacy/Support remain pending |
| PC-019 | IMPLEMENTED | Disable stops new claims; valid `user_id` values stay linked |
| PC-020 | PRESERVED | Production hard-off not removed; PC-020 is not this change |
| PC-021 | IMPLEMENTED | 10-minute OTP recency at attach |
| PC-022 | DEMONSTRATED_WITHOUT_REMOVING_HARD_OFF | Non-production env/config false disables; production still ignores a true flag |

## Tests (names)

- `ProfileClaimFeatureGateTest` — default false; checked-in env false; production true still effective false; no PlatformFeatures bypass; no client production path
- `ProfileClaimCeremonyTest` — disabled endpoint/service fail-closed; four-factor attach; NID+OTP insufficient; replay/expiry/stale OTP; abuse; duplicate_match; kill switch; PC-022 demonstration; freeze
- `ProfileClaimCeremonyRaceTest` — same-account and two-account races
- `ClaimCredentialTest` — Option B alphabet and canonicalization
- Existing `Phase02ProfileClaimPolicyAlignmentTest`, `PatientProfileFlowsTest`, `IdentityAccessPortsTest`, `AuthenticationFlowsTest` profile-claim OTP 404, `ThreatModelDocumentationTest`, SF-001 / ISR-015 suites unchanged in intent

## Remaining external decisions

Product, Security, Privacy, Support/Operations, independent QA, G-08-04,
and P02-AUDIT-007 remain separately required before any future PC-020
production-enablement change. This file does not invent those approvals.

P02-AUDIT-005: OPEN / IMPLEMENTATION_AWAITING_INDEPENDENT_QA_AND_EXTERNAL_GOVERNANCE
P02-T46: OPEN
P02-AUDIT-006: CLOSED_POSTMERGE_VERIFIED
G-08-04: OPEN / EXTERNAL_HUMAN
P02-AUDIT-007: OPEN / EXTERNAL_HUMAN
G-01-21: OPEN
Profile Claim: DISABLED
Phase 02: NOT PASS
