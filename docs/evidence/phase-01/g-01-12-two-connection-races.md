# G-01-12 — Two-connection PostgreSQL auth races

- **Gate:** G-01-12
- **Result:** PASS
- **CI-green SHA (PR #3, not merged):** `e80625ef5984d3b69ff358a267e2da041dcadcce`
- **Candidate SHA:** `e80625ef5984d3b69ff358a267e2da041dcadcce`
- **Recorded:** 2026-08-27T13:30:00Z
- **Pest:** 5 passed, 815 assertions, 171.34s
- **Infrastructure failures:** 0 deadlocks, 0 timeouts, 0 scenario failures

This is two independent OS processes, each booting Laravel and opening its own
PostgreSQL session. It is not sequential calls, not Redis, and not mocked I/O.

Phase 01 remains **OPEN**. This file does not close the phase.

## Concurrency method

- Parent Pest process commits setup (`DatabaseTruncation`, no wrapping transaction)
- Two `php tests/Support/bin/auth-race-worker.php` children
- File barrier (ready files, then go) so both HTTP kernels start together
- Isolation: PostgreSQL `read committed`
- Refresh: `SELECT user_devices … FOR UPDATE` on current/previous/consumed hash
- Logout: `FOR UPDATE` on `user_devices` then `auth_sessions` (same order as refresh)
- OTP/recovery: `SELECT otp_requests WHERE id = ? FOR UPDATE`
- Unique indexes: `auth_refresh_consumptions_token_hash_unique`, `user_devices_active_refresh_hash_unique`

## Command

```bash
bash scripts/perf/run-two-connection-auth-races.sh
```

Optional: `CLINIC_TWO_CONNECTION_RACE_ITERATIONS=40`

Pest group: `--group=two-connection-race` with `CLINIC_TWO_CONNECTION_RACE=1`. Without that env, the tests skip so CI stays green.

## Results

Persisted PostgreSQL state was the oracle (live sessions, refresh hashes, consumption uniqueness, `otp_requests.consumed_at` / `attempts`, `users.credential_version`). HTTP status was recorded but not trusted alone.

| scenario | iterations | failures | deadlocks | timeouts | result |
| --- | --- | --- | --- | --- | --- |
| dual_refresh_same_token | 40 | 0 | 0 | 0 | PASS |
| refresh_vs_logout | 40 | 0 | 0 | 0 | PASS |
| rotated_reuse_vs_inflight_successor | 40 | 0 | 0 | 0 | PASS |
| otp_single_consumer_and_attempts | 40 + 40 wrong-code | 0 | 0 | 0 | PASS |
| recovery_otp_single_consumer | 40 | 0 | 0 | 0 | PASS |

PASS requires zero deadlock/timeout/5xx, at most one live successor session/device, unique refresh-consumption hashes, one OTP consume, committed wrong-code attempts, and one recovery apply / credential-version bump.
