# ADR 0014 — Egyptian National ID check-digit is not invented

- **Status:** Accepted as an engineering constraint; legal/statutory check-digit policy remains human-owned
- **Date:** 2026-08-26
- **Deciders:** Engineering (implementation). Privacy/legal owner named, but no independent legal sign-off exists.
- **Phase:** 01
- **Supersedes / Superseded by:** none. Complements ADR 0011 (assurance) and ADR 0013 (key handling).

## Context

Phase 01 requires a National ID value object that canonicalizes Unicode digits and rejects impossible dates/governorates. Published descriptions of the 14th-digit check disagree. Inventing a modulus would lock real people out or accept forgeries with false confidence.

Independent review ISR-017 required this disagreement to be recorded rather than silently coded as a check-digit.

## Decision

**Do not implement a National ID check-digit.** Digit 14 must still be a digit after canonicalization. Century, date, and governorate checks remain. Synthetic century-9 fixtures stay behind `IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS`.

A lawful check-digit, if Egypt requires one for this product, is a **privacy/legal owner** decision with a cited specification. Engineering will not guess it.

Closed JSON on auth routes rejects unexpected properties at runtime. That is a contract control, not a substitute for legal identity policy.

## Consequences

- False rejects of valid IDs from a homemade checksum are avoided.
- Forgery resistance of the 14th digit is **not** claimed.
- G-01-21 / G-08-04 cannot close on the strength of this ADR: legal review is still outstanding.

## Verification

- `NationalId` source contains no modulus/check-digit arithmetic.
- Feature tests use synthetic IDs only.
- Independent legal sign-off is out of scope for this implementer.
