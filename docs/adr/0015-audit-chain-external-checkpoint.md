# ADR 0015 — External signed audit-chain checkpoints

- **Status:** Accepted for local/staging checkpointing; production object-lock/KMS binding remains an operations recommendation, not proven by the test filesystem
- **Date:** 2026-08-30
- **Deciders:** Security, operations, engineering
- **Phase:** 01 (ISR-008 checkpoint/sign residual)
- **Supersedes / Superseded by:** none

## Context

ISR-008 requires a verifiable tamper-evident audit chain, including a checkpoint
or signature **outside the mutable database**. PostgreSQL now owns per-row
SHA-256 chaining (`clinic_append_audit_event`). That control does not detect a
table owner or migrator who rewrites `audit_events` and recomputes a new
internally consistent hash history. An independent signed snapshot of chain
position and `row_hash` is required so that rewrite is detectable without
trusting PostgreSQL.

## Decision

**PostgreSQL** owns normal per-row chain hashing and append serialization.

**External checkpoints** are versioned JSON objects stored on a configurable
Laravel disk (not in `audit_events` or any PostgreSQL table). Each object
contains a canonical payload and a detached **Ed25519** signature.

**Private signing key** is loaded from the process environment or a secret file.
It is never stored in PostgreSQL, never committed, and never logged. Database
roles `clinic_app`, `clinic_worker`, `clinic_audit_writer`, and `clinic_reporter`
do not receive it. A migrator/owner does not need it to operate the database.

**Verification** uses configured public-key material only. Public keys are never
taken from client input.

**Semantics.** A checkpoint at sequence N proves that `audit_events.chain_sequence = N`
still has the signed `row_hash`. Later legitimate events N+1, N+2, … remain valid.
Verification does not merely compare the checkpoint to the current tip.

**Empty chain.** Creating a checkpoint with zero rows is a successful no-op.
Verification of an empty chain with no checkpoint objects succeeds.

**Failure.** Signing or storage failure does not mutate `audit_events`. The
checkpoint command exits non-zero and does not leave a signed-looking partial
object. A configured store that is missing, empty (when rows exist), corrupt,
or has an invalid signature fails closed.

**Not claimed.** This control does not protect an attacker who controls **both**
PostgreSQL **and** the checkpoint private key or the external checkpoint store.
A same-host local disk used in tests is **not** a production immutable store.

## Consequences

### Positive

- A rewritten, internally valid in-database chain fails verification against an
  earlier signed checkpoint.
- Application and database roles can verify with the public key without holding
  the signer.

### Negative / accepted cost

- Operators must provision and rotate Ed25519 keys outside PostgreSQL.
- Checkpoint objects must be retained; deleting the only required checkpoint is
  a detectable failure, not a silent skip.

### Risks and their mitigations

- **Local disk on the API host.** Convenient for tests; production must point
  `AUDIT_CHECKPOINT_DISK` at storage the database owner does not control
  (object-lock/WORM bucket or equivalent) and keep the private key in a secret
  manager or HSM. See [audit-chain-checkpoint.md](../runbooks/audit-chain-checkpoint.md).
- **Key loss.** Existing checkpoints remain verifiable with the public key.
  New checkpoints cannot be created until the signer is restored. Do not
  generate a replacement key and pretend history is continuous; introduce a new
  `key_id` and retain old public keys for verification of old objects.
- **Scheduler overlap.** `audit:checkpoint-chain` uses `withoutOverlapping()`
  and `onOneServer()`. The tip is read under the same PostgreSQL advisory lock
  as append, then signed after the lock is released.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| SHA-256 checksum of the latest row stored on disk | Not a signature; a database owner who can write the disk can recompute it |
| HMAC with a key also used for identity | Independent verification wants a public key; HMAC shares a secret with the verifier |
| Storing checkpoints in `audit_events` | Still inside the mutable database the threat model does not trust |
| Hard-coded S3 client | The repository already has Laravel disks; production chooses the disk |

## Migration and rollback impact

Enable `AUDIT_CHECKPOINT_ENABLED` and `AUDIT_CHECKPOINT_REQUIRED` only after
keys and a disk exist. Disabling checkpoint verification returns the verifier
to in-database chain checks only; existing checkpoint objects remain on the
disk and can be re-enabled.

## Verification

Pest covers valid signatures, payload/signature/key tampering, missing and
malformed objects, legitimate appends after a checkpoint, refusal to sign an
invalid chain, role isolation of the private key, and a full in-database rehash
that the DB verifier would accept.

## Review requirement

Security and operations own production key placement and object-lock storage.
Engineering cannot claim the test filesystem equals that store.
