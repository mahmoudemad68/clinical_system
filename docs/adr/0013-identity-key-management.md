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
call those ports. Keys never sit beside ciphertext in application tables. A
future approved KMS/secret-manager provider binds behind the same ports. This
ADR does not add a vendor KMS client.

**Envelope.** Ciphertext is a binary envelope stored in `bytea`:

`version uint16 big-endian | 12-byte IV | ciphertext | 16-byte GCM tag`

using AES-256-GCM. Associated data is the column purpose (`phone`,
`national_id`, `mfa_secret`, `otp_code`, `push_token`, `refresh_replay`).
Decrypt uses the version prefix and the matching configured key (dual-read of
any configured version). Encrypt always uses the current version (new-write).
The persisted `key_version` / `phone_key_version` is the envelope version
actually used, not a hardcoded `1`.

**HMAC.** Purpose-separated keys are derived with HKDF-SHA-256 from a versioned
master HMAC secret plus a purpose label (`phone_lookup`, `national_id_lookup`,
`otp_code`, `otp_subject`, `device_fingerprint`, `session_token`). Lookup
values are 32-byte binary HMAC outputs. Raw phone, National ID, OTP, token, or
MFA secret is never the HMAC key.

**Lookup invariant.**

- READ: calculate lookup digests for every configured readable HMAC version
  (`lookupDigests` / `phoneLookupHmacs` / `nationalIdLookupHmacs`).
- WRITE: use only the configured current HMAC version.
- A v1 row remains discoverable while `current_version=2` and both keys exist.
- Reads never rewrite v1 digests merely to perform the lookup.
- National-ID uniqueness checks every readable HMAC so a v1 digest cannot be
  bypassed by inserting a v2 digest of the same canonical ID.
- When issuing an OTP for an existing user, persist that user's stored
  `phone_lookup_hmac` on the OTP row so verification still resolves after HMAC
  rotation. New identities store the current HMAC.

**HMAC version columns.** `users.phone_hmac_version` and
`identity_national_ids.hmac_key_version` are durable markers so batched
rotation can resume without an in-memory cursor. Existing rows default to
version 1. Closed (ISR-013 tombstone) users are skipped: their bytes are not
real envelopes.

**Local and test.** Keys come from the process environment / secret injection
already required by Phase 00. They are not committed. Missing or sub-32-character
keys fail closed at readiness for the **configured current** encryption and HMAC
versions. Any non-empty older key still present in config must also meet the
floor. After an old version is retired operationally, an empty previous-version
slot is valid readiness as long as the current version is present and strong.

**Production.** The same ports will bind to the approved KMS/secret manager
during Phase 23. This ADR does **not** authorize application-level keys as the
production end state. Until that binding exists, identity features stay off in
production (`APP_ENV=production` keeps registration, claim, and recovery flags
false regardless of env overrides). Database TLS and the KMS runbook live in
[production-kms-tls.md](../operations/production-kms-tls.md): production
readiness fails unless `DB_SSLMODE` is `require`, `verify-ca`, or `verify-full`.
Production CORS must be an exact HTTPS origin set (no localhost, loopback,
wildcard, or patterns).

**Rotation.** `identity:rotate-keys` is the provider-neutral backfill:

- Default invocation is inspect / dry-run (counts only).
- `--apply` rewrites a batch. Production `--apply` also requires `--confirm`.
- Batched (`--batch`), resumable via row version columns, idempotent on
  already-current rows, mixed v1/v2 readable, new writes stay on current.
- Fail closed: missing/wrong old key while old ciphertext remains aborts; no
  silent skip and no plaintext in output.
- OTP and refresh-replay ciphertext are **not** rewritten. They are
  short-lived; `--status` reports remaining old-version rows; retirement stays
  blocked until they expire or prune.
- TOTP secrets and non-null push tokens **are** re-encrypted (long-lived).
- Phone HMAC rewrite rebinds `otp_requests.subject_lookup_hmac` in the same
  transaction.
- Environment keys are never deleted by the command. `--status` answers
  whether an old version may be retired. The operator/KMS ceremony removes
  material later (OPERATIONAL_FOLLOW_THROUGH).

**Decrypt audit.** Sensitive plaintext use goes through
`AuditedSensitiveDecryptor` (not unrestricted `NationalIdProtector` decrypt
helpers). Classification (explicit):

| Class | Meaning |
| --- | --- |
| `internal_processing` | Application decrypts to deliver OTP, verify TOTP, send a recovery notice, or re-encrypt during rotation. Still audited. |
| `human_disclosure` | Reserved. Support screens never receive plaintext National ID or full phone. |

OTP delivery decrypt is internal processing **and is audited**. Declaring OTP
"internal" does not skip the event. Refresh-replay decrypt stays on
`FieldEncryptor` as a short-lived credential envelope, not an identity
plaintext disclosure.

Audit metadata is secret-free: purpose, decrypt class, reason code, object id,
actor, key version, correlation id. Never plaintext, ciphertext, keys, OTP,
TOTP seed, phone, National ID, or tokens.

**Access.** Support screens never receive plaintext National ID or full phone.

## Consequences

### Positive

- One encryption/HMAC implementation; modules cannot invent a second.
- Rotation does not require a maintenance window that decrypts the table into
  SQL.
- Lookup and uniqueness survive HMAC rotation without rewriting on read.

### Negative / accepted cost

- Production KMS is not wired in this phase. Registration is flag-gated and
  production-locked.
- Short-lived OTP/refresh-replay ciphertext delays old-key retirement until
  expiry/prune.

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
| Re-encrypt live OTPs during rotation | Short TTL; safer to wait for expiry/prune than to touch every challenge |
| Vendor KMS SDK in application code | Provider binding is Phase 23; ports stay provider-neutral |

## Migration and rollback impact

Empty tables plus HMAC version columns defaulting to 1. Rollback retains
ciphertext columns; it never drops them. Dual-read plus both key versions
keeps existing rows reachable if current_version is rolled back before the
old key is retired. A forward recovery disables new registration while
login/revoke stay available.

## Verification

- Encrypt/decrypt round-trip; dual-read of v1 ciphertext after v2 current;
  wrong-key fail-closed; HMAC purpose isolation (phone HMAC ≠ National ID HMAC
  for the same canonical string); weak/missing/unknown-envelope negatives.
- Readiness fails when the **configured current** identity keys are missing or
  below 32 characters, including `current_version=2` with only V1 present.
- Dual-read registration, OTP issue/verify, recovery, National-ID uniqueness,
  and bootstrap lookup.
- Resumable/idempotent rotation, fail-closed missing old key, retirement gate,
  production `--apply --confirm`.
- `auth.sensitive_decrypt` for OTP, TOTP, phone/NID rotation; metadata
  secret-free.
- Production CORS / HTTPS / Secure cookie / PostgreSQL sslmode / Reverb origin
  negatives (repository configuration, not live TLS).
- Redaction canaries cover phone, National ID, OTP, token, and MFA seed.

## Review requirement

Security and operations own key-ceremony and production KMS binding. Privacy
owns retention of ciphertext after account close. Engineering cannot declare
the Phase 23 restore gate closed from this ADR. Encrypted volume, encrypted
backup, and restore drill remain Phase 23 / OPERATIONAL_FOLLOW_THROUGH. This
ADR does not change ISR-013 erasure semantics.
