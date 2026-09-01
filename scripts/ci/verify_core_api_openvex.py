#!/usr/bin/env python3
"""Fail-closed OpenVEX applicability and narrowness checks for CVE-2026-56854.

This is not risk acceptance. The VEX is invalid the moment the bound FrankenPHP
binary, base digest, versions, or compiled modules change.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[2]
APPLICABILITY = (
    REPO_ROOT / "infra" / "security" / "vex" / "core-api-frankenphp-cve-2026-56854.applicability.json"
)
OPENVEX = REPO_ROOT / "infra" / "security" / "vex" / "core-api-frankenphp-cve-2026-56854.openvex.json"
DOCKERFILE = REPO_ROOT / "infra" / "docker" / "core-api.Dockerfile"
PR_WORKFLOW = REPO_ROOT / ".github" / "workflows" / "pull-request.yaml"
POST_MERGE_WORKFLOW = REPO_ROOT / ".github" / "workflows" / "post-merge.yaml"
TRIVY_IMAGE_IGNORE = REPO_ROOT / "infra" / "security" / "trivy-image.ignore"
TRIVY_MERGE_IGNORE = REPO_ROOT / "infra" / "security" / "trivy-merge.ignore"
VEX_REL = "infra/security/vex/core-api-frankenphp-cve-2026-56854.openvex.json"

OPENVEX_CONTEXT = "https://openvex.dev/ns/v0.2.0"
OTHER_XCRYPTO_CVE = "CVE-2024-45337"
SYNTHETIC_CRITICAL = "CVE-2099-00001"


class GateError(Exception):
    pass


def fail(message: str) -> None:
    raise GateError(message)


def load_json(path: Path) -> dict[str, Any]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        fail(f"malformed JSON in {path}: {exc}")
    if not isinstance(data, dict):
        fail(f"{path} must be a JSON object")
    return data


def ignore_ids(path: Path) -> list[str]:
    ids: list[str] = []
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        ids.append(line)
    return ids


def binding() -> dict[str, Any]:
    data = load_json(APPLICABILITY)
    required = (
        "cve",
        "go_advisory",
        "status",
        "justification",
        "base_digest",
        "base_image",
        "frankenphp_binary_sha256",
        "frankenphp_version",
        "caddy_version",
        "x_crypto_version",
        "trivy_product_purl",
        "trivy_subcomponent_purl",
        "trivy_vulnerability_id",
        "forbidden_modules",
        "not_risk_acceptance",
        "determination",
        "upstream_fix",
    )
    for key in required:
        if key not in data:
            fail(f"applicability manifest missing {key}")
    if data["cve"] != "CVE-2026-56854":
        fail("applicability cve must be CVE-2026-56854")
    if data["go_advisory"] != "GO-2026-6303":
        fail("applicability go_advisory must be GO-2026-6303")
    if data["status"] != "not_affected":
        fail("applicability status must be not_affected")
    if data["justification"] != "vulnerable_code_not_present":
        fail("applicability justification must be vulnerable_code_not_present")
    if data["determination"] != "NOT_AFFECTED_REACHABILITY_PROVEN":
        fail("applicability determination must be NOT_AFFECTED_REACHABILITY_PROVEN")
    if data["not_risk_acceptance"] is not True:
        fail("applicability must record not_risk_acceptance true")
    if "v0.55.0" not in str(data["upstream_fix"]):
        fail("applicability must record upstream fix target >= v0.55.0")
    return data


def assert_openvex_document() -> dict[str, Any]:
    spec = binding()
    doc = load_json(OPENVEX)
    if doc.get("@context") != OPENVEX_CONTEXT:
        fail("OpenVEX @context must be https://openvex.dev/ns/v0.2.0")
    statements = doc.get("statements")
    if not isinstance(statements, list) or len(statements) != 1:
        fail("OpenVEX must contain exactly one statement")
    stmt = statements[0]
    if not isinstance(stmt, dict):
        fail("OpenVEX statement must be an object")
    vuln = stmt.get("vulnerability")
    if not isinstance(vuln, dict):
        fail("OpenVEX statement missing vulnerability")
    names = {str(vuln.get("name") or "")}
    aliases = vuln.get("aliases") or []
    if not isinstance(aliases, list):
        fail("OpenVEX aliases must be a list")
    names.update(str(item) for item in aliases)
    expected = {spec["cve"], spec["go_advisory"]}
    if names != expected:
        fail(f"OpenVEX vulnerability identifiers must be exactly {sorted(expected)}, got {sorted(names)}")
    if stmt.get("status") != "not_affected":
        fail("OpenVEX status must be not_affected")
    if stmt.get("justification") != "vulnerable_code_not_present":
        fail("OpenVEX justification must be vulnerable_code_not_present")
    impact = str(stmt.get("impact_statement") or "")
    for needle in (
        "FrankenPHP v1.12.7",
        "Caddy v2.11.4",
        "golang.org/x/crypto v0.54.0",
        "ssh.NewServerConn",
        "connection.serverAuthenticate",
        "not claim",
        "not risk acceptance",
        "port 8080",
        spec["frankenphp_binary_sha256"],
        spec["base_digest"],
    ):
        if needle not in impact:
            fail(f"OpenVEX impact_statement missing {needle!r}")
    products = stmt.get("products")
    if not isinstance(products, list) or len(products) != 1:
        fail("OpenVEX must bind exactly one product")
    product = products[0]
    if product.get("@id") != spec["trivy_product_purl"]:
        fail("OpenVEX product PURL must match Trivy's FrankenPHP gobinary root")
    subs = product.get("subcomponents")
    if not isinstance(subs, list) or len(subs) != 1:
        fail("OpenVEX must bind exactly one subcomponent")
    if subs[0].get("@id") != spec["trivy_subcomponent_purl"]:
        fail("OpenVEX subcomponent PURL must be pkg:golang/golang.org/x/crypto@v0.54.0")
    if "@v0.54.0" not in spec["trivy_subcomponent_purl"]:
        fail("x/crypto PURL must be version-pinned")
    print("openvex-document: PASS")
    return spec


def assert_not_in_ignore_files(spec: dict[str, Any]) -> None:
    banned = {spec["cve"], spec["go_advisory"]}
    for path in (TRIVY_IMAGE_IGNORE, TRIVY_MERGE_IGNORE):
        found = banned.intersection(ignore_ids(path))
        if found:
            fail(f"{path.relative_to(REPO_ROOT)} must not list {sorted(found)}; use OpenVEX, not trivy ignore")
    print("openvex-not-in-ignore: PASS")


def assert_dockerfile_binding(spec: dict[str, Any]) -> None:
    text = DOCKERFILE.read_text(encoding="utf-8")
    expected_from = f"FROM {spec['base_image']}@{spec['base_digest']} AS base"
    if expected_from not in text:
        fail(f"Dockerfile FROM must be exactly {expected_from}")
    for needle in spec.get("dockerfile_entrypoint_needles") or []:
        if str(needle) not in text:
            fail(f"Dockerfile entrypoint missing {needle}")
    print("openvex-dockerfile: PASS")


def assert_workflow_wiring() -> None:
    pr = PR_WORKFLOW.read_text(encoding="utf-8")
    post = POST_MERGE_WORKFLOW.read_text(encoding="utf-8")
    for label, text in (("pull-request.yaml", pr), ("post-merge.yaml", post)):
        if VEX_REL not in text:
            fail(f"{label} must reference {VEX_REL}")
        if "--vex" not in text:
            fail(f"{label} core-api image scan must pass Trivy --vex")
        if "--show-suppressed" not in text:
            fail(f"{label} core-api image scan must pass --show-suppressed")
        if "--exit-code 1" not in text and "exit-code: '1'" not in text:
            fail(f"{label} must keep Trivy exit-code 1")
        if "verify_core_api_openvex.py" not in text:
            fail(f"{label} must run verify_core_api_openvex.py before trusting VEX")
        if "ignore-unfixed: 'false'" not in text and "--ignore-unfixed=false" not in text:
            fail(f"{label} must keep ignore-unfixed false")
    if VEX_REL in _job(pr, "security"):
        fail("filesystem scan must not consume the core-api OpenVEX document")
    if VEX_REL in _job(post, "promotion-fs-scan"):
        fail("promotion filesystem scan must not consume the core-api OpenVEX document")
    if "matrix.unit == 'core-api'" not in pr or "matrix.unit == 'core-api'" not in post:
        fail("OpenVEX must be limited to matrix.unit == 'core-api'")
    print("openvex-workflow-wiring: PASS")


def _job(yaml_text: str, job_id: str) -> str:
    lines = yaml_text.splitlines()
    capturing = False
    in_jobs = False
    out: list[str] = []
    for line in lines:
        if line.startswith("jobs:"):
            in_jobs = True
            continue
        if not in_jobs:
            continue
        if re.match(rf"^  {re.escape(job_id)}:", line):
            capturing = True
            out.append(line)
            continue
        if capturing and re.match(r"^  [A-Za-z0-9_][\w-]*:", line):
            break
        if capturing:
            out.append(line)
    return "\n".join(out)


def statement_matches(
    stmt: dict[str, Any],
    vuln_id: str,
    product_purl: str,
    subcomponent_purl: str | None,
) -> bool:
    vuln = stmt.get("vulnerability") or {}
    names = {str(vuln.get("name") or "")}
    names.update(str(item) for item in (vuln.get("aliases") or []))
    if vuln_id not in names:
        return False
    for product in stmt.get("products") or []:
        if product.get("@id") != product_purl:
            continue
        subs = [item.get("@id") for item in (product.get("subcomponents") or [])]
        if subs:
            return subcomponent_purl in subs
        return not subcomponent_purl
    return False


def assert_negative_narrowness(spec: dict[str, Any]) -> None:
    stmt = load_json(OPENVEX)["statements"][0]
    product = spec["trivy_product_purl"]
    sub = spec["trivy_subcomponent_purl"]
    if not statement_matches(stmt, spec["cve"], product, sub):
        fail("VEX must match CVE-2026-56854 on the FrankenPHP/x/crypto pair")
    if not statement_matches(stmt, spec["go_advisory"], product, sub):
        fail("VEX must also recognize GO-2026-6303 as an alias")
    if statement_matches(stmt, OTHER_XCRYPTO_CVE, product, sub):
        fail("VEX must not match a different golang.org/x/crypto CVE")
    if statement_matches(stmt, SYNTHETIC_CRITICAL, product, sub):
        fail("VEX must not match a synthetic unrelated CVE")
    if statement_matches(stmt, spec["cve"], "pkg:golang/google.golang.org/grpc@v1.81.1", None):
        fail("VEX must not match a different gobinary component")
    if statement_matches(stmt, spec["cve"], sub, None):
        fail("VEX must not blanket-match the x/crypto leaf without the FrankenPHP product")
    sub_id = ((stmt.get("products") or [{}])[0].get("subcomponents") or [{}])[0].get("@id") or ""
    if sub_id == "pkg:golang/golang.org/x/crypto" or "@" not in sub_id:
        fail("VEX must not omit the x/crypto version")
    print("openvex-negative-narrowness: PASS")


def docker_run(image: str, *, entrypoint: str, args: list[str]) -> str:
    cmd = [
        "docker",
        "run",
        "--rm",
        "--user",
        "root",
        "--entrypoint",
        entrypoint,
        image,
        *args,
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        fail(
            "docker inspect failed for "
            f"{entrypoint} {args}: rc={proc.returncode}\n{proc.stdout}\n{proc.stderr}"
        )
    return proc.stdout


def parse_version_line(output: str, spec: dict[str, Any]) -> None:
    if spec["frankenphp_version"] not in output:
        fail(f"FrankenPHP version mismatch: expected {spec['frankenphp_version']} in {output!r}")
    if spec["caddy_version"] not in output:
        fail(f"Caddy version mismatch: expected {spec['caddy_version']} in {output!r}")


def parse_xcrypto_version(build_info: str, spec: dict[str, Any]) -> None:
    found: str | None = None
    for line in build_info.splitlines():
        parts = line.split("\t")
        if len(parts) >= 3 and parts[0] == "dep" and parts[1] == spec["x_crypto_module"]:
            found = parts[2]
            break
    if found != spec["x_crypto_version"]:
        fail(
            f"{spec['x_crypto_module']} version mismatch: expected {spec['x_crypto_version']}, got {found}"
        )


def module_forbidden(name: str, tokens: list[str]) -> bool:
    lowered = name.lower()
    parts = lowered.split(".")
    for token in tokens:
        token_l = token.lower()
        if lowered == token_l or lowered.startswith(token_l + ".") or token_l in parts:
            return True
    return False


def parse_modules(output: str, spec: dict[str, Any]) -> None:
    forbidden = [str(item) for item in spec["forbidden_modules"]]
    hits: list[str] = []
    for raw in output.splitlines():
        name = raw.strip()
        if not name or name.startswith("Standard ") or name.startswith("Non-standard "):
            continue
        if module_forbidden(name, forbidden):
            hits.append(name)
    if hits:
        fail(
            "compiled modules changed; OpenVEX is invalid until re-review. "
            f"forbidden modules present: {hits}"
        )


def assert_image(image: str, spec: dict[str, Any]) -> None:
    binary = str(spec.get("frankenphp_binary_path") or "/usr/local/bin/frankenphp")
    digest_out = docker_run(image, entrypoint="sha256sum", args=[binary]).strip()
    actual = digest_out.split()[0]
    expected = str(spec["frankenphp_binary_sha256"])
    if actual != expected:
        fail(
            f"frankenphp SHA-256 mismatch: expected {expected}, got {actual}. "
            "OpenVEX is invalid until re-review."
        )
    parse_version_line(docker_run(image, entrypoint=binary, args=["version"]), spec)
    parse_xcrypto_version(docker_run(image, entrypoint=binary, args=["build-info"]), spec)
    parse_modules(docker_run(image, entrypoint=binary, args=["list-modules"]), spec)
    print(f"openvex-image-binding ({image}): PASS")


def iter_findings(report: dict[str, Any]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for result in report.get("Results") or []:
        for vuln in result.get("Vulnerabilities") or []:
            rows.append(vuln)
    return rows


def iter_modified(report: dict[str, Any]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for result in report.get("Results") or []:
        for key in ("ModifiedFindings", "ExperimentalModifiedFindings"):
            for item in result.get(key) or []:
                rows.append(item)
    return rows


def modified_vuln_id(item: dict[str, Any]) -> str:
    finding = item.get("Finding") or item.get("vulnerability") or {}
    if isinstance(finding, dict):
        return str(finding.get("VulnerabilityID") or finding.get("id") or "")
    return str(item.get("VulnerabilityID") or "")


def modified_pkg(item: dict[str, Any]) -> str:
    finding = item.get("Finding") or {}
    if isinstance(finding, dict):
        return str(finding.get("PkgName") or "")
    return ""


def modified_status(item: dict[str, Any]) -> str:
    return str(item.get("Status") or item.get("status") or "").lower()


def assert_narrow_report(path: Path, spec: dict[str, Any]) -> None:
    report = load_json(path)
    remaining = iter_findings(report)
    modified = iter_modified(report)
    remaining_ids = {str(item.get("VulnerabilityID") or "") for item in remaining}
    remaining_pkgs = {str(item.get("PkgName") or "") for item in remaining}
    modified_ids = {modified_vuln_id(item) for item in modified}
    if spec["cve"] in remaining_ids:
        fail("CVE-2026-56854 must be suppressed by OpenVEX in the Trivy report")
    if spec["cve"] not in modified_ids:
        fail("CVE-2026-56854 must appear in Trivy ModifiedFindings / suppressed evidence")
    statuses = {
        modified_status(item)
        for item in modified
        if modified_vuln_id(item) == spec["cve"]
    }
    if "not_affected" not in statuses:
        fail("suppressed CVE-2026-56854 must have status not_affected")
    statements = {
        str(item.get("Statement") or item.get("statement") or "")
        for item in modified
        if modified_vuln_id(item) == spec["cve"]
    }
    if spec["justification"] not in statements:
        fail("suppressed CVE-2026-56854 must cite justification vulnerable_code_not_present")
    sources = " ".join(
        str(item.get("Source") or "") for item in modified if modified_vuln_id(item) == spec["cve"]
    )
    if "openvex.json" not in sources:
        fail("suppressed CVE-2026-56854 must cite the OpenVEX document as source")
    if not remaining:
        fail("narrowness failed: OpenVEX appears to have suppressed every HIGH/CRITICAL finding")
    high_or_crit = [
        item
        for item in remaining
        if str(item.get("Severity") or "").upper() in {"HIGH", "CRITICAL"}
    ]
    if not high_or_crit:
        fail("narrowness failed: no remaining HIGH/CRITICAL finding after VEX-only scan")
    xcrypto_modified = [
        item
        for item in modified
        if modified_pkg(item) == spec["x_crypto_module"]
        and modified_vuln_id(item) not in {spec["cve"], spec["go_advisory"]}
    ]
    if xcrypto_modified:
        extra = sorted({modified_vuln_id(item) for item in xcrypto_modified})
        fail(f"OpenVEX must not suppress other golang.org/x/crypto CVEs: {extra}")
    if spec["x_crypto_module"] in remaining_pkgs:
        # A remaining x/crypto finding is allowed and proves no blanket suppress.
        pass
    print("openvex-trivy-narrowness: PASS")
    print(f"  suppressed={sorted(modified_ids)}")
    print(f"  remaining_high_critical={len(high_or_crit)}")


def default_checks() -> dict[str, Any]:
    spec = assert_openvex_document()
    assert_not_in_ignore_files(spec)
    assert_dockerfile_binding(spec)
    assert_workflow_wiring()
    assert_negative_narrowness(spec)
    return spec


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Verify core-api CVE-2026-56854 OpenVEX applicability")
    parser.add_argument("--image", help="Built core-api runtime image reference")
    parser.add_argument(
        "--assert-narrow-json",
        dest="narrow_json",
        help="Trivy JSON report produced with --vex and without trivy-image.ignore",
    )
    args = parser.parse_args(argv)
    try:
        spec = default_checks()
        if args.image:
            assert_image(args.image, spec)
        if args.narrow_json:
            assert_narrow_report(Path(args.narrow_json), spec)
    except GateError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
