# Production KMS and database TLS path

**Status:** required production path. **Not implemented** as a live KMS binding
in this phase. This is not a claim that production keys exist.

Related: [ADR 0013](../adr/0013-identity-key-management.md). Phase 23 owns
restore, failover, and the production secret manager.

## Database TLS

| Environment | `DB_SSLMODE` | Notes |
| --- | --- | --- |
| local Compose | `prefer` | Loopback Postgres has no server certificate in the default image |
| CI | `prefer` | Ephemeral service container |
| staging / production | `require`, `verify-ca`, or `verify-full` | `ConfigurationCheck` fails readiness if production is still `prefer`/`disable`/`allow` |

`verify-full` is the production default when `APP_ENV=production` and
`DB_SSLMODE` is unset. Operators should set `verify-full` plus
`PGSSLROOTCERT` (or the equivalent Laravel/Postgres SSL cert config) to the
environment CA.

Application code does not downgrade TLS because a handshake failed.

## KMS / secret manager

Until Phase 23:

- Identity HMAC and envelope keys come from the process environment / injected
  secrets, never from the git tree.
- `identity:rotate-keys` **refuses ciphertext rewrite in production**.
- Registration, profile claim, and recovery stay flag-gated and
  production-locked (ADR 0011 / 0013).

Production end state (Phase 23):

1. Keys live in the approved KMS/secret manager, not in `APP_KEY` and not beside
   ciphertext columns.
2. The existing `FieldEncryptor` / `HmacHasher` ports bind to that manager
   without domain code calling the vendor SDK.
3. Dual-read / new-write rotation stays; backfill remains resumable and
   plaintext-free in logs.
4. Volume and backup encryption, plus a restore rehearsal, are separate Phase 23
   gates. This document does not mark them done.

## mTLS and backups

Workload-to-Postgres mTLS and encrypted backup restore are Phase 23. The
`clinic_backup` role is SELECT-only so a backup credential cannot DML identity
rows; that is not a substitute for encrypted media.
