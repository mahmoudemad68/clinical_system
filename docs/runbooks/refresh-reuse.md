# Refresh-token reuse

**Alert:** `RefreshTokenReuse`

## Impact

A stolen or replayed refresh credential was presented after rotation. The token family is revoked.

## Confirm

`clinic_auth_attempts_total{result="refresh_reuse"}` increased. The user will need to sign in again.

## Do

Treat as suspected theft. Follow [account-takeover](account-takeover.md). Do not re-issue the same family.
