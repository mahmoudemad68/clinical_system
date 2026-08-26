# Deletion and ciphertext purge (engineering procedure)

**Status:** engineering runbook. **Not** a legal erasure decision, statutory
retention schedule, or privacy-officer approval. G-08-04 and lawful-basis
columns stay OPEN.

This file is the procedure the inventory refers to. Jobs must not log
plaintext, OTP codes, National IDs, or TOTP URIs.

## Purpose

Phase 01 stores identity secrets. Ciphertext that has served its purpose must
not remain queryable forever. PostgreSQL `DELETE` of a row is the purge of that
ciphertext; overwriting a `bytea` column with NULL is the purge when the row
must remain for a short integrity window (consumed OTP id referenced by
`recovery_requests`).

## Triggers

| Trigger | Action | Job |
| --- | --- | --- |
| OTP expires unused | Set `invalidated_at`; NULL `code_ciphertext` and `destination_ciphertext` | `auth:prune-expired` hourly |
| OTP consumed or invalidated | NULL ciphertext columns immediately | same job, and consume/invalidate paths |
| OTP row older than engineering TTL | `DELETE` the row if ciphertext is already NULL | `auth:prune-expired` |
| Session past absolute expiry | Set `revoked_at`; after session TTL `DELETE` hashes by deleting the row | `auth:prune-expired` |
| MFA factor disabled | Overwrite `secret_ciphertext` with a non-decryptable tombstone; delete unused recovery hashes | MFA disable handler + prune |
| Account closed/disabled | Revoke sessions and devices; identity disable coordinator | Phase 01 disable path |
| Subject erasure request | **OPEN legal workflow.** Engineering will not invent an Egyptian legal basis. Until legal signs a schedule, operators must not run an ad-hoc production wipe | legal + privacy owner |

## Ciphertext purge rules

1. Never log the value being purged.
2. Never `UPDATE` `audit_events` (append-only). Erasure of audit is a legal
   question and is **not** implemented.
3. `idempotency_keys` never store tokens; they expire by `expires_at` and are
   pruned by the platform prune command.
4. Redis rate-limit keys expire with the limiter TTL. They are not a second
   identity store.
5. Telescope tables are local-only and must not exist in staging/production
   schemas.

## Lawful basis

Every inventory row that processes personal data has
`lawful_basis = OPEN_LEGAL_DECISION`. Filling that column with an invented
article number would be worse than leaving it explicitly open.
