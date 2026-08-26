#!/usr/bin/env bash
set -euo pipefail

# Resolve and print digest-pinned FROM lines for runtime base images.
# ADR 0008: tags move; digests do not. Output is printed for operators to
# copy into Dockerfiles after review. This script does not rewrite files.

images=(
  "dunglas/frankenphp:1-php8.3-alpine"
  "python:3.12-slim-bookworm"
)

for image in "${images[@]}"; do
  if ! command -v docker >/dev/null 2>&1; then
    echo "docker is required to resolve ${image}" >&2
    exit 1
  fi
  docker pull --quiet "${image}" >/dev/null
  digest="$(docker inspect --format='{{index .RepoDigests 0}}' "${image}")"
  echo "${digest}"
done
