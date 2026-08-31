# G-01-20 — Live k6 auth-abuse and Redis-backed rate limits

- **Gate:** G-01-20
- **Result:** PASS
- **CI-green SHA (PR #3, not merged):** `ecabb9ffccc2820c24bd75edeb8c0c5a81d84910`
- **Candidate SHA:** `b161c5b72fc2a30b2a8f1f877c0620bb357dd1f0`
- **Recorded:** 2026-08-27T14:11:35Z
- **k6:** k6 v2.2.0 (commit/00a9a1b7f5, go1.26.5, linux/amd64)
- **k6 exit:** 0

This is a live dual-process Laravel API against the Redis `ratelimit` store
(database index 3). It is not the phpunit array driver, not a mock, and not a
single sequential request chain.

Phase 01 remains **OPEN**. This file does not close the phase.

## Command

```bash
bash scripts/perf/run-k6-auth-abuse.sh
```

## Live API

- Image: `clinic-php-pgsql:local`
- Processes: `clinic-k6-api-a:8080` and `clinic-k6-api-b:8080` (FrankenPHP `php-server`, concurrent listeners)
- Host ports: `127.0.0.1:18080`, `127.0.0.1:18082`
- Database: `clinic_k6`
- `AUTH_RATE_LIMIT_STORE=ratelimit`
- `AUTH_RATE_LIMIT_DRIVER=redis`
- Argon2id `time=1`, `memory=16384` for this measurement so the Redis limiter is the bottleneck
- Runtime config probe: `redis ratelimit 3`
- `FEATURE_AUTH_RECOVERY=true` for this measurement only
- `FEATURE_IDENTITY_PROFILE_CLAIM=false`

## Redis

- Host `redis:6379`, logical DB **3** (`REDIS_RATELIMIT_DB`)
- Shared-process proof: overflow `429` on API A, then API B also `429` before flush
- `DBSIZE` after k6: 30

## Workload

- Below threshold: 2 VUs, 4 shared iterations (login + OTP request + refresh)
- Abuse: constant-vus, 20s, start at 4s
- VUs: login 8, OTP request 6, OTP resend 6, OTP verify 8, refresh 8, MFA 8, recovery start 6, recovery complete 6

## Measurements

| metric | value |
| --- | --- |
| http_reqs | 292 |
| http_req_failed_rate | 0.0 |
| dropped_iterations | 0 |
| unexpected_server_error_rate | 0.0 |
| latency p50 ms | 4452.628452 |
| latency p95 ms | 7684.523073749999 |
| latency p99 ms | 8037.379658269997 |
| retry_after_missing | 0 |
| below_threshold_429 | 0 |

| scenario | 429 count |
| --- | --- |
| login_abuse | 37 |
| otp_request | 26 |
| otp_resend | 26 |
| otp_verify | 37 |
| refresh_abuse | 33 |
| mfa_abuse | 32 |
| recovery_start | 29 |
| recovery_complete | 23 |

## Privacy

Application logs and k6 stdout were scanned for synthetic passwords, OTP codes,
refresh tokens, and phone numbers used by the harness. Leaks: 0.

PASS requires shared Redis 429s across both API processes, k6 thresholds,
zero 5xx, zero below-threshold 429s, 429s in every abuse scenario, Retry-After
on 429s, and no canaries in k6 output or API logs.
