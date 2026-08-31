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
| Account closed/disabled | Revoke sessions and devices; identity disable coordinator | Phase 01 disable path. Disable is not erasure. |
| Subject erasure request | Technical Phase-01 coordinator tombstones identity and deletes subject-linked auth/access/transient copies. Audit append-only. Backups and offline clients are not wiped. Legal approval remains EXTERNAL_HUMAN. | `EraseSubjectService` (capability `identity.erase`). Export enumeration: `ExportSubjectDataService` (no HTTP endpoint; original ISR-013 evidence asked for a deletion/export test). |

## Ciphertext purge rules

1. Never log the value being purged.
2. Never `UPDATE` `audit_events` (append-only). Erasure of audit is a legal
   question (**OPEN_LEGAL_DECISION**) and is **not** implemented.
   `EraseSubjectService` uses `PRESERVE_SECURITY_AUDIT` and appends
   `identity.subject_erased`.
3. `idempotency_keys` never store tokens; they expire by `expires_at` and are
   pruned by scheduled `platform:prune` (daily, withoutOverlapping, onOneServer).
4. Redis rate-limit keys expire with the limiter TTL. Subject-scoped keys are
   also cleared during subject erasure. They are not a second identity store.
5. Telescope tables are local-only and must not exist in staging/production
   schemas.
6. Framework tables `jobs`, `job_batches`, `cache`, and `cache_locks` are **not**
   pruned by `auth:prune-expired` or `platform:prune`. Successful database-queue
   `jobs` rows are deleted by the worker. `failed_jobs` is pruned by scheduled
   `queue:prune-failed` using `platform.queue.failed_job_retention_hours`
   (ENGINEERING_DEFAULT 168 hours, not a statutory period). Laravel `sessions`
   rows for an erased `user_id` are deleted by subject erasure. Offline client
   vault files are **OPERATIONAL_FOLLOW_THROUGH**.

`auth:prune-expired` (hourly, withoutOverlapping, onOneServer) also deletes
terminal `recovery_requests`, revoked `user_devices`, and old
`auth_refresh_consumptions` past ENGINEERING_DEFAULT TTLs in
`identity.retention.*`. `access:prune-expired` deletes obsolete contextual
grants. Active/unexpired rows stay.

## Lawful basis

Personal/sensitive identity processing in the inventory is
`owner_approved_2026-08-27` (Mahmoud). That records owner acceptance of the
documented purpose. It is not a statutory article number.
