#!/usr/bin/env bash
# Two independent Laravel/PostgreSQL connections racing Phase 01 auth transitions.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE_FILE="$ROOT/infra/docker/compose.yaml"
EVIDENCE_DIR="$ROOT/docs/evidence/phase-01"
JSON_OUT="$EVIDENCE_DIR/g-01-12-two-connection-races.json"
MD_OUT="$EVIDENCE_DIR/g-01-12-two-connection-races.md"
SAMPLES="${CLINIC_TWO_CONNECTION_RACE_ITERATIONS:-40}"
IMAGE="${CLINIC_TWO_CONNECTION_RACE_IMAGE:-clinic-php-pgsql:local}"
HOST_UID="$(id -u)"
HOST_GID="$(id -g)"

mkdir -p "$EVIDENCE_DIR"

if ! docker info >/dev/null 2>&1; then
  echo "Docker is required to run PostgreSQL and Pest with pdo_pgsql." >&2
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
export CLINIC_TWO_CONNECTION_RACE=1
export CLINIC_TWO_CONNECTION_RACE_ITERATIONS=${SAMPLES}
export CLINIC_TWO_CONNECTION_RACE_EVIDENCE=/workspace/docs/evidence/phase-01/g-01-12-two-connection-races.json
./vendor/bin/pest --group=two-connection-race
chown ${HOST_UID}:${HOST_GID} /workspace/docs/evidence/phase-01/g-01-12-two-connection-races.json || true
EOF
)

echo "==> Running two-connection auth races in ${IMAGE}"
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
  -e CACHE_STORE=array \
  -e SESSION_DRIVER=array \
  -e REDIS_CLIENT=predis \
  -e REDIS_HOST=redis \
  -e REDIS_PORT=6379 \
  -e AUTH_OTP_GLOBAL_HOURLY_BUDGET=100000 \
  -e AUTH_OTP_PER_IP_PER_HOUR=100000 \
  -e AUTH_OTP_PER_SUBJECT_PER_HOUR=100000 \
  -e AUTH_OTP_VERIFY_PER_IP_PER_MINUTE=100000 \
  -e AUTH_OTP_VERIFY_PER_CHALLENGE_PER_MINUTE=100000 \
  -e AUTH_RECOVERY_PER_SUBJECT_PER_HOUR=100000 \
  -e AUTH_RECOVERY_PER_IP_PER_HOUR=100000 \
  -e AUTH_REFRESH_PER_DEVICE_PER_MINUTE=100000 \
  -e AUTH_REFRESH_PER_IP_PER_MINUTE=100000 \
  -e CLINIC_TWO_CONNECTION_RACE_SHA="$(git -C "$ROOT" rev-parse HEAD)" \
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
scenarios = data.get("scenarios") or {}
rows = []
for name, row in scenarios.items():
    rows.append(
        f"| {name} | {row.get('iterations')} | {row.get('failures')} | {row.get('deadlocks')} | {row.get('timeouts')} | {row.get('result')} |"
    )
table = "\n".join(rows) if rows else "| (none) | | | | | |"
md = f"""# G-01-12 — Two-connection PostgreSQL auth races

- **Gate:** G-01-12
- **Result:** {data.get("result")}
- **Candidate SHA:** `{head}`
- **Recorded:** {now}
- **Iterations per scenario:** {samples} (wrong-code OTP repeats the same count)

This is two independent OS processes, each booting Laravel and opening its own
PostgreSQL session. It is not sequential calls, not Redis, and not mocked sockets.

Phase 01 remains **OPEN**. This file does not close the phase.

## Concurrency method

- Parent Pest process commits setup (DatabaseTruncation, no wrapping transaction)
- Two `php tests/Support/bin/auth-race-worker.php` children
- File barrier (ready files, then go) so both HTTP kernels start together
- Isolation: `{data.get("db_isolation")}`
- Locks: `{json.dumps(data.get("locking"))}`

## Command

```bash
bash scripts/perf/run-two-connection-auth-races.sh
```

## Results

| scenario | iterations | failures | deadlocks | timeouts | result |
| --- | --- | --- | --- | --- | --- |
{table}

PASS requires zero infrastructure failures (deadlock/timeout/5xx), at most one
live successor session/device, unique refresh-consumption hashes, one OTP
consume, committed wrong-code attempts, and one recovery apply.
"""
pathlib.Path(md_path).write_text(md)
print(md)
PY
