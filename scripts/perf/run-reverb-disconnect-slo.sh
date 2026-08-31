#!/usr/bin/env bash
# Bring up PostgreSQL, Redis, and a live Laravel Reverb process, then measure
# revoke-to-WebSocket-close for G-01-16.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE_FILE="$ROOT/infra/docker/compose.yaml"
EVIDENCE_DIR="$ROOT/docs/evidence/phase-01"
JSON_OUT="$EVIDENCE_DIR/g-01-16-reverb-disconnect-slo.json"
MD_OUT="$EVIDENCE_DIR/g-01-16-reverb-disconnect-slo.md"
SAMPLES="${CLINIC_REVERB_SLO_SAMPLES:-100}"
IMAGE="${CLINIC_REVERB_SLO_IMAGE:-clinic-php-pgsql:local}"
HOST_UID="$(id -u)"
HOST_GID="$(id -g)"

mkdir -p "$EVIDENCE_DIR"

if ! docker info >/dev/null 2>&1; then
  echo "Docker is required to run PostgreSQL, Redis, Reverb, and Pest with pdo_pgsql." >&2
  exit 1
fi

echo "==> Starting PostgreSQL and Redis"
docker compose -f "$COMPOSE_FILE" --profile core up -d postgres redis

echo "==> Waiting for PostgreSQL"
for _ in $(seq 1 40); do
  if docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres pg_isready -U clinic_owner -d clinic >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

NETWORK=clinic_default

echo "==> Ensuring clinic_test exists"
docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres psql -U clinic_owner -d clinic -v ON_ERROR_STOP=1 <<'SQL'
SELECT 'CREATE DATABASE clinic_test OWNER clinic_owner'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'clinic_test')\gexec
GRANT ALL ON DATABASE clinic_test TO clinic_migrator;
GRANT ALL ON DATABASE clinic_test TO clinic_app;
SQL
docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres psql -U clinic_owner -d clinic_test -v ON_ERROR_STOP=1 <<'SQL'
GRANT ALL ON SCHEMA public TO clinic_migrator;
GRANT ALL ON SCHEMA public TO clinic_app;
ALTER SCHEMA public OWNER TO clinic_migrator;
SQL

if [[ ! -f "$ROOT/apps/core-api/.env" ]]; then
  cp "$ROOT/apps/core-api/.env.example" "$ROOT/apps/core-api/.env"
fi

COMMANDS=$(cat <<EOF
set -euo pipefail
if ! php -m | grep -q pcntl; then
  install-php-extensions pcntl
fi
php artisan reverb:start --host=127.0.0.1 --port=8081 >/tmp/reverb.log 2>&1 &
REVERB_PID=\$!
cleanup() {
  kill \$REVERB_PID >/dev/null 2>&1 || true
}
trap cleanup EXIT
for i in \$(seq 1 50); do
  php -r 'exit(@fsockopen("127.0.0.1", 8081) ? 0 : 1);' && break
  sleep 0.2
done
php -r 'exit(@fsockopen("127.0.0.1", 8081) ? 0 : 1);' || { echo "Reverb failed to listen"; cat /tmp/reverb.log; exit 1; }
echo "==> Reverb log:"; cat /tmp/reverb.log || true
export CLINIC_REVERB_SLO_RUNTIME=1
export CLINIC_REVERB_SLO_SAMPLES=${SAMPLES}
export CLINIC_REVERB_SLO_EVIDENCE=/workspace/docs/evidence/phase-01/g-01-16-reverb-disconnect-slo.json
./vendor/bin/pest --group=reverb-slo || { cat /tmp/reverb.log; exit 1; }
chown ${HOST_UID}:${HOST_GID} /workspace/docs/evidence/phase-01/g-01-16-reverb-disconnect-slo.json || true
EOF
)

echo "==> Running Reverb + Pest measurement in ${IMAGE}"
docker run --rm \
  --network "$NETWORK" \
  -v "$ROOT:/workspace" \
  -w /workspace/apps/core-api \
  -e DB_HOST=postgres \
  -e DB_CONNECTION=pgsql \
  -e DB_PORT=5432 \
  -e DB_DATABASE=clinic_test \
  -e DB_USERNAME=clinic_migrator \
  -e DB_PASSWORD=local_dev_only_not_a_secret \
  -e DB_MIGRATION_USERNAME=clinic_migrator \
  -e DB_MIGRATION_PASSWORD=local_dev_only_not_a_secret \
  -e CACHE_STORE=redis \
  -e SESSION_DRIVER=array \
  -e REDIS_CLIENT=predis \
  -e REDIS_HOST=redis \
  -e REDIS_PORT=6379 \
  -e REVERB_HOST=127.0.0.1 \
  -e REVERB_PORT=8081 \
  -e REVERB_SERVER_HOST=127.0.0.1 \
  -e REVERB_SERVER_PORT=8081 \
  -e REVERB_SCHEME=http \
  -e REVERB_APP_ID=clinic-test \
  -e REVERB_APP_KEY=local_dev_only_not_a_secret \
  -e REVERB_APP_SECRET=local_dev_only_not_a_secret \
  -e REVERB_APP_RATE_LIMITING_ENABLED=false \
  -e REVERB_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000 \
  -e BROADCAST_CONNECTION=reverb \
  -e CLINIC_REVERB_SLO_SHA="$(git -C "$ROOT" rev-parse HEAD)" \
  -e AUTH_OTP_GLOBAL_HOURLY_BUDGET=100000 \
  -e AUTH_OTP_VERIFY_PER_IP_PER_MINUTE=100000 \
  -e AUTH_OTP_VERIFY_PER_CHALLENGE_PER_MINUTE=100000 \
  "$IMAGE" \
  sh -c "$COMMANDS"

HEAD_SHA="$(git -C "$ROOT" rev-parse HEAD)"
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

python3 - "$JSON_OUT" "$MD_OUT" "$HEAD_SHA" "$NOW" "$SAMPLES" <<'PY'
import json, sys, pathlib
json_path, md_path, head, now, samples = sys.argv[1:]
data = json.loads(pathlib.Path(json_path).read_text())
data["candidate_sha"] = head
data["recorded_at"] = now
pathlib.Path(json_path).write_text(json.dumps(data, indent=2) + "\n")
md = f"""# G-01-16 — Measured Reverb disconnect SLO

- **Gate:** G-01-16
- **Result:** {data.get("result")}
- **Candidate SHA:** `{head}`
- **Recorded:** {now}
- **Sample size:** {data.get("sample_size", samples)}
- **SLO:** {data.get("slo_seconds")} seconds (`identity.session.revocation_slo_seconds` / `AUTH_REVOCATION_SLO_SECONDS`, Phase 01 session revocation propagation)

## Runtime

- PostgreSQL + Redis via `docker compose --profile core up -d postgres redis`
- Live `php artisan reverb:start --host=127.0.0.1 --port=8081`
- Pest in-process authenticated session + outbox consumer after commit
- Actual Pusher-protocol WebSocket to Reverb `private-auth.session.{{session_id}}`
- Socket close is the Reverb process draining Redis list `clinic.session.disconnect`

## Command

```bash
bash scripts/perf/run-reverb-disconnect-slo.sh
```

## Measurements (seconds)

| p50 | p95 | p99 | max | timeouts |
| --- | --- | --- | --- | --- |
| {data.get("p50_seconds")} | {data.get("p95_seconds")} | {data.get("p99_seconds")} | {data.get("max_seconds")} | {data.get("timeouts")} |

PASS requires every run to close the WebSocket in less than the 5s Phase 01 SLO (max and p99 both below the bound, zero timeouts).
"""
pathlib.Path(md_path).write_text(md)
print(md)
PY
