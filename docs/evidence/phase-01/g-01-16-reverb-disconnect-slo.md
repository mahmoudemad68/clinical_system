# G-01-16 — Measured Reverb disconnect SLO

- **Gate:** G-01-16
- **Result:** PASS
- **Candidate SHA:** `1164dd4b96145d442752973db00dcb6e58f41bf6`
- **Recorded:** 2026-08-30T12:49:58Z
- **Sample size:** 100
- **SLO:** 5 seconds (`identity.session.revocation_slo_seconds` / `AUTH_REVOCATION_SLO_SECONDS`, Phase 01 session revocation propagation)

## Runtime

- PostgreSQL + Redis via `docker compose --profile core up -d postgres redis`
- Live `php artisan reverb:start --host=127.0.0.1 --port=8081`
- Pest in-process authenticated session + outbox consumer after commit
- Actual Pusher-protocol WebSocket to Reverb `private-auth.session.{session_id}`
- Socket close is the Reverb process draining Redis list `clinic.session.disconnect`

## Command

```bash
bash scripts/perf/run-reverb-disconnect-slo.sh
```

## Measurements (seconds)

| p50 | p95 | p99 | max | timeouts |
| --- | --- | --- | --- | --- |
| 0.095847 | 0.210985 | 0.220569 | 0.228422 | 0 |

PASS requires every run to close the WebSocket in less than the 5s Phase 01 SLO (max and p99 both below the bound, zero timeouts).
