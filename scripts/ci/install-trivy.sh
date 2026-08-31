#!/usr/bin/env bash
# Install the pinned Trivy release after verifying its SHA-256. Fail closed.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PINS="${ROOT}/infra/security/ci-executable-pins.json"

if [[ ! -f "${PINS}" ]]; then
  echo "missing Trivy pin catalog: ${PINS}" >&2
  exit 1
fi

mapfile -t TRIVY_PIN < <(python3 - "${PINS}" <<'PY'
import json
import sys

pins = json.load(open(sys.argv[1], encoding="utf-8"))
tool = next((item for item in pins.get("tools", []) if item.get("name") == "trivy"), None)
if not tool:
    raise SystemExit("trivy pin missing from catalog")
for key in ("url", "sha256", "artifact", "version"):
    if not tool.get(key):
        raise SystemExit(f"trivy pin missing {key}")
print(tool["url"])
print(tool["sha256"])
print(tool["artifact"])
print(tool["version"])
PY
)

URL="${TRIVY_PIN[0]}"
EXPECTED_SHA256="${TRIVY_PIN[1]}"
ARTIFACT="${TRIVY_PIN[2]}"
VERSION="${TRIVY_PIN[3]}"

WORKDIR="$(mktemp -d)"
trap 'rm -rf "${WORKDIR}"' EXIT
ARCHIVE="${WORKDIR}/${ARTIFACT}"

echo "Installing Trivy ${VERSION} from ${URL}"
curl --fail --silent --show-error --location --output "${ARCHIVE}" "${URL}"

echo "${EXPECTED_SHA256}  ${ARCHIVE}" | sha256sum --strict -c -

tar -xzf "${ARCHIVE}" -C "${WORKDIR}" trivy
install -m 0755 "${WORKDIR}/trivy" "${WORKDIR}/trivy.bin"

DEST="${HOME}/.local/bin"
mkdir -p "${DEST}"
mv "${WORKDIR}/trivy.bin" "${DEST}/trivy"

if [[ -n "${GITHUB_PATH:-}" ]]; then
  echo "${DEST}" >> "${GITHUB_PATH}"
fi
export PATH="${DEST}:${PATH}"

trivy --version
