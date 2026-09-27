#!/usr/bin/env bash
# Start digest-pinned clamd and a source-built MinIO Community fixture for the
# secure-file provider CI lane. Path-filter trigger: scripts/ci/** re-runs this
# job on evidence-only HEADs.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CLAMAV_IMAGE="clamav/clamav:1.4.6@sha256:f156095071757e3838caa50265d65e36cdf7f934a27aacf851ea6d2fadbe8200"
MINIO_IMAGE="clinic-ci-minio:local"
MC_IMAGE="clinic-ci-mc:local"
DOCKERFILE="${ROOT}/infra/docker/minio-ci.Dockerfile"
DOCKER_CONTEXT="${ROOT}/infra/docker"

python3 "${ROOT}/scripts/ci/verify-minio-ci-source-pins.py"

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

export DOCKER_BUILDKIT=1
docker build --pull --target minio -t "${MINIO_IMAGE}" -f "${DOCKERFILE}" "${DOCKER_CONTEXT}"
docker build --pull --target mc -t "${MC_IMAGE}" -f "${DOCKERFILE}" "${DOCKER_CONTEXT}"

docker run -d --name clinic-ci-minio --network host \
  -e MINIO_ROOT_USER=clinic_local \
  -e MINIO_ROOT_PASSWORD=local_dev_only_not_a_secret \
  "${MINIO_IMAGE}" server /data --console-address ":9001"

docker run -d --name clinic-ci-clamav --network host \
  -e CLAMD_CONF_TCPSocket=3310 \
  "${CLAMAV_IMAGE}"

wait_tcp 127.0.0.1 9000 30
export MINIO_MC_IMAGE="${MC_IMAGE}"
if ! bash "${ROOT}/scripts/ci/provision-minio-bucket.sh"; then
  echo "::error::MinIO bucket provision failed" >&2
  docker logs clinic-ci-minio >&2 || true
  exit 1
fi
echo "MinIO bucket clinic-local-private is private"

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
