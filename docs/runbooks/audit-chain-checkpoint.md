# Audit chain external checkpoint

Operations for signed checkpoints. Verification failure pages through
[`audit-chain-verification`](audit-chain-verification.md)
(`AuditChainVerificationFailed` / `AuditChainVerificationStale`).

See [ADR 0015](../adr/0015-audit-chain-external-checkpoint.md).

## Trust boundary

- PostgreSQL owns per-row SHA-256 chaining.
- The Ed25519 **private** key is not in PostgreSQL and is not required by
  migrators or database owners.
- Verifiers use the configured **public** key.
- Checkpoint objects live on a Laravel disk, never in `audit_events`.
- This does **not** protect an attacker who controls both PostgreSQL and the
  signing key / checkpoint store.
- The repository local disk is **not** an immutable production store.

## What fired

`audit:verify-chain` exited non-zero with `checkpoint=no` and a reason code, or
`audit:checkpoint-chain` failed with `reason=...`.

Reason codes: `checkpoint_missing`, `checkpoint_malformed`,
`checkpoint_signature_invalid`, `checkpoint_wrong_key`, `checkpoint_row_missing`,
`checkpoint_hash_mismatch`, `checkpoint_keys_unavailable`,
`checkpoint_store_unavailable`, `checkpoint_invalid_chain`.

## User impact

Audit history may have been rewritten, the checkpoint store may be down, or
signing keys may be missing. Privileged actions continue; tamper evidence is
degraded until verification passes again.

## Confirm

1. Run `php artisan audit:verify-chain`. Note `checked`, `ok`, `checkpoint`,
   and `reason`. Do not print or copy private keys, signatures, or row payloads
   into tickets.
2. Confirm `AUDIT_CHECKPOINT_ENABLED` and `AUDIT_CHECKPOINT_REQUIRED` on the
   verifier hosts, and that the public key (or public key file) is present.
3. List checkpoint objects on the configured disk/prefix. A required empty store
   with existing `audit_events` rows is a failure, not a skip.
4. If the in-database chain is `ok=yes` but the checkpoint fails with
   `checkpoint_hash_mismatch` or `checkpoint_row_missing`, treat the database as
   untrusted relative to the signed snapshot.

## Do

**Missing or unavailable store.** Restore the checkpoint objects from backup
(object-lock/versioned bucket). Do not invent a new checkpoint against a
database you have not independently recovered. After restore, re-run
`audit:verify-chain`.

**Invalid in-database chain.** Do not run `audit:checkpoint-chain`. The command
refuses to sign a chain that already fails verification.

**Key rotation.** Deploy the new public key under a new `AUDIT_CHECKPOINT_KEY_ID`
on verifiers first (retain the previous public key until old objects age out).
Place the new private key only on the signer host (scheduler), never in
PostgreSQL. Old envelopes keep their `key_id`; current code verifies the
configured `key_id` only — keep old objects and the matching public key until
you explicitly cut over.

**Signer host vs web fleet.** Web processes need the **public** key.
`audit:checkpoint-chain` needs the **private** key. Do not put the private key
on every application server.

**Production storage.** Set `AUDIT_CHECKPOINT_DISK` to a disk the database owner
does not administer. Prefer object lock / WORM / versioned writes. Same-host
`storage/app/private/audit-checkpoints` is not that.

**New checkpoint after confirmed-good recovery.** Only after the restored
database matches the restored checkpoints, operators may create a new
checkpoint of the current tip.

## Do not

- Disable checkpoint verification to “clear” a failure.
- Store the private key in a migration, table, or SQL function.
- Log private keys, secret files, or raw signatures.
- Claim a local test disk is production-immutable.
