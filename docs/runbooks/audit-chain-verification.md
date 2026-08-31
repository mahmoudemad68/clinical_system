# Audit chain verification failed or went silent

**Alert:** `AuditChainVerificationFailed`, `AuditChainVerificationStale`
**Severity:** critical · **Owner:** security · **Component:** audit

See also [audit-chain-checkpoint](audit-chain-checkpoint.md) and [ADR 0015](../adr/0015-audit-chain-external-checkpoint.md).

## Routing

These alerts carry `severity=critical` and `owner=security`. Alertmanager sends
that pair to receiver `security-critical` — the same class as
`RedactionCanaryDetected` and `RefreshTokenReuse`. Receiver endpoints are
environment-managed; the routing contract is
[`infra/monitoring/alertmanager.yaml`](../../infra/monitoring/alertmanager.yaml).

## What this means

`AuditChainVerificationFailed` means the scheduled `audit:verify-chain` ran and
did not pass. Causes include an in-database hash mismatch, a broken previous-hash
link, a missing/reordered/forked row, a required checkpoint that is missing,
malformed, or wrongly signed, a checkpointed row that no longer matches, or
configuration that makes required verification impossible.

`AuditChainVerificationStale` means no verification execution completed for more
than 45 minutes (three 15-minute schedule intervals). That includes a process
that is scraped but has never run the verifier: `last_run` stays 0 while
staleness grows from the first scrape. The chain may still be intact; the
watcher is not running.

Neither alert contains row hashes, signatures, keys, event metadata, or clinical
data. Reason codes stay in command output, not in metric labels.

## User impact

Privileged product flows continue. Tamper evidence is degraded until verification
passes again. Treat Failed as a possible database-owner/migrator rewrite.

## Confirm

1. Scrape `/metrics` for:
   - `clinic_audit_chain_verification_ok` (1 pass, 0 fail; ignore unless `last_run` > 0)
   - `clinic_audit_chain_verification_last_run_timestamp_seconds`
   - `clinic_audit_chain_verification_last_success_timestamp_seconds`
   - `clinic_audit_chain_verification_failures_total` (historical; does not reset on success)
   - `clinic_audit_chain_verification_staleness_seconds`
2. Run `php artisan audit:verify-chain`. Note `checked`, `ok`, `checkpoint`, and
   the bounded `reason=` code. Do not copy private keys, signatures, row payloads,
   or hashes into tickets.
3. Do not disable checkpoint verification or rewrite `audit_events` to clear the page.

## Immediate action

1. **Preserve evidence.** Snapshot the external checkpoint objects (copy; do not
   delete). Record the metric values and command counts. Do not mint a new
   checkpoint to silence the alert.
2. **Do not repair `audit_events` yet.** Owner/migrator UPDATE/DELETE is itself
   the threat. Capture the current table (or a logical backup) before any rewrite.
3. Compare the signed checkpoint history to persisted `chain_sequence` / `row_hash`
   without pasting those values into chat. A hash mismatch against a valid
   signature is the ISR-008 rewrite case.
4. Isolate suspected DB-owner/migrator compromise: freeze migrator credentials,
   review recent DDL and trigger disables, keep `clinic_app` / `clinic_worker` /
   `clinic_audit_writer` / `clinic_reporter` off the signing key (it is not in
   PostgreSQL).
5. Check checkpoint store availability and public-key configuration. Missing
   required keys fail closed; that is not a reason to skip verification.
6. Escalate on the **security-critical** path (same on-call class as redaction
   canary and refresh-token reuse). Engineering does not close this as a flake.

## Recovery

Only after independent evidence that the database matches the signed checkpoints
(or an authorized restore):

1. Restore from a known-good backup if the chain was rewritten.
2. Re-run `audit:verify-chain` until `ok=yes`.
3. Confirm `/metrics` shows `clinic_audit_chain_verification_ok 1` and staleness
   near zero. `failures_total` may stay elevated; that is expected.
4. Create a new checkpoint only after verification passes.

## Do not

- Swallow verifier failure or convert it to success.
- Rewrite `audit_events` or regenerate a checkpoint to mute Prometheus.
- Put hashes, signatures, keys, National IDs, or clinical text in the alert,
  ticket, or chat.
