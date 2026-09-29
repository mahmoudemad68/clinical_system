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

        waived_ignore = write(
            tmpdir / "waived.ignore",
            "CVE-2026-56876\nGHSA-jmr9-qjv8-65gv\n",
        )
        cve_only_ignore = write(tmpdir / "cve-only.ignore", "CVE-2026-56876\n")
        ghsa_only_ignore = write(tmpdir / "ghsa-only.ignore", "GHSA-jmr9-qjv8-65gv\n")
        malformed_expiry = dict(manifest)
        malformed_expiry["expires_at"] = "2026-11-26"
        malformed_path = write(tmpdir / "malformed-expiry.json", json.dumps(malformed_expiry, indent=2) + "\n")
        stale = dict(manifest)
        stale["scope"] = "MERGE_ONLY"
        stale["historical_merge_exception_active"] = True
        stale["graph_status"] = "PRESENT"
        stale_path = write(tmpdir / "stale.json", json.dumps(stale, indent=2) + "\n")
        accepted = dict(manifest)
        accepted["independent_acceptance_status"] = "ACCEPTED"
        accepted_path = write(tmpdir / "accepted.json", json.dumps(accepted, indent=2) + "\n")
        closed_audit = dict(manifest)
        closed_audit["p02_audit_006"] = "CLOSED"
        closed_path = write(tmpdir / "closed.json", json.dumps(closed_audit, indent=2) + "\n")
        root_lock = ROOT / "package-lock.json"
        e2e_lock = ROOT / "tests" / "desktop-e2e" / "package-lock.json"
        fixtures = tmpdir / "sf001-locks"
        fixtures.mkdir()
        clean_root = fixtures / "package-lock.json"
        clean_e2e = fixtures / "desktop-e2e" / "package-lock.json"
        clean_e2e.parent.mkdir()
        clean_root.write_bytes(root_lock.read_bytes())
        clean_e2e.write_bytes(e2e_lock.read_bytes())

        def inject_extract_zip(src: Path, dest: Path, *, mode: str) -> Path:
            data = json.loads(src.read_text(encoding="utf-8"))
            packages = data.setdefault("packages", {})
            if mode == "hoisted":
                packages["node_modules/extract-zip"] = {
                    "version": "2.0.1",
                    "resolved": "https://registry.npmjs.org/extract-zip/-/extract-zip-2.0.1.tgz",
                }
            elif mode == "nested":
                packages["node_modules/@electron/packager/node_modules/extract-zip"] = {
                    "version": "2.0.1",
                    "resolved": "https://registry.npmjs.org/extract-zip/-/extract-zip-2.0.1.tgz",
                }
            elif mode == "dependency":
                packager = packages.setdefault("node_modules/@electron/packager", {})
                deps = packager.setdefault("dependencies", {})
                deps["extract-zip"] = "^2.0.0"
            else:
                raise SystemExit(f"unknown extract-zip inject mode {mode}")
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_text(json.dumps(data) + "\n", encoding="utf-8")
            return dest

        dirty_root = inject_extract_zip(
            root_lock, fixtures / "dirty-root" / "package-lock.json", mode="hoisted"
        )
        dirty_nested_root = inject_extract_zip(
            root_lock, fixtures / "dirty-nested" / "package-lock.json", mode="nested"
        )
        dirty_dep_root = inject_extract_zip(
            root_lock, fixtures / "dirty-dep" / "package-lock.json", mode="dependency"
        )
        dirty_e2e = inject_extract_zip(
            e2e_lock,
            fixtures / "dirty-e2e" / "desktop-e2e" / "package-lock.json",
            mode="hoisted",
        )
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

        expect_pass("H valid SF-001 absence", ["sf001"])
        expect_pass(
            "H clean lockfile copies still pass",
            ["sf001", "--lock", str(clean_root), "--lock", str(clean_e2e)],
        )
        expect_fail(
            "I SF-001 CVE/GHSA merge ignore reintroduced",
            ["sf001", "--ignore-file", str(waived_ignore)],
            "must not ignore SF-001 advisories",
        )
        expect_fail(
            "I CVE-only merge ignore reintroduced",
            ["sf001", "--ignore-file", str(cve_only_ignore)],
            "must not ignore SF-001 advisories",
        )
        expect_fail(
            "I GHSA-only merge ignore reintroduced",
            ["sf001", "--ignore-file", str(ghsa_only_ignore)],
            "must not ignore SF-001 advisories",
        )
        expect_fail("K malformed expiry", ["sf001", "--manifest", str(malformed_path)], "strict UTC")
        expect_fail(
            "N stale exception claims extract-zip remains present",
            ["sf001", "--manifest", str(stale_path)],
            "intentionally present",
        )
        expect_fail(
            "N independent acceptance must stay pending",
            ["sf001", "--manifest", str(accepted_path)],
            "must not mark SF-001 accepted",
        )
        expect_fail(
            "N P02-AUDIT-006 must remain OPEN",
            ["sf001", "--manifest", str(closed_path)],
            "p02_audit_006 must remain OPEN",
        )
        expect_fail(
            "J extract-zip reintroduced in root lockfile only",
            ["sf001", "--lock", str(dirty_root), "--lock", str(clean_e2e)],
            "extract-zip must not be present",
        )
        expect_fail(
            "J nested extract-zip reintroduced in root lockfile",
            ["sf001", "--lock", str(dirty_nested_root), "--lock", str(clean_e2e)],
            "extract-zip must not be present",
        )
        expect_fail(
            "J extract-zip dependency edge reintroduced in root lockfile",
            ["sf001", "--lock", str(dirty_dep_root), "--lock", str(clean_e2e)],
            "extract-zip must not be present",
        )
        expect_fail(
            "J extract-zip reintroduced in desktop-e2e lockfile only",
            ["sf001", "--lock", str(clean_root), "--lock", str(dirty_e2e)],
            "extract-zip must not be present",
        )
        expect_fail(
            "N only one lockfile checked",
            ["sf001", "--lock", str(clean_root)],
            "must cover both",
        )
        expect_fail(
            "N both lockfile arguments are root",
            ["sf001", "--lock", str(clean_root), "--lock", str(dirty_root)],
            "must cover both",
        )
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
    with tempfile.TemporaryDirectory() as tmp:
        tmpdir = Path(tmp)
        current_workflow = (ROOT / ".github" / "workflows" / "post-merge.yaml").read_text(
            encoding="utf-8"
        )
        gh_token_line = "GH_TOKEN: ${{ github.token }}"
        if gh_token_line not in current_workflow:
            raise SystemExit("post-merge.yaml is missing GH_TOKEN: ${{ github.token }}")

        removed = current_workflow.replace("          GH_TOKEN: ${{ github.token }}\n", "")
        if removed == current_workflow:
            raise SystemExit("GH_TOKEN removal fixture did not change the workflow")
        expect_fail(
            "P3 GH_TOKEN removal fails closed",
            [
                "provenance-wiring",
                "--workflow",
                str(write(tmpdir / "post-merge-no-gh-token.yaml", removed)),
            ],
            "verify step must expose GH_TOKEN bound to ${{ github.token }}",
        )

        mutated = current_workflow.replace(gh_token_line, "GH_TOKEN: ${{ secrets.GH_PAT }}")
        if mutated == current_workflow:
            raise SystemExit("GH_TOKEN mutation fixture did not change the workflow")
        expect_fail(
            "P3 GH_TOKEN mutation fails closed",
            [
                "provenance-wiring",
                "--workflow",
                str(write(tmpdir / "post-merge-pat-gh-token.yaml", mutated)),
            ],
            "verify step GH_TOKEN must be bound to ${{ github.token }}",
        )

        no_attest = current_workflow.replace("      attestations: read\n", "")
        if no_attest == current_workflow:
            raise SystemExit("attestations: read removal fixture did not change the workflow")
        expect_fail(
            "P3 attestations: read removal fails closed",
            [
                "provenance-wiring",
                "--workflow",
                str(write(tmpdir / "post-merge-no-attest.yaml", no_attest)),
            ],
            "verify-artifacts must keep attestations: read",
        )

        no_script = current_workflow.replace(
            "        run: bash scripts/ci/verify-signed-images.sh",
            "        run: echo skipped",
        )
        if no_script == current_workflow:
            raise SystemExit("verification script removal fixture did not change the workflow")
        expect_fail(
            "P3 verification script removal fails closed",
            [
                "provenance-wiring",
                "--workflow",
                str(write(tmpdir / "post-merge-no-script.yaml", no_script)),
            ],
            "verify-artifacts must verify signatures/attestations",
        )
    expect_pass("P2 gh attestation CLI flags", ["gh-attestation-cli"])
    with tempfile.TemporaryDirectory() as tmp:
        tmpdir = Path(tmp)
        current_script = (ROOT / "scripts" / "ci" / "verify-signed-images.sh").read_text(encoding="utf-8")
        drifted = current_script.replace("--cert-identity-regex", "--cert-identity-regexp")
        if drifted == current_script:
            raise SystemExit("gh attestation flag fixture did not change --cert-identity-regex")
        bad_script = write(tmpdir / "verify-signed-images.sh", drifted)
        expect_fail(
            "P2 typo --cert-identity-regexp fails closed",
            ["gh-attestation-cli", "--script", str(bad_script)],
            "must not pass --cert-identity-regexp",
        )
    expect_pass("R CODEOWNERS coverage", ["codeowners-coverage"])

    vex = subprocess.run(
        [sys.executable, str(ROOT / "scripts" / "ci" / "verify_core_api_openvex.py")],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )
    vex_out = (vex.stdout or "") + (vex.stderr or "")
    if vex.returncode != 0:
        raise SystemExit(f"core-api OpenVEX applicability: expected exit 0, got {vex.returncode}\n{vex_out}")
    print("S core-api OpenVEX document/wiring/narrowness: PASS")
    if vex_out.strip():
        for line in vex_out.strip().splitlines():
            print(f"  {line}")

    grpc_vex = subprocess.run(
        [sys.executable, str(ROOT / "scripts" / "ci" / "verify_core_api_grpc_openvex.py")],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )
    grpc_out = (grpc_vex.stdout or "") + (grpc_vex.stderr or "")
    if grpc_vex.returncode != 0:
        raise SystemExit(f"core-api gRPC OpenVEX applicability: expected exit 0, got {grpc_vex.returncode}\n{grpc_out}")
    print("S2 core-api gRPC OpenVEX document/wiring/narrowness: PASS")
    if grpc_out.strip():
        for line in grpc_out.strip().splitlines():
            print(f"  {line}")

    catalog = subprocess.run(
        [sys.executable, str(ROOT / "scripts" / "ci" / "verify_module_catalog_classification.py")],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )
    catalog_out = (catalog.stdout or "") + (catalog.stderr or "")
    if catalog.returncode != 0:
        raise SystemExit(f"module-catalog classification: expected exit 0, got {catalog.returncode}\n{catalog_out}")
    print("S3 module-catalog peak classification: PASS")
    if catalog_out.strip():
        for line in catalog_out.strip().splitlines():
            print(f"  {line}")

    with tempfile.TemporaryDirectory() as tmp:
        tmpdir = Path(tmp)
        current = (ROOT / "docs" / "architecture" / "module-catalog.md").read_text(encoding="utf-8")
        drifted = current.replace(
            "| `Doctors` | 02 | Backend + clinical | sensitive |",
            "| `Doctors` | 02 | Backend + clinical | personal |",
        )
        if drifted == current:
            raise SystemExit("catalog drift fixture did not change Doctors peak")
        bad_catalog = write(tmpdir / "module-catalog.md", drifted)
        drift = subprocess.run(
            [
                sys.executable,
                str(ROOT / "scripts" / "ci" / "verify_module_catalog_classification.py"),
                "--catalog",
                str(bad_catalog),
                "--skip-inventory",
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        drift_out = (drift.stdout or "") + (drift.stderr or "")
        if drift.returncode != 1 or "peak mismatch" not in drift_out:
            raise SystemExit(
                f"catalog drift fixture: expected exit 1 with peak mismatch, got {drift.returncode}\n{drift_out}"
            )
        print("S4 module-catalog Doctors peak drift fails closed: PASS")

    nid = subprocess.run(
        [
            sys.executable,
            str(ROOT / "scripts" / "ci" / "run_gitleaks_national_id_allowlist.py"),
            "--static-only",
        ],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )
    nid_out = (nid.stdout or "") + (nid.stderr or "")
    if nid.returncode != 0:
        raise SystemExit(
            f"gitleaks National-ID allowlist: expected exit 0, got {nid.returncode}\n{nid_out}"
        )
    print("T gitleaks National-ID allowlist static: PASS")
    if nid_out.strip():
        for line in nid_out.strip().splitlines():
            print(f"  {line}")

    minio_pins = subprocess.run(
        [sys.executable, str(ROOT / "scripts" / "ci" / "verify-minio-ci-source-pins.py")],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )
    minio_out = (minio_pins.stdout or "") + (minio_pins.stderr or "")
    if minio_pins.returncode != 0:
        raise SystemExit(
            f"minio CI source pins: expected exit 0, got {minio_pins.returncode}\n{minio_out}"
        )
    print("U minio CI source pins: PASS")
    if minio_out.strip():
        for line in minio_out.strip().splitlines():
            print(f"  {line}")

    print("ISR-015 repository technical validators: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
