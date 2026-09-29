# Disputed profile link

ENGINEERING IMPLEMENTATION ONLY — PRODUCTION ENABLEMENT NOT AUTHORIZED.

Profile Claim stays **DISABLED**. Canonical enablement is
`PlatformFeatures::IDENTITY_PROFILE_CLAIM`. Production (`APP_ENV=production`)
remains hard-off even if `FEATURE_IDENTITY_PROFILE_CLAIM=true` is configured.
This runbook does **not** record Product, Security, Privacy, or
Support/Operations approval. Those decisions remain **PENDING_EXTERNAL**.
Independent QA, G-08-04, and P02-AUDIT-007 remain **OPEN / EXTERNAL_HUMAN**.
P02-AUDIT-005 and P02-T46 remain **OPEN**. Phase 02 is **NOT PASS**.

## If the flag is off (checked-in default and production)

- `POST /api/v1/auth/otp-requests` with purpose `profile_claim` returns the
  same hidden 404 as other disabled features.
- Authenticated onboarding against an existing unlinked profile returns generic
  `manual_review_required` and does **not** attach `user_id`.
- `LinkVerifiedPatientAccount` throws `FeatureUnavailable`.
- Do nothing that confirms a candidate, credential, or National ID exists.

## Ceremony when the flag is explicitly enabled in non-production tests

The hybrid claim (PC-002) requires all four factors before `attachAccount`:

1. Canonical National-ID HMAC resolves exactly one Active unlinked profile.
2. The claimant account has a non-empty bound National ID that matches.
3. A fresh consumed `profile_claim` OTP exists on the already-verified phone
   (consumed within 10 minutes).
4. A valid clinic-issued Option B credential is bound to that exact profile
   (Crockford Base32, 16 characters, peppered hash only, single-use, 30-day TTL).

Anything missing, expired, reused, ambiguous, rate-limited, locked, or
ineligible returns generic `manual_review_required`. Clients never see
`wrong_claim_code`, `profile_exists`, `profile_already_linked`,
`claim_code_expired`, or `national_id_not_found`.

`PostgresPatientIdentityRegistry` is the live Patients adapter for
`PatientIdentityRegistry`. It is **not** unavailable. Identity still must not
query `patient_profiles` or `patient_claim_*` tables directly.

## Wrong bind or ownership dispute (PC-015)

1. An authorized operator with `patients.profile.dispute_freeze` (privileged
   admin, default-deny for patients) freezes the profile to status `disputed`.
2. Existing `user_id` is left unchanged. There is **no** automatic
   reassignment or transfer.
3. Notify the linked user, if any, without National ID, phone, or credential
   material.
4. Operator re-bind (`ial3_operator`) is **not** implemented by Policy v1.

Product, Security, Privacy, and Support/Operations still own the production
review playbook. This engineering runbook records current code behavior only.

## Duplicate-match conflict alert

`ProfileClaimDuplicateMatchConflict` fires on
`clinic_profile_claim_conflicts_total{reason_code="duplicate_match"}`.
Labels must not include applicant, document, profile, or user identifiers.
Investigate HMAC uniqueness (`patient_profiles_authoritative_hmac_unique`)
without confirming existence to any client.

## Kill switch (PC-019 / PC-022)

Disabling the flag (non-production `FEATURE_IDENTITY_PROFILE_CLAIM=false`, or
the production hard-off) stops **new** claims. Valid existing links are not
unlinked. After a future separately authorized PC-020 change, env/config false
must disable new claims without a code deploy; that change is **not** this
task and the production hard-off must not be removed here.
