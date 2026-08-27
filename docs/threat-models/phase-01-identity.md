# Threat model — Phase 01 identity and access

Additive to [phase-00-foundation.md](phase-00-foundation.md). This is a
register, not a slogan: assets, preconditions, controls, verification, residual
owner, and expiry.

**Status: engineering draft, not independently reviewed.** Assessor and
remediator remain concentrated. Independent re-review remains Phase 22. **Not a
legal, privacy-officer, or statutory position.** Lawful-basis and retention
statutes stay OPEN.

## Assets

| Asset | Classification | Store | Trust boundary |
| --- | --- | --- | --- |
| Phone (E.164) | personal | `users.phone_e164_encrypted` + HMAC | Core ↔ PostgreSQL |
| National ID | sensitive | envelope + purpose HMAC | Core ↔ PostgreSQL |
| Password | credential | Argon2id | Core ↔ PostgreSQL |
| OTP code | credential | hash + envelope until purge | Core ↔ worker (handle only on the event) |
| TOTP secret | credential | envelope | Core ↔ PostgreSQL |
| MFA recovery codes | credential | HMAC hashes; plaintext shown once | Client display only |
| Device refresh/access | credential | hashes; client vault/envelope | Electron main / Flutter secure storage |
| Admin cookie session | credential | HTTP-only cookie; hash bound to Laravel session id | Browser ↔ gateway |
| Audit chain | internal | `audit_events` via `clinic_append_audit_event` | App ↔ PostgreSQL |
| Rate-limit counters | internal | Redis DB 3 | App ↔ Redis |
| Session disconnect hint | internal | Redis pub/sub + Reverb private channel | Worker ↔ Reverb |

## Preconditions (attacker)

Stolen refresh token; CSRF against an admin cookie; reporter credentials;
compromised Electron renderer; stuffed passwords; reuse of an OTP; AAL1 admin
calling grant/disable; two concurrent refresh/OTP/grant/audit writes.

## Controls and verification

| ID | Threat | Control | Verification | Residual | Owner | Expiry |
| --- | --- | --- | --- | --- | --- | --- |
| P01-T1 | Refresh reuse / logout gap | Session-linked refresh, consumption ledger, N-2 family revoke, absolute cap | Pest refresh/logout cases; two-connection unique insert | Load-test residual | engineering | until independent retest |
| P01-T2 | CSRF / cookie fixation | Bind cookie hash to session id after regenerate; CSRF when session cookie present; `CSRF_MISMATCH` | Pest Origin/cookie cases; Playwright admin CSRF | Packaged Electron Origin matrix | engineering | until independent retest |
| P01-T3 | Password/OTP in idempotency hash | Canonical hasher strips secrets; refresh replay from device envelope | Unit + feature replay tests | Unkeyed fingerprint of non-secrets | engineering | n/a |
| P01-T4 | Log/Telescope/Sentry leak | URI filter, hidden parameters/headers/responses, log taps, Sentry `before_send` | Sink canary tests | Collector-export path still G-07-05 | engineering | until export canary |
| P01-T5 | Auth abuse | Named Redis ratelimit store; layered hits including refresh/MFA | Redis feature test; k6 harness | Adaptive limits absent | engineering | until capacity review |
| P01-T6 | Recovery / MFA lifecycle | Cooling-off / manual_review; operator apply; honest `status`; HTTP TOTP enroll/confirm/codes; bootstrap URI not printed | Pest recovery/MFA; artisan apply | Legal notice copy OPEN | engineering | n/a |
| P01-T7 | Audit tampering | Advisory lock, sequence, actor in hash, DEFINER insert, deny UPDATE/DELETE trigger, verifier command | Privilege tests; verifier; concurrent append | Not a qualified signature | engineering | n/a |
| P01-T8 | BFLA via grants | Initiator `ActorContext`; admin AAL2; grantable allow-list; resource match required | Pest matrix (stale AAL, non-grantable, wrong resource) | Product UI later | engineering | n/a |
| P01-T9 | Revoked session stays on Reverb | Redis publish + private `session.revoked` event; HTTP deny authoritative | Measured HTTP deny latency script | Socket-close SLO may stay PARTIAL | engineering | G-01-16 |
| P01-T10 | Desktop/mobile token theft | Packaged custom origin; atomic credential file; Flutter envelope; logout fail-closed on disk delete | Vitest; packaged ASAR inspect; Flutter tests | Full OS matrix host-limited | engineering | G-02-10 |
| P01-T11 | Weak keys / cleartext DB | 32-byte floor; production SSL mode fail-closed; KMS path documented | ConfigurationCheck | Production KMS unimplemented (Phase 23) | engineering | Phase 23 |
| P01-T12 | Reporter reads identity | Views only after hardening migration | `PostgresPrivilegeTest` + live `clinic` migrate | Unmigrated volumes | engineering | n/a |

## Boundary notes (Phase 01)

- **Admin cookie:** hash is `hmac(cookie:<laravel session id>)` after
  `session()->regenerate()`.
- **Device clients:** CSRF is not inferred from a client-supplied
  `client_class`. A bare Origin without a session cookie is treated as a
  non-browser device call.
- **Privileged TOTP:** admin/doctor/pharmacy/secretary recovery stays
  `manual_review` until an AAL2 operator applies it. Patients follow cooling-off
  unless the configured delay is 0.
- **National ID check-digit:** not implemented ([ADR 0014](../adr/0014-national-id-check-digit-deferred.md)).
  Legal decision OPEN.

Privacy: phones and National IDs are personal/sensitive. Retention periods in
the inventory are **engineering defaults**, not a legal schedule.
