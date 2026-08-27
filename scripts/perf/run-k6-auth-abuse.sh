#!/usr/bin/env bash
# Live dual-process Laravel API + Redis ratelimit store + k6 G-01-20 evidence.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE_FILE="$ROOT/infra/docker/compose.yaml"
EVIDENCE_DIR="$ROOT/docs/evidence/phase-01"
JSON_OUT="$EVIDENCE_DIR/g-01-20-k6-auth-abuse.json"
MD_OUT="$EVIDENCE_DIR/g-01-20-k6-auth-abuse.md"
RAW_OUT="$EVIDENCE_DIR/g-01-20-k6-raw.json"
LOG_DIR="$ROOT/tmp/k6-auth-abuse"
IMAGE="${CLINIC_K6_AUTH_ABUSE_IMAGE:-clinic-php-pgsql:local}"
K6_IMAGE="${CLINIC_K6_IMAGE:-grafana/k6:latest}"
NETWORK=clinic_default
API_A=clinic-k6-api-a
API_B=clinic-k6-api-b
HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
LOGIN_IP_LIMIT="${AUTH_LOGIN_PER_IP_PER_MINUTE:-8}"

mkdir -p "$EVIDENCE_DIR" "$LOG_DIR"
: >"$LOG_DIR/api-a.log"
: >"$LOG_DIR/api-b.log"
: >"$LOG_DIR/k6.stdout"
: >"$LOG_DIR/share-proof.txt"

if ! docker info >/dev/null 2>&1; then
  echo "Docker is required to run PostgreSQL, Redis, the live API, and k6." >&2
  exit 1
fi

cleanup() {
  docker rm -f "$API_A" "$API_B" >/dev/null 2>&1 || true
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

echo "==> Ensuring clinic_k6 exists"
docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres psql -U clinic_owner -d clinic -v ON_ERROR_STOP=1 <<'SQL'
SELECT 'CREATE DATABASE clinic_k6 OWNER clinic_owner'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'clinic_k6')\gexec
GRANT ALL ON DATABASE clinic_k6 TO clinic_migrator;
GRANT ALL ON DATABASE clinic_k6 TO clinic_app;
SQL
docker compose -f "$COMPOSE_FILE" --profile core exec -T postgres psql -U clinic_owner -d clinic_k6 -v ON_ERROR_STOP=1 <<'SQL'
GRANT ALL ON SCHEMA public TO clinic_migrator;
GRANT ALL ON SCHEMA public TO clinic_app;
ALTER SCHEMA public OWNER TO clinic_migrator;
SQL

if [[ ! -f "$ROOT/apps/core-api/.env" ]]; then
  cp "$ROOT/apps/core-api/.env.example" "$ROOT/apps/core-api/.env"
fi

chmod u+rwX "$ROOT/apps/core-api/storage" "$ROOT/apps/core-api/bootstrap/cache" 2>/dev/null || true
mkdir -p "$LOG_DIR/out"
chmod 777 "$LOG_DIR/out" "$EVIDENCE_DIR" 2>/dev/null || true

api_env=(
  -e APP_ENV=local
  -e APP_DEBUG=false
  -e APP_KEY=base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=
  -e LOG_CHANNEL=stderr
  -e LOG_LEVEL=info
  -e TELEMETRY_REDACTION_ENABLED=true
  -e TELEMETRY_REDACTION_STRICT=true
  -e TELESCOPE_ENABLED=false
  -e DB_CONNECTION=pgsql
  -e DB_HOST=postgres
  -e DB_PORT=5432
  -e DB_DATABASE=clinic_k6
  -e DB_USERNAME=clinic_migrator
  -e DB_PASSWORD=local_dev_only_not_a_secret
  -e DB_MIGRATION_USERNAME=clinic_migrator
  -e DB_MIGRATION_PASSWORD=local_dev_only_not_a_secret
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
  -e FEATURE_AUTH_RECOVERY=true
  -e FEATURE_IDENTITY_PROFILE_CLAIM=false
  -e IDENTITY_ALLOW_SYNTHETIC_NATIONAL_IDS=true
  -e IDENTITY_HMAC_VERSION=1
  -e IDENTITY_HMAC_KEY_V1=local_dev_only_identity_hmac_v1_not_a_secret_32b
  -e IDENTITY_ENCRYPTION_VERSION=1
  -e IDENTITY_ENCRYPTION_KEY_V1=local_dev_only_identity_enc_v1_not_a_secret_32b
  -e AUTH_OTP_PEPPER_VERSION=1
  -e AUTH_OTP_PEPPER_V1=local_dev_only_otp_pepper_v1_not_a_secret_32bytes
  -e AUTH_LOGIN_PER_IP_PER_MINUTE="$LOGIN_IP_LIMIT"
  -e AUTH_LOGIN_PER_SUBJECT_PER_MINUTE=8
  -e AUTH_OTP_PER_IP_PER_HOUR=12
  -e AUTH_OTP_PER_SUBJECT_PER_HOUR=8
  -e AUTH_OTP_GLOBAL_HOURLY_BUDGET=10000
  -e AUTH_OTP_VERIFY_PER_IP_PER_MINUTE=8
  -e AUTH_OTP_VERIFY_PER_CHALLENGE_PER_MINUTE=6
  -e AUTH_REFRESH_PER_IP_PER_MINUTE=10
  -e AUTH_REFRESH_PER_DEVICE_PER_MINUTE=8
  -e AUTH_MFA_PER_CHALLENGE_PER_MINUTE=8
  -e AUTH_RECOVERY_PER_SUBJECT_PER_HOUR=5
  -e AUTH_RECOVERY_PER_IP_PER_HOUR=8
)

echo "==> Migrating clinic_k6"
docker run --rm \
  --network "$NETWORK" \
  -v "$ROOT:/workspace" \
  -w /workspace/apps/core-api \
  "${api_env[@]}" \
  "$IMAGE" \
  php artisan migrate --force --no-interaction

start_api() {
  local name="$1"
  local host_port="$2"

  docker rm -f "$name" >/dev/null 2>&1 || true
  docker run -d \
    --name "$name" \
    --network "$NETWORK" \
    -p "127.0.0.1:${host_port}:8080" \
    -v "$ROOT:/workspace" \
    -w /workspace/apps/core-api \
    "${api_env[@]}" \
    "$IMAGE" \
    frankenphp php-server --listen 0.0.0.0:8080 --root public
}

echo "==> Starting two live API processes"
start_api "$API_A" 18080
start_api "$API_B" 18082

wait_live() {
  local url="$1"
  for _ in $(seq 1 60); do
    if curl -fsS "$url/live" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "API did not become live: $url" >&2
  docker logs "$API_A" >&2 || true
  docker logs "$API_B" >&2 || true
  return 1
}

wait_live "http://127.0.0.1:18080"
wait_live "http://127.0.0.1:18082"

echo "==> Confirming Redis-backed ratelimit store"
RATE_DRIVER="$(docker exec -w /workspace/apps/core-api "$API_A" php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("cache.stores.ratelimit.driver")." ".config("cache.auth_rate_limiter")." ".config("database.redis.ratelimit.database");')"
echo "ratelimit config: $RATE_DRIVER"
if [[ "$RATE_DRIVER" != redis* || "$RATE_DRIVER" != *ratelimit* || "$RATE_DRIVER" != *3* ]]; then
  echo "Live API is not using Redis ratelimit DB 3: $RATE_DRIVER" >&2
  docker logs "$API_A" >&2 || true
  exit 1
fi

echo "==> Flushing Redis DB 3"
docker compose -f "$COMPOSE_FILE" --profile core exec -T redis redis-cli -n 3 FLUSHDB >/dev/null

login_status() {
  local url="$1"
  curl -sS -o /dev/null -w '%{http_code}' \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -X POST "$url/api/v1/auth/login" \
    --data '{"phone":"01000000000","password":"","client_class":"patient_mobile","platform":"android","device_label":"k6"}'
}

echo "==> Proving rate-limit counters are shared across API processes"
share_ok=1
for i in $(seq 1 "$LOGIN_IP_LIMIT"); do
  status="$(login_status http://127.0.0.1:18080)"
  echo "api-a hit $i status=$status" | tee -a "$LOG_DIR/share-proof.txt"
  if [[ "$status" != "401" ]]; then
    echo "login below-threshold expected 401, got $status" >&2
    share_ok=0
  fi
done
status_a="$(login_status http://127.0.0.1:18080)"
status_b="$(login_status http://127.0.0.1:18082)"
echo "api-a overflow status=$status_a" | tee -a "$LOG_DIR/share-proof.txt"
echo "api-b shared status=$status_b" | tee -a "$LOG_DIR/share-proof.txt"
if [[ "$status_a" != "429" || "$status_b" != "429" ]]; then
  echo "Redis-backed limiter is not shared across the two API processes (A=$status_a B=$status_b)." >&2
  share_ok=0
fi

echo "==> Flushing Redis DB 3 before k6"
docker compose -f "$COMPOSE_FILE" --profile core exec -T redis redis-cli -n 3 FLUSHDB >/dev/null

K6_VERSION="$(docker run --rm "$K6_IMAGE" version | head -n 1)"
echo "==> k6 version: $K6_VERSION"

echo "==> Running k6"
set +e
docker run --rm \
  --network "$NETWORK" \
  -v "$ROOT:/workspace" \
  -e CLINIC_API_BASE_URLS=http://${API_A}:8080,http://${API_B}:8080 \
  -v "$LOG_DIR/out:/out" \
  -e CLINIC_K6_SUMMARY=/out/g-01-20-k6-raw.json \
  "$K6_IMAGE" \
  run --summary-export /out/g-01-20-k6-export.json /workspace/tests/k6/auth-abuse.js \
  | tee "$LOG_DIR/k6.stdout"
K6_EXIT=${PIPESTATUS[0]}
set -e
if [[ -f "$LOG_DIR/out/g-01-20-k6-raw.json" ]]; then
  cp "$LOG_DIR/out/g-01-20-k6-raw.json" "$RAW_OUT"
fi

docker logs "$API_A" >"$LOG_DIR/api-a.log" 2>&1 || true
docker logs "$API_B" >"$LOG_DIR/api-b.log" 2>&1 || true

REDIS_DBSIZE="$(docker compose -f "$COMPOSE_FILE" --profile core exec -T redis redis-cli -n 3 DBSIZE | tr -d '\r')"
HEAD_SHA="$(git -C "$ROOT" rev-parse HEAD)"
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

python3 - "$JSON_OUT" "$MD_OUT" "$RAW_OUT" "$HEAD_SHA" "$NOW" "$K6_VERSION" "$RATE_DRIVER" "$REDIS_DBSIZE" "$K6_EXIT" "$share_ok" "$LOG_DIR" "$LOGIN_IP_LIMIT" "$IMAGE" "$K6_IMAGE" <<'PY'
import json, pathlib, re, sys

(
    json_path, md_path, raw_path, head, now, k6_version, rate_driver,
    redis_dbsize, k6_exit, share_ok, log_dir, login_ip_limit, image, k6_image,
) = sys.argv[1:]

raw = {}
if pathlib.Path(raw_path).exists():
    raw = json.loads(pathlib.Path(raw_path).read_text())
export_path = pathlib.Path(log_dir) / "out" / "g-01-20-k6-export.json"
export = {}
if export_path.exists():
    export = json.loads(export_path.read_text())

if not raw.get("http_reqs") and export:
    raw["http_reqs"] = int((export.get("metrics") or {}).get("http_reqs", {}).get("values", {}).get("count") or 0)
    raw["http_req_failed_rate"] = float((export.get("metrics") or {}).get("http_req_failed", {}).get("values", {}).get("rate") or 0)
    raw["dropped_iterations"] = int((export.get("metrics") or {}).get("dropped_iterations", {}).get("values", {}).get("count") or 0)
    raw["unexpected_server_error_rate"] = float((export.get("metrics") or {}).get("unexpected_server_error", {}).get("values", {}).get("rate") or 0)
    duration = (export.get("metrics") or {}).get("http_req_duration", {}).get("values") or {}
    raw["latency_ms"] = {
        "p50": duration.get("p(50)", duration.get("med")),
        "p95": duration.get("p(95)"),
        "p99": duration.get("p(99)"),
        "max": duration.get("max"),
    }
    raw["retry_after_missing"] = int((export.get("metrics") or {}).get("retry_after_missing", {}).get("values", {}).get("count") or 0)
    raw["counts_429"] = {
        "below_threshold": int((export.get("metrics") or {}).get("below_threshold_429", {}).get("values", {}).get("count") or 0),
        "login_abuse": int((export.get("metrics") or {}).get("login_abuse_429", {}).get("values", {}).get("count") or 0),
        "otp_request": int((export.get("metrics") or {}).get("otp_request_429", {}).get("values", {}).get("count") or 0),
        "otp_resend": int((export.get("metrics") or {}).get("otp_resend_429", {}).get("values", {}).get("count") or 0),
        "otp_verify": int((export.get("metrics") or {}).get("otp_verify_429", {}).get("values", {}).get("count") or 0),
        "refresh_abuse": int((export.get("metrics") or {}).get("refresh_abuse_429", {}).get("values", {}).get("count") or 0),
        "mfa_abuse": int((export.get("metrics") or {}).get("mfa_abuse_429", {}).get("values", {}).get("count") or 0),
        "recovery_start": int((export.get("metrics") or {}).get("recovery_start_429", {}).get("values", {}).get("count") or 0),
        "recovery_complete": int((export.get("metrics") or {}).get("recovery_complete_429", {}).get("values", {}).get("count") or 0),
    }

canaries = [
    "",
    "k6InvalidRefreshToken",
    "RecoveredHorse12",
    "246801",
    "01000000000",
    "01000000010",
    "01000000020",
    "01000000030",
    "not-a-real-password",
    "correct-horse-battery",
]
scan_files = [
    pathlib.Path(log_dir) / "k6.stdout",
    pathlib.Path(log_dir) / "api-a.log",
    pathlib.Path(log_dir) / "api-b.log",
    pathlib.Path(raw_path),
]
leaks = []
for path in scan_files:
    if not path.exists():
        continue
    text = path.read_text(errors="replace")
    for canary in canaries:
        if canary in text:
            leaks.append({"file": path.name, "canary": canary})

counts = raw.get("counts_429") or {}
latency = raw.get("latency_ms") or {}
http_reqs = int(raw.get("http_reqs") or 0)
dropped = int(raw.get("dropped_iterations") or 0)
failed_rate = float(raw.get("http_req_failed_rate") or 0)
unexpected = float(raw.get("unexpected_server_error_rate") or 0)
retry_missing = int(raw.get("retry_after_missing") or 0)
below_429 = int(counts.get("below_threshold") or 0)
abuse_429 = {
    key: int(counts.get(key) or 0)
    for key in (
        "login_abuse",
        "otp_request",
        "otp_resend",
        "otp_verify",
        "refresh_abuse",
        "mfa_abuse",
        "recovery_start",
        "recovery_complete",
    )
}
total_429 = sum(abuse_429.values())
share_pass = share_ok == "1"
k6_pass = k6_exit == "0"
no_leaks = leaks == []
redis_keys = int(re.sub(r"[^0-9]", "", str(redis_dbsize)) or 0)
result = "PASS" if share_pass and k6_pass and no_leaks and total_429 > 0 and below_429 == 0 else "FAIL"
if result == "FAIL" and share_pass and no_leaks and total_429 > 0 and not k6_pass:
    result = "PARTIAL"

evidence = {
    "gate": "G-01-20",
    "result": result,
    "candidate_sha": head,
    "recorded_at": now,
    "command": "bash scripts/perf/run-k6-auth-abuse.sh",
    "k6_exit_code": int(k6_exit),
    "k6_version": k6_version,
    "k6_image": k6_image,
    "api_image": image,
    "live_api": {
        "processes": ["clinic-k6-api-a:8080", "clinic-k6-api-b:8080"],
        "host_ports": ["127.0.0.1:18080", "127.0.0.1:18082"],
        "database": "clinic_k6",
        "auth_rate_limit_store": "ratelimit",
        "auth_rate_limit_driver": "redis",
        "cache_store": "redis",
        "session_driver": "array",
        "hash_driver": "argon2id",
        "argon_time": 1,
        "argon_memory": 16384,
        "feature_auth_recovery": True,
        "feature_identity_profile_claim": False,
        "ratelimit_config": rate_driver,
        "limits": {
            "login_per_ip_per_minute": int(login_ip_limit),
            "login_per_subject_per_minute": 8,
            "otp_per_ip_per_hour": 12,
            "otp_per_subject_per_hour": 8,
            "otp_global_hourly_budget": 10000,
            "otp_verify_per_ip_per_minute": 8,
            "otp_verify_per_challenge_per_minute": 6,
            "refresh_per_ip_per_minute": 10,
            "refresh_per_device_per_minute": 8,
            "mfa_per_challenge_per_minute": 8,
            "recovery_per_subject_per_hour": 5,
            "recovery_per_ip_per_hour": 8,
        },
    },
    "redis": {
        "host": "redis",
        "port": 6379,
        "ratelimit_db": 3,
        "dbsize_after_k6": redis_keys,
        "shared_across_processes": share_pass,
    },
    "k6": {
        "script": "tests/k6/auth-abuse.js",
        "base_urls": ["http://clinic-k6-api-a:8080", "http://clinic-k6-api-b:8080"],
        "vus": {
            "below_threshold": {"executor": "shared-iterations", "vus": 2, "iterations": 4},
            "abuse": {
                "executor": "constant-vus",
                "duration": "20s",
                "start_time": "4s",
                "vus": {
                    "login_abuse": 8,
                    "otp_request_abuse": 6,
                    "otp_resend_abuse": 6,
                    "otp_verify_abuse": 8,
                    "refresh_abuse": 8,
                    "mfa_abuse": 8,
                    "recovery_start_abuse": 6,
                    "recovery_complete_abuse": 6,
                },
            },
        },
        "thresholds": [
            "unexpected_server_error rate==0",
            "dropped_iterations count==0",
            "below_threshold_429 count==0",
            "each abuse scenario 429 count>0",
            "retry_after_missing count==0",
            "checks rate>0.99",
        ],
        "http_reqs": http_reqs,
        "http_req_failed_rate": failed_rate,
        "dropped_iterations": dropped,
        "unexpected_server_error_rate": unexpected,
        "latency_ms": latency,
        "counts_429": counts,
        "retry_after_missing": retry_missing,
    },
    "privacy_scan": {
        "leaks": leaks,
        "passed": no_leaks,
    },
}

pathlib.Path(json_path).write_text(json.dumps(evidence, indent=2) + "\n")

rows = "\n".join(
    f"| {name} | {value} |" for name, value in abuse_429.items()
)
md = f"""# G-01-20 — Live k6 auth-abuse and Redis-backed rate limits

- **Gate:** G-01-20
- **Result:** {result}
- **Candidate SHA:** `{head}`
- **Recorded:** {now}
- **k6:** {k6_version}
- **k6 exit:** {k6_exit}

This is a live dual-process Laravel API against the Redis `ratelimit` store
(database index 3). It is not the phpunit array driver, not a mock, and not a
single sequential request chain.

Phase 01 remains **OPEN**. This file does not close the phase.

## Command

```bash
bash scripts/perf/run-k6-auth-abuse.sh
```

## Live API

- Image: `{image}`
- Processes: `clinic-k6-api-a:8080` and `clinic-k6-api-b:8080` (FrankenPHP `php-server`, concurrent listeners)
- Host ports: `127.0.0.1:18080`, `127.0.0.1:18082`
- Database: `clinic_k6`
- `AUTH_RATE_LIMIT_STORE=ratelimit`
- `AUTH_RATE_LIMIT_DRIVER=redis`
- Argon2id `time=1`, `memory=16384` for this measurement so the Redis limiter is the bottleneck
- Runtime config probe: `{rate_driver}`
- `FEATURE_AUTH_RECOVERY=true` for this measurement only
- `FEATURE_IDENTITY_PROFILE_CLAIM=false`

## Redis

- Host `redis:6379`, logical DB **3** (`REDIS_RATELIMIT_DB`)
- Shared-process proof: overflow `429` on API A, then API B also `429` before flush
- `DBSIZE` after k6: {redis_keys}

## Workload

- Below threshold: 2 VUs, 4 shared iterations (login + OTP request + refresh)
- Abuse: constant-vus, 20s, start at 4s
- VUs: login 8, OTP request 6, OTP resend 6, OTP verify 8, refresh 8, MFA 8, recovery start 6, recovery complete 6

## Measurements

| metric | value |
| --- | --- |
| http_reqs | {http_reqs} |
| http_req_failed_rate | {failed_rate} |
| dropped_iterations | {dropped} |
| unexpected_server_error_rate | {unexpected} |
| latency p50 ms | {latency.get("p50")} |
| latency p95 ms | {latency.get("p95")} |
| latency p99 ms | {latency.get("p99")} |
| retry_after_missing | {retry_missing} |
| below_threshold_429 | {below_429} |

| scenario | 429 count |
| --- | --- |
{rows}

## Privacy

Application logs and k6 stdout were scanned for synthetic passwords, OTP codes,
refresh tokens, and phone numbers used by the harness. Leaks: {len(leaks)}.

PASS requires shared Redis 429s across both API processes, k6 thresholds,
zero 5xx, zero below-threshold 429s, 429s in every abuse scenario, Retry-After
on 429s, and no canaries in k6 output or API logs.
"""
pathlib.Path(md_path).write_text(md)
print(md)
if result == "FAIL":
    sys.exit(1)
PY

chown "${HOST_UID}:${HOST_GID}" "$JSON_OUT" "$MD_OUT" "$RAW_OUT" 2>/dev/null || true
echo "==> G-01-20 evidence written to $JSON_OUT"
