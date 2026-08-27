# G-01-16 — Measured Reverb disconnect SLO

- **Gate:** G-01-16
- **Result:** PASS
- **CI-green SHA (PR #3, not merged):** `14259cf8dd6e6fb3a7452251cc248f479223d0cb`
- **Candidate SHA:** `14259cf8dd6e6fb3a7452251cc248f479223d0cb` (updated to the implementation commit after local commit)
- **Recorded:** 2026-08-27T12:51:00Z
- **Sample size:** 100
- **Timeouts:** 0
- **SLO:** 5 seconds (`identity.session.revocation_slo_seconds` / `AUTH_REVOCATION_SLO_SECONDS`, Phase 01 session revocation propagation)

This is a live Laravel Reverb WebSocket close measurement. It is not Redis PUBLISH-only, not HTTP 401, not a unit test, and not a mocked socket.

Phase 01 remains **OPEN**. This file does not close the phase.

## Runtime

- PostgreSQL + Redis: `docker compose -f infra/docker/compose.yaml --profile core up -d postgres redis`
- Measurement image: `clinic-php-pgsql:local` on Docker network `clinic_default`
- `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_DATABASE=clinic_test`
- `CACHE_STORE=redis`, `SESSION_DRIVER=array`, `REDIS_HOST=redis`
- `BROADCAST_CONNECTION=reverb`
- `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8081`, `REVERB_SCHEME=http`
- `REVERB_APP_ID=clinic-test`, keys from phpunit placeholders
- Live `php artisan reverb:start --host=127.0.0.1 --port=8081` in the same container as Pest (`pcntl` installed if missing)
- Authenticated restricted session (registration + OTP), then `/broadcasting/auth` HMAC for `private-auth.session.{session_id}`
- Logout commits `auth.session_revoked`; in-process `OutboxDispatcher::dispatchBatch()` RPUSH `clinic.session.disconnect`
- Reverb React loop LPOPs every 20ms and disconnects matching sockets
- Negative control: two authorized sockets; revoking one closes only that socket

## Command

```bash
bash scripts/perf/run-reverb-disconnect-slo.sh
```

Optional: `CLINIC_REVERB_SLO_SAMPLES=100 CLINIC_REVERB_SLO_IMAGE=clinic-php-pgsql:local`

Pest group: `--group=reverb-slo` with `CLINIC_REVERB_SLO_RUNTIME=1`. Without that env, the tests skip so CI stays green.

## Measurements (seconds)

Elapsed time is from HTTP logout (revocation commit) until the actual WebSocket close/EOF.

| p50 | p95 | p99 | max | timeouts |
| --- | --- | --- | --- | --- |
| 0.025804 | 0.036743 | 0.044737 | 0.131135 | 0 |

PASS requires every run to close the WebSocket in less than the 5s Phase 01 SLO (max and p99 both below the bound, zero timeouts).
