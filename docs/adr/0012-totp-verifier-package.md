# ADR 0012 — RFC 6238 TOTP verifier package

- **Status:** Accepted
- **Date:** 2026-08-26
- **Deciders:** Security, backend engineering
- **Phase:** 01
- **Supersedes / Superseded by:** none

## Context

Phase 01 requires a security-reviewed RFC 6238 TOTP implementation behind a
`TotpVerifier` port. The package and version must be pinned (ADR 0008). Domain
code must not call a vendor SDK.

## Decision

**Package:** `spomky-labs/otphp`, locked in `apps/core-api/composer.lock`.

This library implements RFC 6238 TOTP as a focused PHP package, is maintained,
and has no Laravel coupling, so the adapter can sit entirely in Auth
infrastructure.

**Verifier policy (encoded in `TotpVerifier`, not in controllers):**

- SHA-1 HMAC, 6 digits, 30-second period (RFC 6238 defaults / authenticator
  interoperability).
- Bounded skew: ±1 period (30 seconds either side).
- Replay protection: last successful time-step counter is stored per factor
  and a code for that step cannot be reused.
- At most one active TOTP factor per user in V1.
- Secrets are generated as 160-bit random values, stored only through the
  versioned envelope-encryption adapter, and shown to the user once during
  enrollment.

A substitute adapter must pass the same contract tests.

## Consequences

### Positive

- Domain tests can freeze the clock and inject a fake verifier.
- Replacing the library is one adapter change.

### Negative / accepted cost

- SHA-1 remains in TOTP because authenticator apps still default to it.
  This is an RFC interoperability constraint, not a password-hashing choice.

### Risks and their mitigations

- **Library abandonment.** Contract tests pin behaviour; a replacement must
  pass them before the lockfile changes.
- **Skew abuse.** Only ±1 step is accepted; replay of the accepted step fails.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| `pragmarx/google2fa` | Heavier, historically Laravel-oriented; more than the port needs |
| Hand-rolled HMAC | Easy to get skew, padding, or replay wrong |
| WebAuthn as V1 MFA | Out of Phase 01 scope |

## Migration and rollback impact

No production factors exist. Rollback removes the package only after no rows
remain in `mfa_factors`.

## Verification

- `TotpVerifier` contract tests: valid code, skew edge, replay of the same
  time-step, wrong secret, frozen clock.
- Composer lockfile pin; `composer audit` on the Core job.

## Review requirement

Security review of the adapter and pin. Not a statutory compliance claim.
