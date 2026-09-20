#!/usr/bin/env bash
# Provision the local/CI private MinIO bucket. Idempotent.
# Local-only credentials. Real environments must not grant CreateBucket to the app.
set -euo pipefail

ENDPOINT="${MINIO_ENDPOINT:-http://127.0.0.1:9000}"
ACCESS="${MINIO_ROOT_USER:-clinic_local}"
SECRET="${MINIO_ROOT_PASSWORD:-local_dev_only_not_a_secret}"
BUCKET="${MINIO_BUCKET:-clinic-local-private}"
MC_IMAGE="${MINIO_MC_IMAGE:-minio/mc:RELEASE.2025-04-16T18-13-26Z@sha256:aead63c77f9db9107f1696fb08ecb0faeda23729cde94b0f663edf4fe09728e3}"

if command -v mc >/dev/null 2>&1; then
  mc alias set local "${ENDPOINT}" "${ACCESS}" "${SECRET}"
  mc mb --ignore-existing "local/${BUCKET}"
  mc anonymous set none "local/${BUCKET}"
  mc stat "local/${BUCKET}"
  exit 0
fi

docker run --rm --network host \
  "${MC_IMAGE}" \
  /bin/sh -c "set -euo pipefail
    mc alias set local '${ENDPOINT}' '${ACCESS}' '${SECRET}'
    mc mb --ignore-existing local/${BUCKET}
    mc anonymous set none local/${BUCKET}
    mc stat local/${BUCKET}"
