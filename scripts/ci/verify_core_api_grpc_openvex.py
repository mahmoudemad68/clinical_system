#!/usr/bin/env python3
"""Fail-closed OpenVEX checks for FrankenPHP gRPC CVE-2026-84304 and CVE-2026-84445.

This is not risk acceptance. Each VEX is invalid the moment the bound FrankenPHP
binary, base digest, versions, compiled modules, Caddyfile, or entrypoint change.
The CVE-2026-56854 document is not modified by this script.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
import verify_core_api_openvex as corevex  # noqa: E402

DOCKERFILE = corevex.DOCKERFILE
PR_WORKFLOW = corevex.PR_WORKFLOW
POST_MERGE_WORKFLOW = corevex.POST_MERGE_WORKFLOW
TRIVY_IMAGE_IGNORE = corevex.TRIVY_IMAGE_IGNORE
TRIVY_MERGE_IGNORE = corevex.TRIVY_MERGE_IGNORE
OPENVEX_CONTEXT = corevex.OPENVEX_CONTEXT

VEX_84445 = "infra/security/vex/core-api-frankenphp-cve-2026-84445.openvex.json"
VEX_84304 = "infra/security/vex/core-api-frankenphp-cve-2026-84304.openvex.json"
APP_84445 = REPO_ROOT / "infra/security/vex/core-api-frankenphp-cve-2026-84445.applicability.json"
APP_84304 = REPO_ROOT / "infra/security/vex/core-api-frankenphp-cve-2026-84304.applicability.json"
DOC_84445 = REPO_ROOT / VEX_84445
DOC_84304 = REPO_ROOT / VEX_84304
VEX_56854 = corevex.VEX_REL

SHARED_REQUIRED = (
    "cve",
    "go_advisory",
    "ghsa",
    "status",
    "justification",
    "base_digest",
    "base_image",
    "frankenphp_binary_sha256",
    "frankenphp_version",
    "caddy_version",
    "grpc_version",
    "trivy_product_purl",
    "trivy_subcomponent_purl",
    "trivy_vulnerability_id",
    "not_risk_acceptance",
    "determination",
    "upstream_fix",
    "octane_config",
)


class GateError(Exception):
    pass


def fail(message: str) -> None:
    raise GateError(message)


def load_binding(path: Path, cve: str, go_id: str, ghsa: str, justification: str) -> dict[str, Any]:
    data = corevex.load_json(path)
    for key in SHARED_REQUIRED:
        if key not in data:
            fail(f"{path.name} missing {key}")
    if data["cve"] != cve:
        fail(f"{path.name} cve must be {cve}")
    if data["go_advisory"] != go_id:
        fail(f"{path.name} go_advisory must be {go_id}")
    if data["ghsa"] != ghsa:
        fail(f"{path.name} ghsa must be {ghsa}")
    if data["status"] != "not_affected":
        fail(f"{path.name} status must be not_affected")
    if data["justification"] != justification:
        fail(f"{path.name} justification must be {justification}")
    if data["determination"] != "NOT_AFFECTED_REACHABILITY_PROVEN":
        fail(f"{path.name} determination must be NOT_AFFECTED_REACHABILITY_PROVEN")
    if data["not_risk_acceptance"] is not True:
        fail(f"{path.name} must record not_risk_acceptance true")
    if data["trivy_subcomponent_purl"] != "pkg:golang/google.golang.org/grpc@v1.81.1":
        fail(f"{path.name} must bind grpc@v1.81.1 only")
    if "x/crypto" in str(data["trivy_subcomponent_purl"]):
        fail(f"{path.name} must not bind x/crypto")
    return data


def assert_openvex(path: Path, spec: dict[str, Any], extra_needles: tuple[str, ...]) -> None:
    doc = corevex.load_json(path)
    if doc.get("@context") != OPENVEX_CONTEXT:
        fail(f"{path.name} @context must be OpenVEX 0.2")
    statements = doc.get("statements")
    if not isinstance(statements, list) or len(statements) != 1:
        fail(f"{path.name} must contain exactly one statement")
    stmt = statements[0]
    vuln = stmt.get("vulnerability")
    if not isinstance(vuln, dict):
        fail(f"{path.name} missing vulnerability")
    names = {str(vuln.get("name") or "")}
    aliases = vuln.get("aliases") or []
    if not isinstance(aliases, list):
        fail(f"{path.name} aliases must be a list")
    names.update(str(item) for item in aliases)
    expected = {spec["cve"], spec["go_advisory"], spec["ghsa"]}
    if names != expected:
        fail(f"{path.name} identifiers must be exactly {sorted(expected)}, got {sorted(names)}")
    if stmt.get("status") != "not_affected":
        fail(f"{path.name} status must be not_affected")
    if stmt.get("justification") != spec["justification"]:
        fail(f"{path.name} justification must be {spec['justification']}")
    impact = str(stmt.get("impact_statement") or "")
    needles = (
        "FrankenPHP v1.12.7",
        "Caddy v2.11.4",
        "google.golang.org/grpc v1.81.1",
        "not claim",
        "not risk acceptance",
        "port 8080",
        spec["frankenphp_binary_sha256"],
        spec["base_digest"],
        spec["cve"],
        spec["ghsa"],
        *extra_needles,
    )
    for needle in needles:
        if needle not in impact:
            fail(f"{path.name} impact_statement missing {needle!r}")
    products = stmt.get("products")
    if not isinstance(products, list) or len(products) != 1:
        fail(f"{path.name} must bind exactly one product")
    product = products[0]
    if product.get("@id") != spec["trivy_product_purl"]:
        fail(f"{path.name} product PURL mismatch")
    subs = product.get("subcomponents")
    if not isinstance(subs, list) or len(subs) != 1:
        fail(f"{path.name} must bind exactly one subcomponent")
    if subs[0].get("@id") != spec["trivy_subcomponent_purl"]:
        fail(f"{path.name} subcomponent PURL must be pkg:golang/google.golang.org/grpc@v1.81.1")
    print(f"openvex-document ({spec['cve']}): PASS")


def assert_not_in_ignore(spec: dict[str, Any]) -> None:
    banned = {spec["cve"], spec["go_advisory"], spec["ghsa"]}
    for path in (TRIVY_IMAGE_IGNORE, TRIVY_MERGE_IGNORE):
        found = banned.intersection(corevex.ignore_ids(path))
        if found:
            fail(f"{path.relative_to(REPO_ROOT)} must not list {sorted(found)}; use OpenVEX, not trivy ignore")
    print(f"openvex-not-in-ignore ({spec['cve']}): PASS")


def assert_bindings_agree(a: dict[str, Any], b: dict[str, Any]) -> None:
    for key in (
        "base_digest",
        "base_image",
        "frankenphp_binary_sha256",
        "frankenphp_version",
        "caddy_version",
        "grpc_version",
        "trivy_product_purl",
        "trivy_subcomponent_purl",
    ):
        if a[key] != b[key]:
            fail(f"grpc VEX bindings disagree on {key}")
    crypto = corevex.load_json(corevex.APPLICABILITY)
    if a["base_digest"] != crypto["base_digest"]:
        fail("grpc VEX base digest must match the CVE-2026-56854 binding")
    if a["frankenphp_binary_sha256"] != crypto["frankenphp_binary_sha256"]:
        fail("grpc VEX binary SHA must match the CVE-2026-56854 binding")
    print("openvex-grpc-bindings-agree: PASS")


def assert_dockerfile(spec: dict[str, Any]) -> None:
    text = DOCKERFILE.read_text(encoding="utf-8")
    expected_from = f"FROM {spec['base_image']}@{spec['base_digest']} AS base"
    if expected_from not in text:
        fail(f"Dockerfile FROM must be exactly {expected_from}")
    for needle in spec.get("dockerfile_entrypoint_needles") or []:
        if str(needle) not in text:
            fail(f"Dockerfile entrypoint missing {needle}")
    for needle in spec.get("forbidden_dockerfile_needles") or []:
        if str(needle) in text:
            fail(f"Dockerfile must not contain {needle}")
    print("openvex-grpc-dockerfile: PASS")


def assert_octane_config(spec: dict[str, Any]) -> None:
    rel = str(spec.get("octane_config") or "")
    path = REPO_ROOT / rel
    if not path.is_file():
        fail(f"octane config missing: {rel}")
    text = path.read_text(encoding="utf-8")
    lowered = text.lower()
    for needle in spec.get("forbidden_octane_needles") or []:
        if str(needle).lower() in lowered:
            fail(f"{rel} must not contain {needle}; grpc OpenVEX assumes Mercure/OTLP/Caddy extras stay off")
    print("openvex-grpc-octane-config: PASS")


def assert_workflow_wiring() -> None:
    pr = PR_WORKFLOW.read_text(encoding="utf-8")
    post = POST_MERGE_WORKFLOW.read_text(encoding="utf-8")
    for label, text in (("pull-request.yaml", pr), ("post-merge.yaml", post)):
        for rel in (VEX_84445, VEX_84304, VEX_56854):
            if rel not in text:
                fail(f"{label} must reference {rel}")
        if text.count("--vex") < 3:
            fail(f"{label} core-api image scan must pass all three OpenVEX documents")
        if "verify_core_api_grpc_openvex.py" not in text:
            fail(f"{label} must run verify_core_api_grpc_openvex.py")
        if "exit-code: '1'" not in text and "--exit-code 1" not in text:
            fail(f"{label} must keep Trivy exit-code 1")
        if "ignore-unfixed: 'false'" not in text and "--ignore-unfixed=false" not in text:
            fail(f"{label} must keep ignore-unfixed false")
    if VEX_84445 in corevex._job(pr, "security") or VEX_84304 in corevex._job(pr, "security"):
        fail("filesystem scan must not consume the grpc OpenVEX documents")
    print("openvex-grpc-workflow-wiring: PASS")


def assert_negative_narrowness(spec: dict[str, Any], path: Path) -> None:
    stmt = corevex.load_json(path)["statements"][0]
    product = spec["trivy_product_purl"]
    sub = spec["trivy_subcomponent_purl"]
    if not corevex.statement_matches(stmt, spec["cve"], product, sub):
        fail(f"{path.name} must match {spec['cve']} on the FrankenPHP/grpc pair")
    if corevex.statement_matches(stmt, spec["cve"], "pkg:golang/golang.org/x/crypto@v0.54.0", None):
        fail(f"{path.name} must not match x/crypto")
    if corevex.statement_matches(stmt, "CVE-2026-56854", product, sub):
        fail(f"{path.name} must not claim CVE-2026-56854")
    if corevex.statement_matches(stmt, spec["cve"], sub, None):
        fail(f"{path.name} must not blanket-match the grpc leaf without the FrankenPHP product")
    crypto_stmt = corevex.load_json(corevex.OPENVEX)["statements"][0]
    if corevex.statement_matches(crypto_stmt, spec["cve"], product, sub):
        fail("CVE-2026-56854 OpenVEX must not match grpc CVEs")
    print(f"openvex-grpc-negative-narrowness ({spec['cve']}): PASS")


def parse_grpc_version(build_info: str, spec: dict[str, Any]) -> None:
    found: str | None = None
    for line in build_info.splitlines():
        parts = line.split("\t")
        if len(parts) >= 3 and parts[0] == "dep" and parts[1] == spec["grpc_module"]:
            found = parts[2]
            break
    if found != spec["grpc_version"]:
        fail(f"{spec['grpc_module']} version mismatch: expected {spec['grpc_version']}, got {found}")
    if "github.com/dunglas/frankenphp-grpc" in build_info:
        fail("binary must not depend on github.com/dunglas/frankenphp-grpc")


def parse_caddy_modules(output: str, spec: dict[str, Any]) -> None:
    forbidden_tokens = [str(item) for item in spec.get("forbidden_caddy_module_tokens") or []]
    forbidden_ids = {str(item).lower() for item in spec.get("forbidden_caddy_module_ids") or []}
    hits: list[str] = []
    for raw in output.splitlines():
        name = raw.strip()
        if not name or name.startswith("Standard ") or name.startswith("Non-standard "):
            continue
        lowered = name.lower()
        if lowered in forbidden_ids:
            hits.append(name)
            continue
        if corevex.module_forbidden(name, forbidden_tokens):
            hits.append(name)
    if hits:
        fail(f"compiled Caddy modules changed; grpc OpenVEX is invalid: {hits}")


def copy_binary(image: str, dest: Path) -> None:
    proc = subprocess.run(["docker", "create", image], capture_output=True, text=True)
    if proc.returncode != 0:
        fail(f"docker create failed: {proc.stderr}")
    cid = proc.stdout.strip()
    try:
        copied = subprocess.run(
            ["docker", "cp", f"{cid}:/usr/local/bin/frankenphp", str(dest)],
            capture_output=True,
            text=True,
        )
        if copied.returncode != 0:
            fail(f"docker cp frankenphp failed: {copied.stderr}")
    finally:
        subprocess.run(["docker", "rm", cid], capture_output=True, text=True)


def assert_binary_needles(image: str, *specs: dict[str, Any]) -> None:
    dest = Path("/tmp/core-api-frankenphp-openvex-binary")
    copy_binary(image, dest)
    data = dest.read_bytes()
    seen: set[str] = set()
    for spec in specs:
        for needle in spec.get("forbidden_binary_needles") or []:
            token = str(needle)
            if token in seen:
                continue
            seen.add(token)
            if token.encode("utf-8") in data:
                fail(f"binary contains forbidden xDS/grpc-server needle {token!r}")
    print("openvex-grpc-binary-needles: PASS")


def assert_caddyfile(image: str, spec: dict[str, Any]) -> None:
    path = str(spec.get("caddyfile_in_image") or "")
    if not path:
        return
    text = corevex.docker_run(image, entrypoint="cat", args=[path])
    for needle in spec.get("required_caddyfile_needles") or []:
        if str(needle) not in text:
            fail(f"image Caddyfile missing {needle}")
    lowered = text.lower()
    for needle in spec.get("forbidden_caddyfile_needles") or []:
        if str(needle).lower() in lowered:
            fail(f"image Caddyfile must not contain {needle}")
    print("openvex-grpc-caddyfile: PASS")


def assert_image(image: str, spec: dict[str, Any], spec_84445: dict[str, Any]) -> None:
    binary = str(spec.get("frankenphp_binary_path") or "/usr/local/bin/frankenphp")
    digest_out = corevex.docker_run(image, entrypoint="sha256sum", args=[binary]).strip()
    actual = digest_out.split()[0]
    expected = str(spec["frankenphp_binary_sha256"])
    if actual != expected:
        fail(
            f"frankenphp SHA-256 mismatch: expected {expected}, got {actual}. "
            "grpc OpenVEX is invalid until re-review."
        )
    corevex.parse_version_line(corevex.docker_run(image, entrypoint=binary, args=["version"]), spec)
    parse_grpc_version(corevex.docker_run(image, entrypoint=binary, args=["build-info"]), spec)
    parse_caddy_modules(corevex.docker_run(image, entrypoint=binary, args=["list-modules"]), spec)
    assert_binary_needles(image, spec, spec_84445)
    assert_caddyfile(image, spec)
    print(f"openvex-grpc-image-binding ({image}): PASS")


def assert_narrow_report(path: Path, specs: list[dict[str, Any]]) -> None:
    report = corevex.load_json(path)
    remaining = corevex.iter_findings(report)
    modified = corevex.iter_modified(report)
    remaining_ids = {str(item.get("VulnerabilityID") or "") for item in remaining}
    modified_ids = {corevex.modified_vuln_id(item) for item in modified}
    for spec in specs:
        cve = spec["cve"]
        if cve in remaining_ids:
            fail(f"{cve} must be suppressed by OpenVEX in the Trivy report")
        if cve not in modified_ids:
            fail(f"{cve} must appear in Trivy ModifiedFindings / suppressed evidence")
        statuses = {
            corevex.modified_status(item)
            for item in modified
            if corevex.modified_vuln_id(item) == cve
        }
        if "not_affected" not in statuses:
            fail(f"suppressed {cve} must have status not_affected")
        statements = {
            str(item.get("Statement") or item.get("statement") or "")
            for item in modified
            if corevex.modified_vuln_id(item) == cve
        }
        if spec["justification"] not in statements:
            fail(f"suppressed {cve} must cite justification {spec['justification']}")
    if not remaining:
        fail("narrowness failed: OpenVEX appears to have suppressed every HIGH/CRITICAL finding")
    high_or_crit = [
        item
        for item in remaining
        if str(item.get("Severity") or "").upper() in {"HIGH", "CRITICAL"}
    ]
    if not high_or_crit:
        fail("narrowness failed: no remaining HIGH/CRITICAL finding after VEX-only scan")
    extra_grpc = [
        item
        for item in modified
        if str(item.get("Finding", {}).get("PkgName") or item.get("PkgName") or "")
        == "google.golang.org/grpc"
        and corevex.modified_vuln_id(item)
        not in {spec["cve"] for spec in specs} | {spec["go_advisory"] for spec in specs} | {spec["ghsa"] for spec in specs}
    ]
    if extra_grpc:
        extra = sorted({corevex.modified_vuln_id(item) for item in extra_grpc})
        fail(f"grpc OpenVEX must not suppress other google.golang.org/grpc IDs: {extra}")
    print("openvex-grpc-trivy-narrowness: PASS")
    print(f"  suppressed={sorted(modified_ids)}")
    print(f"  remaining_high_critical={len(high_or_crit)}")


def default_checks() -> tuple[dict[str, Any], dict[str, Any]]:
    spec_84445 = load_binding(
        APP_84445,
        "CVE-2026-84445",
        "GO-2026-6443",
        "GHSA-2v4p-qf9q-27wj",
        "vulnerable_code_not_present",
    )
    spec_84304 = load_binding(
        APP_84304,
        "CVE-2026-84304",
        "GO-2026-6348",
        "GHSA-vp52-pcj8-j9qc",
        "vulnerable_code_not_in_execute_path",
    )
    assert_bindings_agree(spec_84445, spec_84304)
    assert_openvex(
        DOC_84445,
        spec_84445,
        ("xds.NewGRPCServer", "RouteAndProcess", "vulnerable_code_not_present"),
    )
    assert_openvex(
        DOC_84304,
        spec_84304,
        ("recvBuffer", "php_server", "vulnerable_code_not_in_execute_path"),
    )
    assert_not_in_ignore(spec_84445)
    assert_not_in_ignore(spec_84304)
    assert_dockerfile(spec_84304)
    assert_octane_config(spec_84304)
    assert_octane_config(spec_84445)
    assert_workflow_wiring()
    assert_negative_narrowness(spec_84445, DOC_84445)
    assert_negative_narrowness(spec_84304, DOC_84304)
    return spec_84445, spec_84304


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Verify core-api gRPC OpenVEX applicability")
    parser.add_argument("--image", help="Built core-api runtime image reference")
    parser.add_argument(
        "--assert-narrow-json",
        dest="narrow_json",
        help="Trivy JSON report produced with grpc OpenVEX and without trivy-image.ignore",
    )
    args = parser.parse_args(argv)
    try:
        spec_84445, spec_84304 = default_checks()
        if args.image:
            assert_image(args.image, spec_84304, spec_84445)
        if args.narrow_json:
            assert_narrow_report(Path(args.narrow_json), [spec_84445, spec_84304])
    except GateError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    except corevex.GateError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
