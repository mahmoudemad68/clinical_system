#!/usr/bin/env bash
# Canonical FrankenPHP/Octane Dataset v1 characterization for P02-AUDIT-002.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE_FILE="$ROOT/infra/docker/compose.yaml"
IMAGE="${CLINIC_CORE_API_IMAGE:-clinic-core-api:chunk17}"
PG_NAME="${CLINIC_P02_PG_NAME:-clinic-perf-postgres}"
REDIS_NAME="${CLINIC_P02_REDIS_NAME:-clinic-perf-redis}"
API_NAME="${CLINIC_P02_API_NAME:-clinic-perf-core-api}"
DB_NAME="${CLINIC_P02_DB_NAME:-clinic_p02_dsv1}"
HOST_PORT="${CLINIC_P02_HOST_PORT:-18090}"
PG_PORT="${CLINIC_P02_PG_PORT:-15432}"
REDIS_PORT="${CLINIC_P02_REDIS_PORT:-16379}"
WORKERS="${CLINIC_P02_WORKERS:-16}"
MAX_REQUESTS="${CLINIC_P02_MAX_REQUESTS:-500}"
MODE="${1:-short}"
RATE="${CLINIC_P02_RATE:-250}"
DURATION="${CLINIC_P02_DURATION:-90s}"
WARMUP_DURATION="${CLINIC_P02_WARMUP_DURATION:-30s}"
EVIDENCE_DIR="${CLINIC_P02_EVIDENCE_DIR:-$ROOT/docs/evidence/phase-02}"
LOG_DIR="${CLINIC_P02_LOG_DIR:-$ROOT/tmp/phase-02-perf}"
ACTORS="$LOG_DIR/actors.json"
KEEP="${CLINIC_P02_KEEP:-1}"
K6_BIN="${CLINIC_K6_BIN:-k6}"

mkdir -p "$EVIDENCE_DIR" "$LOG_DIR/out"
chmod 777 "$LOG_DIR" "$LOG_DIR/out" 2>/dev/null || true

if [[ "$MODE" == "burst" ]]; then
  RATE="${CLINIC_P02_BURST_RATE:-750}"
  DURATION="${CLINIC_P02_BURST_DURATION:-60s}"
  WARMUP_DURATION="0s"
fi
if [[ "$MODE" == "full-cold" || "$MODE" == "full-warm" ]]; then
  DURATION="${CLINIC_P02_FULL_DURATION:-15m}"
  WARMUP_DURATION="${CLINIC_P02_FULL_WARMUP:-3m}"
fi

require_docker() {
  if ! sudo docker info >/dev/null 2>&1; then
    echo "Docker is required." >&2
    exit 1
  fi
}

cleanup() {
  if declare -F stop_stats_sampler >/dev/null; then
    stop_stats_sampler
  fi
  if [[ "$KEEP" != "1" ]]; then
    sudo docker rm -f "$API_NAME" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

api_env() {
  cat <<EOF
-e APP_ENV=production
-e APP_DEBUG=false
-e APP_KEY=base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=
-e APP_URL=http://127.0.0.1:${HOST_PORT}
-e LOG_CHANNEL=stderr
-e LOG_LEVEL=warning
-e TELEMETRY_REDACTION_ENABLED=true
-e TELEMETRY_REDACTION_STRICT=true
-e TELESCOPE_ENABLED=false
-e OCTANE_SERVER=frankenphp
-e OCTANE_HTTPS=false
-e XDG_CONFIG_HOME=/tmp/frankenphp-config
-e XDG_DATA_HOME=/tmp/frankenphp-data
-e DB_CONNECTION=pgsql
-e DB_HOST=127.0.0.1
-e DB_PORT=${PG_PORT}
-e DB_DATABASE=${DB_NAME}
-e DB_USERNAME=clinic_app
-e DB_PASSWORD=local_dev_only_not_a_secret
-e DB_MIGRATION_USERNAME=clinic_migrator
-e DB_MIGRATION_PASSWORD=local_dev_only_not_a_secret
-e DB_AUDIT_USERNAME=clinic_audit_writer
-e DB_AUDIT_PASSWORD=local_dev_only_not_a_secret
-e DB_WORKER_USERNAME=clinic_worker
-e DB_WORKER_PASSWORD=local_dev_only_not_a_secret
-e DB_SSLMODE=prefer
-e CACHE_STORE=redis
-e SESSION_DRIVER=redis
-e QUEUE_CONNECTION=redis
-e REDIS_CLIENT=predis
-e REDIS_HOST=127.0.0.1
-e REDIS_PORT=${REDIS_PORT}
-e REDIS_CACHE_DB=0
-e REDIS_QUEUE_DB=1
-e REDIS_REALTIME_DB=2
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
-e HASH_DRIVER=argon2id
-e ARGON_MEMORY=65536
-e ARGON_THREADS=1
-e ARGON_TIME=4
-e FILESYSTEM_DISK=s3
-e AWS_DEFAULT_REGION=us-east-1
-e AWS_ACCESS_KEY_ID=clinic_local
-e AWS_SECRET_ACCESS_KEY=local_dev_only_not_a_secret
-e AWS_BUCKET=clinic-local-private
-e AWS_ENDPOINT=http://127.0.0.1:1
-e AWS_USE_PATH_STYLE_ENDPOINT=true
EOF
}

require_docker

echo "==> Starting PostgreSQL/PostGIS and Redis on host network (iptables-free nested Docker)"
pg_net="$(sudo docker inspect -f '{{.HostConfig.NetworkMode}}' "$PG_NAME" 2>/dev/null || true)"
redis_net="$(sudo docker inspect -f '{{.HostConfig.NetworkMode}}' "$REDIS_NAME" 2>/dev/null || true)"
if [[ "$pg_net" != "host" ]]; then
  sudo docker rm -f "$PG_NAME" >/dev/null 2>&1 || true
  sudo docker run -d --name "$PG_NAME" --network host \
    -e POSTGRES_DB=clinic \
    -e POSTGRES_USER=clinic_owner \
    -e POSTGRES_PASSWORD=local_dev_only_not_a_secret \
    -e POSTGRES_INITDB_ARGS="--encoding=UTF8 --locale=C" \
    -e PGPORT="$PG_PORT" \
    -v "$ROOT/infra/docker/postgres/initdb:/docker-entrypoint-initdb.d:ro" \
    postgis/postgis:16-3.4-alpine \
    postgres -p "$PG_PORT" -c max_connections=200 -c shared_buffers=512MB -c log_min_duration_statement=200
fi
if [[ "$redis_net" != "host" ]]; then
  sudo docker rm -f "$REDIS_NAME" >/dev/null 2>&1 || true
  sudo docker run -d --name "$REDIS_NAME" --network host \
    redis:7-alpine \
    redis-server --port "$REDIS_PORT" --appendonly no --maxmemory 256mb --maxmemory-policy allkeys-lru
fi

echo "==> Waiting for PostgreSQL and Redis"
for _ in $(seq 1 60); do
  if sudo docker exec -e PGPORT="$PG_PORT" "$PG_NAME" pg_isready -U clinic_owner -d clinic >/dev/null 2>&1 \
    && sudo docker exec "$REDIS_NAME" redis-cli -p "$REDIS_PORT" ping >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "==> Ensuring ${DB_NAME}"
sudo docker exec -e PGPORT="$PG_PORT" -i "$PG_NAME" psql -U clinic_owner -d clinic -v ON_ERROR_STOP=1 <<SQL
SELECT 'CREATE DATABASE ${DB_NAME} OWNER clinic_owner'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec
GRANT ALL ON DATABASE ${DB_NAME} TO clinic_migrator;
GRANT ALL ON DATABASE ${DB_NAME} TO clinic_app;
GRANT CONNECT ON DATABASE ${DB_NAME} TO clinic_audit_writer;
GRANT CONNECT ON DATABASE ${DB_NAME} TO clinic_worker;
SQL
sudo docker exec -e PGPORT="$PG_PORT" -i "$PG_NAME" psql -U clinic_owner -d "$DB_NAME" -v ON_ERROR_STOP=1 <<SQL
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
GRANT ALL ON SCHEMA public TO clinic_migrator;
GRANT ALL ON SCHEMA public TO clinic_app;
GRANT USAGE ON SCHEMA public TO clinic_audit_writer;
GRANT USAGE ON SCHEMA public TO clinic_worker;
ALTER SCHEMA public OWNER TO clinic_migrator;
GRANT CREATE ON DATABASE ${DB_NAME} TO clinic_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO clinic_app;
ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO clinic_app;
ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO clinic_worker;
SQL

migrate_env=(
  --network host
  -e APP_ENV=production
  -e APP_DEBUG=false
  -e APP_KEY=base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=
  -e LOG_CHANNEL=stderr
  -e LOG_LEVEL=warning
  -e TELESCOPE_ENABLED=false
  -e DB_CONNECTION=pgsql
  -e DB_HOST=127.0.0.1
  -e DB_PORT="$PG_PORT"
  -e DB_DATABASE="$DB_NAME"
  -e DB_USERNAME=clinic_migrator
  -e DB_PASSWORD=local_dev_only_not_a_secret
  -e DB_MIGRATION_USERNAME=clinic_migrator
  -e DB_MIGRATION_PASSWORD=local_dev_only_not_a_secret
  -e DB_AUDIT_USERNAME=clinic_audit_writer
  -e DB_AUDIT_PASSWORD=local_dev_only_not_a_secret
  -e DB_SSLMODE=prefer
  -e CACHE_STORE=array
  -e SESSION_DRIVER=array
  -e QUEUE_CONNECTION=sync
  -e IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS=true
  -e IDENTITY_HMAC_VERSION=1
  -e IDENTITY_HMAC_KEY_V1=local_dev_only_identity_hmac_v1_not_a_secret_32b
  -e IDENTITY_ENCRYPTION_VERSION=1
  -e IDENTITY_ENCRYPTION_KEY_V1=local_dev_only_identity_enc_v1_not_a_secret_32b
  -e AUTH_OTP_PEPPER_VERSION=1
  -e AUTH_OTP_PEPPER_V1=local_dev_only_otp_pepper_v1_not_a_secret_32bytes
)

if [[ "${CLINIC_P02_RESEED:-0}" == "1" ]]; then
  echo "==> Dropping ${DB_NAME} for reseed"
  sudo docker exec -e PGPORT="$PG_PORT" -i "$PG_NAME" psql -U clinic_owner -d clinic -v ON_ERROR_STOP=1 <<SQL
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${DB_NAME}' AND pid <> pg_backend_pid();
DROP DATABASE IF EXISTS ${DB_NAME};
CREATE DATABASE ${DB_NAME} OWNER clinic_owner;
GRANT ALL ON DATABASE ${DB_NAME} TO clinic_migrator;
GRANT ALL ON DATABASE ${DB_NAME} TO clinic_app;
GRANT CONNECT ON DATABASE ${DB_NAME} TO clinic_audit_writer;
GRANT CONNECT ON DATABASE ${DB_NAME} TO clinic_worker;
SQL
  sudo docker exec -e PGPORT="$PG_PORT" -i "$PG_NAME" psql -U clinic_owner -d "$DB_NAME" -v ON_ERROR_STOP=1 <<SQL
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
GRANT ALL ON SCHEMA public TO clinic_migrator;
GRANT ALL ON SCHEMA public TO clinic_app;
GRANT USAGE ON SCHEMA public TO clinic_audit_writer;
GRANT USAGE ON SCHEMA public TO clinic_worker;
ALTER SCHEMA public OWNER TO clinic_migrator;
GRANT CREATE ON DATABASE ${DB_NAME} TO clinic_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO clinic_app;
ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO clinic_app;
ALTER DEFAULT PRIVILEGES FOR ROLE clinic_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO clinic_worker;
SQL
fi

if [[ "${CLINIC_P02_SKIP_MIGRATE:-0}" != "1" ]]; then
  echo "==> Migrating ${DB_NAME}"
  sudo docker run --rm --entrypoint php "${migrate_env[@]}" \
    -e HASH_DRIVER=argon2id \
    "$IMAGE" \
    artisan migrate --force --no-interaction
fi

if [[ ! -f "$ACTORS" || "${CLINIC_P02_RESEED:-0}" == "1" ]]; then
  echo "==> Seeding Dataset v1"
  sudo docker run --rm --entrypoint php "${migrate_env[@]}" \
    -v "$LOG_DIR:/out" \
    -v "$ROOT/apps/core-api/tests/Support/bin/seed-phase-02-dataset-v1.php:/app/tests/Support/bin/seed-phase-02-dataset-v1.php:ro" \
    -e CLINIC_P02_DATASET_V1_ACTORS=/out/actors.json \
    -e HASH_DRIVER=argon2id \
    -e ARGON_MEMORY=65536 \
    -e ARGON_TIME=4 \
    -e CLINIC_P02_PATIENTS="${CLINIC_P02_PATIENTS:-50000}" \
    -e CLINIC_P02_DOCTORS="${CLINIC_P02_DOCTORS:-5000}" \
    -e CLINIC_P02_PHARMACY_ORGS="${CLINIC_P02_PHARMACY_ORGS:-2000}" \
    -e CLINIC_P02_PHARMACY_BRANCHES="${CLINIC_P02_PHARMACY_BRANCHES:-6000}" \
    -e CLINIC_P02_CLINIC_LOCATIONS="${CLINIC_P02_CLINIC_LOCATIONS:-15000}" \
    -e CLINIC_P02_CLINIC_MEMBERSHIPS="${CLINIC_P02_CLINIC_MEMBERSHIPS:-45000}" \
    -e CLINIC_P02_PHARMACY_MEMBERSHIPS="${CLINIC_P02_PHARMACY_MEMBERSHIPS:-24000}" \
    -e CLINIC_P02_ADMINS="${CLINIC_P02_ADMINS:-20}" \
    -e CLINIC_P02_ACTOR_PATIENTS="${CLINIC_P02_ACTOR_PATIENTS:-500}" \
    -e CLINIC_P02_ACTOR_DOCTORS_APPROVED="${CLINIC_P02_ACTOR_DOCTORS_APPROVED:-400}" \
    -e CLINIC_P02_ACTOR_DOCTORS_PENDING="${CLINIC_P02_ACTOR_DOCTORS_PENDING:-50}" \
    -e CLINIC_P02_ACTOR_DOCTORS_ONBOARD="${CLINIC_P02_ACTOR_DOCTORS_ONBOARD:-50}" \
    -e CLINIC_P02_ACTOR_PHARMACIES="${CLINIC_P02_ACTOR_PHARMACIES:-250}" \
    -e CLINIC_P02_ACTOR_ADMINS="${CLINIC_P02_ACTOR_ADMINS:-20}" \
    "$IMAGE" \
    -d memory_limit=2048M tests/Support/bin/seed-phase-02-dataset-v1.php
  sudo chmod 644 "$ACTORS" 2>/dev/null || chmod 644 "$ACTORS" || true
  sudo chown "$(id -u):$(id -g)" "$ACTORS" 2>/dev/null || true
fi

echo "==> Granting serving-role DML on migrated tables"
sudo docker exec -e PGPORT="$PG_PORT" -i "$PG_NAME" psql -U clinic_owner -d "$DB_NAME" -v ON_ERROR_STOP=1 <<SQL
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO clinic_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO clinic_worker;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO clinic_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO clinic_worker;
ALTER ROLE clinic_audit_writer CONNECTION LIMIT 40;
SQL

if [[ "$MODE" == "seed" ]]; then
  echo "==> seed complete; skipping Octane/k6"
  exit 0
fi

echo "==> Starting Octane Core API (${WORKERS} workers)"
sudo docker rm -f "$API_NAME" >/dev/null 2>&1 || true
ENV_FILE="$LOG_DIR/core-api.env"
api_env | sed 's/^-e //' >"$ENV_FILE"
sudo docker run -d --name "$API_NAME" --network host \
  --entrypoint sh \
  --env-file "$ENV_FILE" \
  "$IMAGE" \
  -lc "mkdir -p /tmp/frankenphp-config /tmp/frankenphp-data; exec php artisan octane:frankenphp --host=127.0.0.1 --port=${HOST_PORT} --workers=${WORKERS} --max-requests=${MAX_REQUESTS}"

echo "==> Waiting for /live"
for _ in $(seq 1 90); do
  if curl -fsS "http://127.0.0.1:${HOST_PORT}/live" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done
if ! curl -fsS "http://127.0.0.1:${HOST_PORT}/live" >/dev/null 2>&1; then
  echo "Core API did not become live" >&2
  sudo docker logs "$API_NAME" >&2 || true
  exit 1
fi

sample_stats() {
  local dest="$1"
  {
    echo "=== host $(date -u +%FT%TZ) nproc=$(nproc) ==="
    grep -E 'cpu |MemAvailable|MemTotal' /proc/meminfo || true
    head -n 1 /proc/stat
    echo "=== docker stats ==="
    sudo docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}' \
      "$API_NAME" "$PG_NAME" "$REDIS_NAME" || true
  } >>"$dest"
}

STATS="$LOG_DIR/${MODE}-resource.txt"
: >"$STATS"
sample_stats "$STATS"

stop_stats_sampler() {
  if [[ -n "${STATS_SAMPLER_PID:-}" ]]; then
    kill "$STATS_SAMPLER_PID" >/dev/null 2>&1 || true
    wait "$STATS_SAMPLER_PID" >/dev/null 2>&1 || true
    STATS_SAMPLER_PID=""
  fi
}

start_stats_sampler() {
  stop_stats_sampler
  (
    while true; do
      sample_stats "$STATS"
      sleep 5
    done
  ) &
  STATS_SAMPLER_PID=$!
}

run_k6() {
  local label="$1"
  local duration="$2"
  local rate="$3"
  local out_json="$LOG_DIR/out/${label}.json"
  local out_log="$LOG_DIR/${label}.k6.log"
  echo "==> k6 ${label} rate=${rate} duration=${duration}"
  start_stats_sampler
  set +e
  "$K6_BIN" run \
    --summary-export "$out_json" \
    -e CLINIC_API_BASE_URL="http://127.0.0.1:${HOST_PORT}" \
    -e CLINIC_P02_ACTORS_FILE="$ACTORS" \
    -e CLINIC_P02_RATE="$rate" \
    -e CLINIC_P02_DURATION="$duration" \
    -e CLINIC_P02_PRE_VUS="${CLINIC_P02_PRE_VUS:-300}" \
    -e CLINIC_P02_MAX_VUS="${CLINIC_P02_MAX_VUS:-1500}" \
    "$ROOT/tests/k6/phase-02-dataset-v1.js" \
    | tee "$out_log"
  local rc=${PIPESTATUS[0]}
  set -e
  stop_stats_sampler
  sample_stats "$STATS"
  return "$rc"
}

if [[ "$WARMUP_DURATION" != "0s" ]]; then
  run_k6 "${MODE}-warmup" "$WARMUP_DURATION" "$RATE" || true
fi

K6_RC=0
run_k6 "${MODE}-measured" "$DURATION" "$RATE" || K6_RC=$?

sudo docker logs "$API_NAME" >"$LOG_DIR/${MODE}-octane.log" 2>&1 || true
HEAD_SHA="$(git -C "$ROOT" rev-parse HEAD)"
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
NPROC="$(nproc)"

python3 - "$EVIDENCE_DIR/chunk-17-${MODE}.json" "$LOG_DIR" "$MODE" "$RATE" "$DURATION" "$HEAD_SHA" "$NOW" "$WORKERS" "$NPROC" "$K6_RC" "$IMAGE" <<'PY'
import json, pathlib, sys
out, log_dir, mode, rate, duration, head, now, workers, nproc, k6_rc, image = sys.argv[1:]
summary_path = pathlib.Path(log_dir) / "out" / f"{mode}-measured.json"
summary = {}
if summary_path.exists():
    summary = json.loads(summary_path.read_text())
metrics = summary.get("metrics") or {}

def metric(name):
    raw = metrics.get(name) or {}
    if isinstance(raw, dict) and isinstance(raw.get("values"), dict):
        return raw["values"]
    return raw if isinstance(raw, dict) else {}

def v(name, *keys):
    values = metric(name)
    for key in keys:
        if key in values and values[key] is not None:
            return values[key]
    return None

image_name, _, image_tag = image.partition(":")
evidence = {
    "gate": "P02-AUDIT-002",
    "mode": mode,
    "candidate_sha": head,
    "recorded_at": now,
    "runtime_image_name": image_name,
    "runtime_image_tag": image_tag or image_name,
    "runtime": {
        "server": "octane:frankenphp",
        "workers": int(workers),
        "host_nproc": int(nproc),
        "app_debug": False,
        "log_level": "warning",
        "requested_rps": int(rate),
        "duration": duration,
    },
    "k6_exit_code": int(k6_rc),
    "http_reqs": v("http_reqs", "count"),
    "achieved_rps": v("http_reqs", "rate"),
    "dropped_iterations": v("dropped_iterations", "count"),
    "http_req_failed_rate": v("http_req_failed", "rate", "value"),
    "unexpected_request_failure_rate": v("unexpected_request_failure", "rate", "value"),
    "http_2xx": v("http_2xx", "count"),
    "http_4xx": v("http_4xx", "count"),
    "http_5xx": v("http_5xx", "count"),
    "expected_conflict_409": v("expected_conflict_409", "count"),
    "latency_ms": {
        "p50": v("http_req_duration", "p(50)", "med"),
        "p95": v("http_req_duration", "p(95)"),
        "p99": v("http_req_duration", "p(99)"),
        "max": v("http_req_duration", "max"),
    },
    "operations": {},
    "resources": pathlib.Path(log_dir, f"{mode}-resource.txt").read_text() if pathlib.Path(log_dir, f"{mode}-resource.txt").exists() else "",
}
for name in (
    "op_patient_profile_read",
    "op_doctor_profile_read",
    "op_clinic_locations_read",
    "op_pharmacy_org_read",
    "op_pharmacy_branches_read",
    "op_verification_read",
    "op_patient_demographic_update",
    "op_doctor_onboarding_status",
    "op_clinic_location_update",
    "op_membership_operations",
):
    evidence["operations"][name] = {
        "count": v(name, "count"),
        "p50": v(name, "p(50)", "med"),
        "p95": v(name, "p(95)"),
        "p99": v(name, "p(99)"),
    }
pathlib.Path(out).write_text(json.dumps(evidence, indent=2) + "\n")
print(json.dumps({k: evidence[k] for k in evidence if k != "resources"}, indent=2))
PY

echo "==> evidence: $EVIDENCE_DIR/chunk-17-${MODE}.json"
exit "$K6_RC"
