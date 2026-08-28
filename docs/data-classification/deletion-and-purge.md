# Deletion and ciphertext purge (engineering procedure)

**Status:** engineering runbook, accepted by the privacy owner (Mahmoud,
2026-08-27) for the documented identity tables. This is not an Egyptian PDPL
article citation. G-08-04 stays OPEN until independent retest.

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
| Subject erasure request | Owner-approved: follow this runbook; do not invent a wipe outside it. A full data-subject rights workflow is still unimplemented | privacy owner (Mahmoud) |

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
6. Framework tables `jobs`, `job_batches`, `failed_jobs`, `cache`,
   `cache_locks`, and `sessions` are **not** pruned by `auth:prune-expired` or
   `platform:prune`. Laravel may delete completed database-queue `jobs` rows
   and lottery-sweep expired database `sessions`. There is no approved legal
   retention period for those tables.

## Lawful basis

Personal/sensitive identity processing in the inventory is
`owner_approved_2026-08-27` (Mahmoud). That records owner acceptance of the
documented purpose. It is not a statutory article number.
