#!/usr/bin/env bash
# Verify GHCR image digest, keyless cosign signature, and GitHub provenance
# attestation for the post-merge build. Fail closed. Does not deploy.
set -euo pipefail

DIGEST_DIR="${IMAGE_DIGEST_DIR:?IMAGE_DIGEST_DIR is required}"
REGISTRY="${REGISTRY:-ghcr.io}"
REPO="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
SHA="${GITHUB_SHA:?GITHUB_SHA is required}"
ISSUER="${COSIGN_CERTIFICATE_OIDC_ISSUER:-https://token.actions.githubusercontent.com}"
IDENTITY_REGEXP="${COSIGN_CERTIFICATE_IDENTITY_REGEXP:-https://github.com/${REPO}/.github/workflows/post-merge.yaml@refs/heads/main}"

if ! command -v cosign >/dev/null 2>&1; then
  echo "cosign is required on PATH" >&2
  exit 1
fi
if ! command -v gh >/dev/null 2>&1; then
  echo "gh is required on PATH" >&2
  exit 1
fi

expected_repo="$(echo "${REPO}" | tr '[:upper:]' '[:lower:]')"

for unit in core-api ai-service; do
  digest_file="${DIGEST_DIR}/${unit}.digest"
  image_file="${DIGEST_DIR}/${unit}.image"
  commit_file="${DIGEST_DIR}/${unit}.commit"
  if [[ ! -f "${digest_file}" || ! -f "${image_file}" ]]; then
    echo "missing image identity files for ${unit} in ${DIGEST_DIR}" >&2
    exit 1
  fi
  digest="$(tr -d '[:space:]' < "${digest_file}")"
  image="$(tr -d '[:space:]' < "${image_file}")"
  if [[ ! "${digest}" =~ ^sha256:[a-f0-9]{64}$ ]]; then
    echo "invalid digest for ${unit}: ${digest}" >&2
    exit 1
  fi
  expected_image="$(echo "${REGISTRY}/${expected_repo}/${unit}" | tr '[:upper:]' '[:lower:]')"
  actual_image="$(echo "${image}" | tr '[:upper:]' '[:lower:]')"
  if [[ "${actual_image}" != "${expected_image}" ]]; then
    echo "image repository mismatch for ${unit}: expected ${expected_image}, got ${actual_image}" >&2
    exit 1
  fi
  if [[ -f "${commit_file}" ]]; then
    recorded_sha="$(tr -d '[:space:]' < "${commit_file}")"
    if [[ "${recorded_sha}" != "${SHA}" ]]; then
      echo "provenance subject mismatch for ${unit}: expected ${SHA}, got ${recorded_sha}" >&2
      exit 1
    fi
  fi

  echo "Verifying ${image}@${digest} commit=${SHA}"
  cosign verify \
    --certificate-oidc-issuer "${ISSUER}" \
    --certificate-identity-regexp "${IDENTITY_REGEXP}" \
    "${image}@${digest}"

  gh attestation verify "oci://${image}@${digest}" \
    --repo "${REPO}" \
    --cert-identity-regexp "${IDENTITY_REGEXP}"
done
