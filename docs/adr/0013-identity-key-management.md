# ADR 0013 — Identity envelope encryption and HMAC key management

- **Status:** Accepted for local/staging key handling; production KMS binding remains a Phase 23 restore gate
- **Date:** 2026-08-26
- **Deciders:** Security, privacy/legal, operations, engineering
- **Phase:** 01 (design gate); Phase 23 (restore/KMS production gate)
- **Supersedes / Superseded by:** none

## Context

`docs/phases/README.md` requires envelope encryption/KMS, per-environment keys,
HMAC key versions, audited decrypt, rotation/backfill, and tested recovery.
National IDs and phones are encrypted for recovery and stored as keyed HMACs
for lookup. Domain code must not call Laravel `Crypt` helpers.

## Decision

**Ports.** Platform owns `FieldEncryptor` and `HmacHasher`. Identity and Auth
call those ports. Keys never sit beside ciphertext in application tables.

**Envelope.** Ciphertext is `v{n}.` plus base64url(IV || ciphertext || GCM tag)
using AES-256-GCM. Associated data is the column purpose (`phone`,
`national_id`, `mfa_secret`, `push_token`). Decrypt tries the current key then
the previous key (dual-read). Encrypt always uses the current version
(new-write).

**HMAC.** Purpose-separated keys are derived with HKDF-SHA-256 from a versioned
master HMAC secret plus a purpose label (`phone_lookup`, `national_id_lookup`,
`otp_code`, `otp_subject`, `device_fingerprint`, `session_token`). Lookup
values are 32-byte binary HMAC outputs. Raw phone, National ID, OTP, token, or
MFA secret is never the HMAC key.

**Local and test.** Keys come from the process environment / secret injection
already required by Phase 00. They are not committed. Missing keys fail closed
at readiness for any process that handles identity writes.

**Production.** The same ports will bind to the approved KMS/secret manager
during Phase 23. This ADR does **not** authorize application-level keys as the
production end state. Until that binding exists, identity features stay off in
production (`APP_ENV=production` keeps registration, claim, and recovery flags
false regardless of env overrides).

**Rotation.** Dual-read/new-write, resumable backfill job with cursors, metrics
without plaintext, then retirement of the old version. Decrypt into logs or
migration output is forbidden. Canary tests prove redaction.

**Access.** Decrypt is an audited operation. Support screens never receive
plaintext National ID or full phone.

## Consequences

### Positive

- One encryption/HMAC implementation; modules cannot invent a second.
- Rotation does not require a maintenance window that decrypts the table into
  SQL.

### Negative / accepted cost

- Production KMS is not wired in this phase. Registration is flag-gated and
  production-locked.

### Risks and their mitigations

- **Key loss.** Restore is a Phase 23 gate; ciphertext without keys is
  unrecoverable by design.
- **APP_KEY reuse as identity master.** Forbidden. Identity keys are separate
  configuration values.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Laravel `Crypt` in domain services | Couples domain to the framework; no purpose AAD |
| Application-side keys beside rows | Violates the roadmap key-ownership default |
| SHA-256 of the National ID without HMAC | Rainbow / cross-environment matching |

## Migration and rollback impact

Empty tables. Rollback retains ciphertext columns; it never drops them. A
forward recovery disables new registration while login/revoke stay available.

## Verification

- Encrypt/decrypt round-trip; dual-read of v1 ciphertext after v2 current;
  wrong-key fail-closed; HMAC purpose isolation (phone HMAC ≠ National ID HMAC
  for the same canonical string).
- Readiness fails when identity write keys are missing in environments where
  registration is enabled.
- Redaction canaries cover phone, National ID, OTP, token, and MFA seed.

## Review requirement

Security and operations own key-ceremony and production KMS binding. Privacy
owns retention of ciphertext after account close. Engineering cannot declare
the Phase 23 restore gate closed from this ADR.
