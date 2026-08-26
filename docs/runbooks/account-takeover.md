# Suspected account takeover

**Alert:** `RefreshTokenReuse`, `PrivilegedMfaBypassAttempt`, or a support report of unexpected sessions.

## User impact

An attacker may hold a refresh family or a password. Clinical data must stay denied until Phase 02+ grants exist.

## Confirm

1. List the actor's sessions via the authenticated session API (safe metadata only).
2. Check `clinic_auth_attempts_total{result="refresh_reuse"}`.
3. Confirm credential_version increments on password/recovery events.

## Do

1. Revoke all sessions for the actor (`POST /api/v1/auth/sessions/revoke-all`).
2. If recovery is enabled in this environment, complete recovery so credential_version rotates.
3. Notify the account through the generic security notification path. Do not confirm whether a National ID exists.

## Do not

Reset a password from support without the audited recovery workflow. Do not disable MFA from support in V1.
