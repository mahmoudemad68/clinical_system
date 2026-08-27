# Auth abuse harness (Phase 01 / G-01-20)

Live k6 scenarios for login stuffing, OTP request/resend/verify flooding,
invalid refresh reuse, MFA verification abuse, and recovery start/complete.
This is **not** a Phase 21 SLO test and must only target an isolated synthetic
environment.

The script discards HTTP bodies and never prints passwords, OTP codes, tokens,
or National IDs. Thresholds fail the run if a 5xx appears, if an abuse scenario
produces zero `429`s, if below-threshold traffic is limited, or if `Retry-After`
is missing on a `429`.

```bash
bash scripts/perf/run-k6-auth-abuse.sh
```

Direct k6 (already-running Redis-backed API):

```bash
k6 run tests/k6/auth-abuse.js
```

Required live API configuration: `AUTH_RATE_LIMIT_STORE=ratelimit`,
`AUTH_RATE_LIMIT_DRIVER=redis`, Redis database index 3. Do not substitute the
phpunit array driver.
