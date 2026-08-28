# G-01-18 — Octane alternating authenticated identity

- **Gate:** G-01-18
- **Result:** PASS
- **Candidate SHA:** `9296d9c98ba9d7be2e7e8b9ff01d9c9e6cddbf8b`
- **Recorded:** 2026-08-28T12:35:36Z
- **Leakage failures:** 0
- **Worker reuse proven:** True

This is live authenticated HTTP against long-lived Laravel Octane workers.
It is not `php artisan serve`, not Pest kernel `$this->getJson`, not a mock,
and the Octane process is not restarted between user A and user B.

Phase 01 remains **OPEN**. This file does not close the phase.

## Original acceptance criteria (not broadened)

From `docs/phases/01_auth_identity_and_access.md`:

- Security control: *Long-lived Octane leakage: request-scoped actor/context only, no mutable identity singletons, explicit worker reset hooks, and alternating-user regression tests.*
- Security test: *Alternating identities through the same Octane worker proves no actor/capability/response leakage.*
- Exit gate item: *Octane alternating-user leakage ... suites pass.*

Verified here: actor identity (`user_id`, `account_type`, `status`, `language`, `assurance_level`), session/device identifiers in the response body, and capability lists (`/api/v1/me/capabilities`). CSRF, enumeration, replay, credential-stuffing, and BOLA/BFLA remain other suites.

## Runtime

- PostgreSQL + Redis: `docker compose -f infra/docker/compose.yaml --profile core up -d postgres redis`
- Database: `clinic_octane_iso` (`DB_CONNECTION=pgsql`, `DB_HOST=postgres`)
- Session store for device tokens: PostgreSQL `auth_sessions` / `user_devices`
- Rate-limit cache: Redis
- Image: `clinic-php-pgsql:local` on Docker network `clinic_default`
- Command: `php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8080 --workers=1 --max-requests=10000`
- Workers: 1
- Max requests before recycle: 10000
- Worker PIDs observed: `[2395]`
- `octane:status`:

```

   INFO  Octane server is running.  
```

## Dual-user scenario

- **A:** synthetic patient, language `en`, assurance `aal1_password`, extra capability `access.context.delegate`, device session
- **B:** synthetic doctor, language `ar`, assurance `aal2_totp`, no extra grant, device session after TOTP
- User A id: `01a0485d-e72f-76ce-8187-0a141d444c3c`
- User B id: `01a0485d-e72f-779e-8187-0a141d7a4ce1`
- Sequence: login A (password) then login B (password+TOTP); alternate GET /api/v1/me and /api/v1/me/capabilities; then concurrent paired GET /api/v1/me

## Request counts

- Sequential alternating iterations: 50
- Concurrent paired GET /me: 20
- Authenticated GET requests: 244
- Unique response request ids: 244
- Request id collisions: 0

## Command

```bash
bash scripts/perf/run-octane-alternating-identity.sh
```

Optional: `CLINIC_OCTANE_ISOLATION_ITERATIONS=50 CLINIC_OCTANE_ISOLATION_CONCURRENT=20`

Pest group: `--group=octane-isolation` with `CLINIC_OCTANE_ISOLATION_RUNTIME=1`. Without that env, the test skips so CI stays green.

## Leakage samples

- none

PASS requires zero leakage failures and a single reused Octane worker PID across the authenticated GET traffic.
