# Emergency credential rotation and artifact revocation

What fired: a credential, signing key, or issued artifact must be treated as
compromised. User impact: sessions or workers that still hold the old secret
will fail closed; callers must not be told *why* beyond a generic
unauthenticated or dependency error.

This is the Phase 00 mandatory control. It is a procedure, not a claim that
production keys exist yet.

## Confirm

1. Identify the secret *class* from the correlation ID and audit row, never from
   a pasted value. Classes: `app.key`, Sanctum tokens (Phase 01), Reverb app
   secret, AI internal token, S3 keys, Postgres roles, Redis AUTH, OTel
   exporter, Sentry DSN, Electron signing identity (Phase 23).
2. Check `platform_config_audits` for `kind = secret_access` or `config`.
   Values longer than 32 characters are stored as `[withheld]`.
3. Do not open production databases, object stores, or log dumps to "see the
   key". If you need the value, rotation has already failed as a process.

## Immediate actions

1. **Issue a new secret in the secret manager.** Do not reuse material. Do not
   commit it. Do not put it in chat.
2. **Inject at runtime** into the affected workload only (app, worker,
   migrator, AI, backup). Workloads do not share broad credentials.
3. **Revoke the old secret** after the new one is serving. For tokens, revoke
   server-side first so a stolen copy dies even if a client still holds it.
4. **Invalidate issued artifacts** that were signed or encrypted with the old
   material: container images waiting promotion, Electron packages, signed
   URLs. Prefer digest pin + deny list over "rebuild later".
5. **Record the rotation** with `ConfigChangeAuditor` (`secret_access`, key
   name, `to_value` withheld). Include the correlation ID in the incident
   ticket, not the secret.
6. **Watch** `clinic_redaction_canary_total` and authorization-denial alerts.
   A spike after rotation is expected; a canary is not.

## Do not

- Disable redaction to debug the incident.
- Rotate `APP_KEY` without a planned re-encrypt of cookies/cursors; pagination
  cursors minted with the old key become `CURSOR_INVALID`, which is correct.
- Rotate Postgres migrator and app roles in the same untested step.
- Leave the old AI internal token valid "for compatibility". Dual-token is a
  dual-read window with an expiry, not an open pair.

## Follow-up

Time-box any remaining old-secret access to hours, not days. Phase 23 owns
production promotion and signed-artifact revocation at go-live. This runbook
is the engineering procedure those gates will execute.
