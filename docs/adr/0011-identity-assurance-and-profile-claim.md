# ADR 0011 — Identity assurance levels, profile claim, and recovery

- **Status:** Accepted for engineering defaults; production enablement of claim and recovery remains gated
- **Date:** 2026-08-26
- **Deciders:** Product, privacy/legal, security, support, engineering (named in `docs/governance/accountable-owners.md`)
- **Phase:** 01
- **Supersedes / Superseded by:** none

## Context

Phase 01 must authenticate actors without implying clinical access, and must
not attach a verified phone to an existing patient profile merely because the
National ID matches. `docs/phases/README.md` requires this decision before the
Phase 01 design gate. Caregiver, guardian, minor, deceased-patient, and
emergency break-glass access stay disabled (Phase 01 non-goals).

## Decision

The platform uses these **identity assurance levels** (stored as `varchar(32)`,
never inferred from a client field):

| Level | Meaning | Used for |
| --- | --- | --- |
| `aal1_password` | Verified password (or equivalent knowledge factor) | Patient device sessions after phone verification |
| `aal2_totp` | Password plus verified TOTP | Privileged doctor, pharmacy-owner, and admin capability activation |
| `aal2_otp_phone` | Purpose-bound SMS OTP on a verified phone | Registration phone proof; not sufficient for privileged activation |
| `ial1_self_asserted` | Caller supplied a well-formed National ID; no registry match required | New pending accounts |
| `ial2_proof_pending` | A registry candidate exists; linking is blocked pending reviewed proof | Existing-profile claim |
| `ial2_verified_link` | Unique active account-to-profile link after approved proof | Phase 02 after this ADR's proof policy is executed |
| `ial3_operator` | Audited operator workflow with separation of duties | Support/manual review only; not self-service |

**Patient registration.** Phone is canonicalized to E.164. National ID is
canonicalized by one function. Existence of either value is never returned to
the client. After a successful OTP the phone is marked verified; `status`
stays `pending_phone` until Phase 02 activates a profile. A restricted device
session may call only `/me`, session management, refresh, logout, and
future Phase 02 onboarding routes.

**Existing-profile claim.** Matching National ID plus a newly verified phone is
**not** sufficient when a candidate exists. The server looks up the candidate
only through `PatientIdentityRegistry`. Outcomes:

1. No candidate: Phase 02 may create a new profile; this phase returns the same
   generic success as every other registration path.
2. Candidate already linked to this account: idempotent success.
3. Candidate linked to another account: `MANUAL_REVIEW_REQUIRED` in the
   application result, with the same client-visible envelope as an ordinary
   pending registration. No second link. No existence flag.
4. Unlinked candidate: same non-disclosing `MANUAL_REVIEW_REQUIRED` until a
   qualified reviewer records proof. Self-service auto-link is disabled.

The claim workflow is behind `identity.profile_claim` and remains **off** until
product, privacy/legal, security, and support record enablement against this
ADR. Phase 02 owns profile rows; this phase only defines the port and the
non-enumerating result.

**Recovery.** Recovery is a separate state machine with purpose `recovery` OTPs,
notifications to old and new channels when both exist, `credential_version`
increment, session revocation, and a cooling-off plus manual-review path when
risk signals fire. Support cannot set a password, reveal National ID existence,
disable MFA, or relink a profile except through an audited operator workflow.
The workflow is behind `auth.recovery` and remains **off** by default.

**Privileged enrollment.** Admin, doctor, and pharmacy-owner identities require
verified TOTP before privileged capabilities activate. Patient MFA is optional
and never mandatory in V1.

**Proxy and break-glass.** No caregiver, guardian, minor, deceased-patient, or
emergency access is implemented or flag-enabled.

## Consequences

### Positive

- Authentication cannot be mistaken for clinical authorization.
- Profile takeover via SIM swap plus National ID knowledge is not an automatic
  link.
- Engineering defaults are conservative and reversible.

### Negative / accepted cost

- Genuine owners of historical unlinked profiles cannot self-serve a link until
  the proof policy is enabled and Phase 02 ships the registry.
- Recovery is implemented but dark, so lost-device support relies on the
  operator bootstrap/review path until enablement.

### Risks and their mitigations

- **Silent enablement of claim/recovery.** Server flags fail closed; tests
  assert the production default is off.
- **Enumeration via timing or result shape.** Registration, OTP, login,
  recovery, and claim share one generic client-visible success/failure shape
  for existence-sensitive steps.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Auto-link when National ID and a new phone match | Roadmap forbids it; SIM-swap takeover |
| Reveal “account exists” on registration | Enumeration |
| Enable proxy/break-glass as a hidden admin switch | Explicit Phase 01 non-goal; needs a later legal model |

## Migration and rollback impact

No production users exist. Flags default off. Rollback disables the flags;
encrypted identity columns are retained.

## Verification

- Pest tests: generic registration/claim envelopes; concurrent unique link;
  privileged login without TOTP issues no session; pending users denied on
  business routes.
- Feature flags `identity.profile_claim` and `auth.recovery` resolve false
  unless explicitly enabled in a non-production environment.

## Review requirement

Product, privacy/legal, security, and support must record enablement before
either flag is turned on outside local/testing. Engineering cannot treat this
ADR as statutory or clinical approval. Independent re-review remains Phase 22.
