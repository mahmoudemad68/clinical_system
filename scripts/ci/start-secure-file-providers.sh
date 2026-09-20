#!/usr/bin/env bash
# Start digest-pinned MinIO and clamd for the secure-file provider CI lane.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MINIO_IMAGE="minio/minio:RELEASE.2025-04-22T22-12-26Z@sha256:a1ea29fa28355559ef137d71fc570e508a214ec84ff8083e39bc5428980b015e"
CLAMAV_IMAGE="clamav/clamav:1.4.6@sha256:f156095071757e3838caa50265d65e36cdf7f934a27aacf851ea6d2fadbe8200"

wait_tcp() {
  local host="$1"
  local port="$2"
  local attempts="$3"
  local i
  for i in $(seq 1 "${attempts}"); do
    if (echo >/dev/tcp/"${host}"/"${port}") >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "::error::${host}:${port} did not become reachable" >&2
  return 1
}

docker rm -f clinic-ci-minio clinic-ci-clamav >/dev/null 2>&1 || true

docker run -d --name clinic-ci-minio --network host \
  -e MINIO_ROOT_USER=clinic_local \
  -e MINIO_ROOT_PASSWORD=local_dev_only_not_a_secret \
  "${MINIO_IMAGE}" server /data --console-address ":9001"

docker run -d --name clinic-ci-clamav --network host \
  -e CLAMD_CONF_TCPSocket=3310 \
  "${CLAMAV_IMAGE}"

wait_tcp 127.0.0.1 9000 30
bash "${ROOT}/scripts/ci/provision-minio-bucket.sh"

wait_tcp 127.0.0.1 3310 120
deadline=$((SECONDS + 240))
while (( SECONDS < deadline )); do
  if echo PING | nc -w 1 127.0.0.1 3310 2>/dev/null | grep -q PONG; then
    echo "clamd is ready"
    exit 0
  fi
  sleep 4
done

echo "::error::clamd did not answer PONG" >&2
exit 1
