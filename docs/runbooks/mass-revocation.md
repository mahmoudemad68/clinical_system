# Mass session revocation

**Alert:** incident commander decision, or `RefreshTokenReuse` across many actors.

## Do

1. For one actor: `POST /api/v1/auth/sessions/revoke-all` with an idempotency key.
2. For many actors: run `auth:prune-expired` only for expiry; mass revoke is a targeted query plus revoke, not a TRUNCATE.
3. HTTP denies immediately on the revoked timestamp. Reverb disconnect is eventual (SessionRevokedConsumer). Treat realtime as a hint and keep REST authoritative.

## Do not

Flush Redis as a substitute for database revocation.
