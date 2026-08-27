# ADR 0014 — Egyptian National ID check-digit is not invented

- **Status:** Accepted as an engineering constraint; privacy owner (Mahmoud) confirmed 2026-08-27
- **Date:** 2026-08-26 (owner confirmation 2026-08-27)
- **Deciders:** Engineering (implementation). Privacy owner: Mahmoud.
- **Phase:** 01
- **Supersedes / Superseded by:** none. Complements ADR 0011 (assurance) and ADR 0013 (key handling).

## Context

Phase 01 requires a National ID value object that canonicalizes Unicode digits and rejects impossible dates/governorates. Published descriptions of the 14th-digit check disagree. Inventing a modulus would lock real people out or accept forgeries with false confidence.

Independent review ISR-017 required this disagreement to be recorded rather than silently coded as a check-digit.

## Decision

**Do not implement a National ID check-digit.** Digit 14 must still be a digit after canonicalization. Century, date, and governorate checks remain. Synthetic century-9 fixtures stay behind `IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS`.

On 2026-08-27 the privacy owner (Mahmoud) accepted this constraint: continue without a modulus until a cited Egyptian specification is supplied. Engineering will not guess a check-digit.

Closed JSON on auth routes rejects unexpected properties at runtime. That is a contract control, not a substitute for identity policy.

## Consequences

- False rejects of valid IDs from a homemade checksum are avoided.
- Forgery resistance of the 14th digit is **not** claimed.
- G-01-21 / G-08-04 remain independent-retest gates. Owner acceptance of this ADR does not close them.

## Verification

- `NationalId` source contains no modulus/check-digit arithmetic.
- Feature tests use synthetic IDs only.
- A check-digit lands only after a cited specification from the privacy owner.
