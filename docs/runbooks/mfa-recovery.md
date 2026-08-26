# MFA recovery (privileged actors)

Privileged TOTP is required before a doctor, pharmacy, secretary, or admin session is issued.

## Confirm

The actor can still receive a recovery OTP only when `FEATURE_AUTH_RECOVERY` is on (phpunit/local as configured; production stays off until owners enable it).

## Do

1. If recovery is off, keep the session denied. Do not email a TOTP seed.
2. If recovery is on, use `POST /api/v1/auth/recovery/start` then `complete`. That increments credential_version and revokes sessions.
3. Re-enrol TOTP through the audited bootstrap or a later enrolment endpoint. V1 has no self-serve re-read of backup codes.

## Do not

Store a TOTP secret in a ticket. Support cannot disable MFA.
