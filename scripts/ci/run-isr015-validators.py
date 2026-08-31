#!/usr/bin/env python3
"""Run ISR-015 repository technical gates and synthetic negatives. Fail closed."""

from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
POLICY = ROOT / "scripts" / "ci" / "isr015_policy.py"
SF001 = ROOT / "infra" / "security" / "exceptions" / "SF-001.json"
UNKNOWN_LOCK = ROOT / "scripts" / "ci" / "fixtures" / "license-unknown-lock.json"


def run(args: list[str], *, expect: int) -> str:
    proc = subprocess.run(
        [sys.executable, str(POLICY), *args],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )
    output = (proc.stdout or "") + (proc.stderr or "")
    if proc.returncode != expect:
        raise SystemExit(
            f"expected exit {expect} for {args!r}, got {proc.returncode}\n{output}"
        )
    return output


def expect_pass(label: str, args: list[str]) -> None:
    output = run(args, expect=0)
    print(f"{label}: PASS")
    if output.strip():
        for line in output.strip().splitlines():
            print(f"  {line}")


def expect_fail(label: str, args: list[str], needle: str) -> None:
    output = run(args, expect=1)
    if needle not in output:
        raise SystemExit(f"{label}: expected {needle!r} in output\n{output}")
    print(f"{label}: PASS (failed closed: {needle})")


def write(path: Path, text: str) -> Path:
    path.write_text(text, encoding="utf-8")
    return path


def main() -> int:
    manifest = json.loads(SF001.read_text(encoding="utf-8"))

    expect_pass("A/B path-filters", ["path-filters"])
    expect_pass("C immutable-refs", ["immutable-refs"])

    with tempfile.TemporaryDirectory() as tmp:
        tmpdir = Path(tmp)
        junk = write(tmpdir / "not-trivy.bin", "not-the-trivy-release\n")
        wrong = hashlib.sha256(b"different-bytes\n").hexdigest()
        expect_fail("D trivy checksum mismatch", ["checksum", "--file", str(junk), "--sha256", wrong], "checksum mismatch")

    expect_pass("E workflow-permissions", ["workflow-permissions"])
    expect_pass("F license-gate current tree", ["license-gate"])
    expect_fail(
        "G synthetic UNKNOWN license",
        ["license-gate", "--lock", str(UNKNOWN_LOCK)],
        "UNKNOWN license",
    )

    malformed_baseline = None
    with tempfile.TemporaryDirectory() as tmp:
        tmpdir = Path(tmp)
        malformed_baseline = write(tmpdir / "baseline.json", "{not-json")
        expect_fail(
            "G malformed license baseline",
            ["license-gate", "--baseline", str(malformed_baseline)],
            "malformed JSON",
        )

        extra_ignore = write(
            tmpdir / "extra.ignore",
            "CVE-2026-56876\nGHSA-jmr9-qjv8-65gv\nCVE-0000-0000\n",
        )
        missing_ignore = write(tmpdir / "missing.ignore", "CVE-2026-56876\n")
        expired = dict(manifest)
        expired["expires_at"] = "2020-01-01T00:00:00Z"
        expired_path = write(tmpdir / "expired.json", json.dumps(expired, indent=2) + "\n")
        malformed_expiry = dict(manifest)
        malformed_expiry["expires_at"] = "2026-11-26"
        malformed_path = write(tmpdir / "malformed-expiry.json", json.dumps(malformed_expiry, indent=2) + "\n")
        mismatch = dict(manifest)
        mismatch["affected_version"] = "9.9.9"
        mismatch_path = write(tmpdir / "mismatch.json", json.dumps(mismatch, indent=2) + "\n")
        stale = dict(manifest)
        stale["package_lock_key"] = "node_modules/definitely-not-extract-zip"
        stale_path = write(tmpdir / "stale.json", json.dumps(stale, indent=2) + "\n")
        promotion_bad = write(
            tmpdir / "promotion-with-merge-ignore.yaml",
            """
jobs:
  promotion-fs-scan:
    steps:
      - name: Filesystem scan without merge exceptions
        uses: aquasecurity/trivy-action@ed142fd0673e97e23eac54620cfb913e5ce36c25
        with:
          skip-setup-trivy: true
          scan-type: fs
          severity: CRITICAL,HIGH
          exit-code: '1'
          trivyignores: infra/security/trivy-merge.ignore
""",
        )
        workflow_write = write(
            tmpdir / "workflow-level-write.yaml",
            """
permissions:
  contents: read
  packages: write
  id-token: write
  attestations: write
jobs:
  build:
    permissions:
      contents: read
      packages: write
      id-token: write
      attestations: write
    steps: []
  promotion-fs-scan:
    steps: []
  deploy-staging:
    steps: []
  promote-production:
    steps: []
  verify-artifacts:
    steps: []
""",
        )
        provenance_unwired = write(
            tmpdir / "deploy-without-verify.yaml",
            """
jobs:
  build:
    steps: []
  promotion-fs-scan:
    steps:
      - run: true
  verify-artifacts:
    needs: build
    steps:
      - run: bash scripts/ci/verify-signed-images.sh
  deploy-staging:
    needs: [build, promotion-fs-scan]
    environment:
      name: staging
    steps: []
""",
        )

        expect_pass("H valid SF-001 manifest", ["sf001"])
        expect_fail("I extra trivy ignore ID", ["sf001", "--ignore-file", str(extra_ignore)], "extra=")
        expect_fail("J missing trivy ignore ID", ["sf001", "--ignore-file", str(missing_ignore)], "missing=")
        expect_fail("K malformed expiry", ["sf001", "--manifest", str(malformed_path)], "strict UTC")
        expect_fail("L expired exception", ["sf001", "--manifest", str(expired_path)], "expired")
        expect_fail(
            "L expiry reached at boundary",
            ["sf001", "--now", "2026-11-26T00:00:00Z"],
            "expired",
        )
        expect_fail("M package/version mismatch", ["sf001", "--manifest", str(mismatch_path)], "package/version mismatch")
        expect_fail("N stale exception", ["sf001", "--manifest", str(stale_path)], "stale")
        expect_fail(
            "O promotion merge-ignore",
            ["promotion-isolation", "--workflow", str(promotion_bad)],
            "trivy-merge.ignore",
        )
        expect_fail(
            "E workflow-level privileges",
            ["workflow-permissions", "--workflow", str(workflow_write)],
            "workflow-level permissions include",
        )
        expect_fail(
            "Q verify missing from deploy needs",
            ["provenance-wiring", "--workflow", str(provenance_unwired)],
            "deploy-staging must need verify-artifacts",
        )

    expect_pass("O promotion isolation (current workflows)", ["promotion-isolation"])
    expect_pass("P/Q provenance wiring", ["provenance-wiring"])
    expect_pass("R CODEOWNERS coverage", ["codeowners-coverage"])
    print("ISR-015 repository technical validators: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
