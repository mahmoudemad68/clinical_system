#!/usr/bin/env bash
# Live Laravel Octane (FrankenPHP) dual-user authenticated HTTP isolation for G-01-18.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE_FILE="$ROOT/infra/docker/compose.yaml"
EVIDENCE_DIR="$ROOT/docs/evidence/phase-01"
JSON_OUT="$EVIDENCE_DIR/g-01-18-octane-alternating-identity.json"
MD_OUT="$EVIDENCE_DIR/g-01-18-octane-alternating-identity.md"
LOG_DIR="$ROOT/tmp/octane-iso"
IDENTITIES="$LOG_DIR/identities.json"
IMAGE="${CLINIC_OCTANE_ISO_IMAGE:-clinic-php-pgsql:local}"
NETWORK=clinic_default
OCTANE_NAME=clinic-octane-iso
HOST_PORT="${CLINIC_OCTANE_ISO_PORT:-18081}"
WORKERS=1
MAX_REQUESTS=10000
ITERATIONS="${CLINIC_OCTANE_ISOLATION_ITERATIONS:-50}"
CONCURRENT="${CLINIC_OCTANE_ISOLATION_CONCURRENT:-20}"
HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
OCTANE_CMD="php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8080 --workers=${WORKERS} --max-requests=${MAX_REQUESTS}"

mkdir -p "$EVIDENCE_DIR" "$LOG_DIR"
: >"$LOG_DIR/octane.log"
chmod 777 "$LOG_DIR" "$EVIDENCE_DIR" 2>/dev/null || true
chmod u+rwX "$ROOT/apps/core-api/storage" "$ROOT/apps/core-api/bootstrap/cache" 2>/dev/null || true

if ! docker info >/dev/null 2>&1; then
  echo "Docker is required to run PostgreSQL, Redis, and live Octane." >&2
  exit 1
fi

cleanup() {
  docker rm -f "$OCTANE_NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> Starting PostgreSQL and Redis"
docker compose -f "$COMPOSE_FILE" --profile core up -d postgres redis

echo "==> Waiting for PostgreSQL and Redis"
for _ in $(seq 1 40); do
  if docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres pg_isready -U clinic_owner -d clinic >/dev/null 2>&1 \
    && docker compose -f "$COMPOSE_FILE" --profile core exec -T redis redis-cli ping >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

echo "==> Ensuring clinic_octane_iso exists"
docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres psql -U clinic_owner -d clinic -v ON_ERROR_STOP=1 <<'SQL'
SELECT 'CREATE DATABASE clinic_octane_iso OWNER clinic_owner'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'clinic_octane_iso')\gexec
GRANT ALL ON DATABASE clinic_octane_iso TO clinic_migrator;
GRANT ALL ON DATABASE clinic_octane_iso TO clinic_app;
GRANT CONNECT ON DATABASE clinic_octane_iso TO clinic_audit_writer;
GRANT CONNECT ON DATABASE clinic_octane_iso TO clinic_worker;
SQL
docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres psql -U clinic_owner -d clinic_octane_iso -v ON_ERROR_STOP=1 <<'SQL'
GRANT ALL ON SCHEMA public TO clinic_migrator;
GRANT ALL ON SCHEMA public TO clinic_app;
GRANT USAGE ON SCHEMA public TO clinic_audit_writer;
GRANT USAGE ON SCHEMA public TO clinic_worker;
ALTER SCHEMA public OWNER TO clinic_migrator;
SQL

if [[ ! -f "$ROOT/apps/core-api/.env" ]]; then
  cp "$ROOT/apps/core-api/.env.example" "$ROOT/apps/core-api/.env"
fi

api_env=(
  -e APP_ENV=local
  -e APP_DEBUG=false
  -e APP_KEY=base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=
  -e APP_URL=http://127.0.0.1:8080
  -e LOG_CHANNEL=stderr
  -e LOG_LEVEL=info
  -e TELEMETRY_REDACTION_ENABLED=true
  -e TELEMETRY_REDACTION_STRICT=true
  -e TELESCOPE_ENABLED=false
  -e OCTANE_SERVER=frankenphp
  -e OCTANE_HTTPS=false
  -e OCTANE_WORKER_PROBE=true
  -e XDG_CONFIG_HOME=/tmp/frankenphp-config
  -e XDG_DATA_HOME=/tmp/frankenphp-data
  -e DB_CONNECTION=pgsql
  -e DB_HOST=postgres
  -e DB_PORT=5432
  -e DB_DATABASE=clinic_octane_iso
  -e DB_USERNAME=clinic_migrator
  -e DB_PASSWORD=local_dev_only_not_a_secret
  -e DB_MIGRATION_USERNAME=clinic_migrator
  -e DB_MIGRATION_PASSWORD=local_dev_only_not_a_secret
  -e DB_AUDIT_USERNAME=clinic_audit_writer
  -e DB_AUDIT_PASSWORD=local_dev_only_not_a_secret
  -e DB_WORKER_USERNAME=clinic_worker
  -e DB_WORKER_PASSWORD=local_dev_only_not_a_secret
  -e CACHE_STORE=redis
  -e SESSION_DRIVER=array
  -e QUEUE_CONNECTION=sync
  -e HASH_DRIVER=argon2id
  -e ARGON_MEMORY=16384
  -e ARGON_THREADS=1
  -e ARGON_TIME=1
  -e REDIS_CLIENT=predis
  -e REDIS_HOST=redis
  -e REDIS_PORT=6379
  -e REDIS_RATELIMIT_DB=3
  -e AUTH_RATE_LIMIT_STORE=ratelimit
  -e AUTH_RATE_LIMIT_DRIVER=redis
  -e FEATURE_AUTH_REGISTRATION=true
  -e FEATURE_AUTH_RECOVERY=false
  -e FEATURE_IDENTITY_PROFILE_CLAIM=false
  -e IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS=true
  -e IDENTITY_HMAC_VERSION=1
  -e IDENTITY_HMAC_KEY_V1=local_dev_only_identity_hmac_v1_not_a_secret_32b
  -e IDENTITY_ENCRYPTION_VERSION=1
  -e IDENTITY_ENCRYPTION_KEY_V1=local_dev_only_identity_enc_v1_not_a_secret_32b
  -e AUTH_OTP_PEPPER_VERSION=1
  -e AUTH_OTP_PEPPER_V1=local_dev_only_otp_pepper_v1_not_a_secret_32bytes
  -e AUTH_LOGIN_PER_IP_PER_MINUTE=100000
  -e AUTH_LOGIN_PER_SUBJECT_PER_MINUTE=100000
  -e AUTH_OTP_PER_IP_PER_HOUR=100000
  -e AUTH_OTP_PER_SUBJECT_PER_HOUR=100000
  -e AUTH_OTP_GLOBAL_HOURLY_BUDGET=100000
  -e AUTH_OTP_VERIFY_PER_IP_PER_MINUTE=100000
  -e AUTH_OTP_VERIFY_PER_CHALLENGE_PER_MINUTE=100000
  -e AUTH_REFRESH_PER_IP_PER_MINUTE=100000
  -e AUTH_REFRESH_PER_DEVICE_PER_MINUTE=100000
  -e AUTH_MFA_PER_CHALLENGE_PER_MINUTE=100000
  -e AUTH_RECOVERY_PER_SUBJECT_PER_HOUR=100000
  -e AUTH_RECOVERY_PER_IP_PER_HOUR=100000
)

echo "==> Migrating clinic_octane_iso"
docker run --rm \
  --network "$NETWORK" \
  -v "$ROOT:/workspace" \
  -w /workspace/apps/core-api \
  "${api_env[@]}" \
  "$IMAGE" \
  php artisan migrate:fresh --force --no-interaction

echo "==> Seeding synthetic identities A and B"
docker run --rm \
  --network "$NETWORK" \
  -v "$ROOT:/workspace" \
  -w /workspace/apps/core-api \
  "${api_env[@]}" \
  -e CLINIC_OCTANE_ISOLATION_IDENTITIES=/workspace/tmp/octane-iso/identities.json \
  "$IMAGE" \
  php tests/Support/bin/seed-octane-isolation-identities.php
chown "${HOST_UID}:${HOST_GID}" "$IDENTITIES" 2>/dev/null || true

echo "==> Starting Octane (${OCTANE_CMD})"
docker rm -f "$OCTANE_NAME" >/dev/null 2>&1 || true
docker run -d \
  --name "$OCTANE_NAME" \
  --network "$NETWORK" \
  -p "127.0.0.1:${HOST_PORT}:8080" \
  -v "$ROOT:/workspace" \
  -w /workspace/apps/core-api \
  "${api_env[@]}" \
  "$IMAGE" \
  sh -lc "if ! php -m | grep -q pcntl; then install-php-extensions pcntl >/dev/null; fi; mkdir -p /tmp/frankenphp-config /tmp/frankenphp-data; exec ${OCTANE_CMD}"

echo "==> Waiting for Octane /live"
for _ in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:${HOST_PORT}/live" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
if ! curl -fsS "http://127.0.0.1:${HOST_PORT}/live" >/dev/null 2>&1; then
  echo "Octane did not become live" >&2
  docker logs "$OCTANE_NAME" >&2 || true
  exit 1
fi

docker logs "$OCTANE_NAME" >"$LOG_DIR/octane.log" 2>&1 || true
docker exec -w /workspace/apps/core-api "$OCTANE_NAME" php artisan octane:status >"$LOG_DIR/octane-status.txt" 2>&1 || true
WORKER_COUNT_LOG="$(grep -E -i 'worker' "$LOG_DIR/octane.log" | head -n 20 || true)"

echo "==> Running Pest HTTP client against live Octane"
docker run --rm \
  --network "$NETWORK" \
  -v "$ROOT:/workspace" \
  -w /workspace/apps/core-api \
  "${api_env[@]}" \
  -e CLINIC_OCTANE_ISOLATION_RUNTIME=1 \
  -e CLINIC_OCTANE_ISOLATION_BASE_URL=http://${OCTANE_NAME}:8080 \
  -e CLINIC_OCTANE_ISOLATION_IDENTITIES=/workspace/tmp/octane-iso/identities.json \
  -e CLINIC_OCTANE_ISOLATION_EVIDENCE=/workspace/docs/evidence/phase-01/g-01-18-octane-alternating-identity.json \
  -e CLINIC_OCTANE_ISOLATION_ITERATIONS="$ITERATIONS" \
  -e CLINIC_OCTANE_ISOLATION_CONCURRENT="$CONCURRENT" \
  -e CLINIC_OCTANE_ISOLATION_SHA="$(git -C "$ROOT" rev-parse HEAD)" \
  "$IMAGE" \
  sh -lc "set -euo pipefail; ./vendor/bin/pest --group=octane-isolation; chown ${HOST_UID}:${HOST_GID} /workspace/docs/evidence/phase-01/g-01-18-octane-alternating-identity.json" \
  || { echo "==> Octane logs on Pest failure"; docker logs "$OCTANE_NAME" >&2 || true; exit 1; }

chown "${HOST_UID}:${HOST_GID}" "$JSON_OUT" 2>/dev/null || true

HEAD_SHA="$(git -C "$ROOT" rev-parse HEAD)"
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
OCTANE_STATUS="$(cat "$LOG_DIR/octane-status.txt" 2>/dev/null || true)"

python3 - "$JSON_OUT" "$MD_OUT" "$HEAD_SHA" "$NOW" "$OCTANE_CMD" "$WORKERS" "$MAX_REQUESTS" "$OCTANE_STATUS" "$WORKER_COUNT_LOG" <<'PY'
import json, sys, pathlib
json_path, md_path, head, now, cmd, workers, max_requests, octane_status, worker_log = sys.argv[1:]
data = json.loads(pathlib.Path(json_path).read_text())
data["candidate_sha"] = head
data["recorded_at"] = now
data["runtime"] = cmd
data["workers"] = int(workers)
data["max_requests"] = int(max_requests)
data["octane_status"] = octane_status.strip()
data["octane_worker_log_excerpt"] = worker_log.strip()
pathlib.Path(json_path).write_text(json.dumps(data, indent=2) + "\n")
users = data.get("users") or {}
user_a = users.get("A") or {}
user_b = users.get("B") or {}
leaks = data.get("leakage_samples") or []
leak_lines = "\n".join(f"- {item}" for item in leaks) if leaks else "- none"
md = f"""# G-01-18 — Octane alternating authenticated identity

- **Gate:** G-01-18
- **Result:** {data.get("result")}
- **Candidate SHA:** `{head}`
- **Recorded:** {now}
- **Leakage failures:** {data.get("leakage_failures")}
- **Worker reuse proven:** {data.get("worker_reuse_proven")}

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
- Command: `{cmd}`
- Workers: {workers}
- Max requests before recycle: {max_requests}
- Worker PIDs observed: `{json.dumps(data.get("worker_pids"))}`
- `octane:status`:

```
{octane_status or "(unavailable)"}
```

## Dual-user scenario

- **A:** synthetic patient, language `en`, assurance `aal1_password`, extra capability `access.context.delegate`, device session
- **B:** synthetic doctor, language `ar`, assurance `aal2_totp`, no extra grant, device session after TOTP
- User A id: `{user_a.get("user_id")}`
- User B id: `{user_b.get("user_id")}`
- Sequence: {data.get("sequence")}

## Request counts

- Sequential alternating iterations: {data.get("sequential_iterations")}
- Concurrent paired GET /me: {data.get("concurrent_pairs")}
- Authenticated GET requests: {data.get("authenticated_gets")}
- Unique response request ids: {data.get("unique_request_ids")}
- Request id collisions: {data.get("request_id_collisions")}

## Command

```bash
bash scripts/perf/run-octane-alternating-identity.sh
```

Optional: `CLINIC_OCTANE_ISOLATION_ITERATIONS=50 CLINIC_OCTANE_ISOLATION_CONCURRENT=20`

Pest group: `--group=octane-isolation` with `CLINIC_OCTANE_ISOLATION_RUNTIME=1`. Without that env, the test skips so CI stays green.

## Leakage samples

{leak_lines}

PASS requires zero leakage failures and a single reused Octane worker PID across the authenticated GET traffic.
"""
pathlib.Path(md_path).write_text(md)
print(md)
PY

chown "${HOST_UID}:${HOST_GID}" "$MD_OUT" "$JSON_OUT" 2>/dev/null || true
