#!/usr/bin/env python3
"""ISR-015 repository policy gates. Fail closed. Not legal approval."""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[2]
PATH_FILTERS = REPO_ROOT / ".github" / "path-filters.yaml"
PR_WORKFLOW = REPO_ROOT / ".github" / "workflows" / "pull-request.yaml"
POST_MERGE_WORKFLOW = REPO_ROOT / ".github" / "workflows" / "post-merge.yaml"
CODEOWNERS = REPO_ROOT / ".github" / "CODEOWNERS"
PINS = REPO_ROOT / "infra" / "security" / "ci-executable-pins.json"
LICENSE_BASELINE = REPO_ROOT / "infra" / "security" / "engineering-license-baseline.json"
SF001 = REPO_ROOT / "infra" / "security" / "exceptions" / "SF-001.json"
TRIVY_MERGE_IGNORE = REPO_ROOT / "infra" / "security" / "trivy-merge.ignore"

ROOT_NODE_PATHS = ("package.json", "package-lock.json")
CLIENT_FILTERS = ("admin_web", "desktop", "flutter")
UNRELATED_FILTERS = ("ai_service", "core_api")
SHARED_TS_CONFIG = "packages/typescript/tsconfig.base.json"

REQUIRED_CODEOWNERS = (
    "infra/security/trivy-merge.ignore",
    "infra/security/exceptions/SF-001.json",
    "infra/security/engineering-license-baseline.json",
    "infra/security/ci-executable-pins.json",
    "scripts/ci/isr015_policy.py",
    "scripts/ci/run-isr015-validators.py",
    "scripts/ci/install-trivy.sh",
    "scripts/ci/verify-signed-images.sh",
    "scripts/ci/verify_core_api_openvex.py",
    "scripts/ci/run_gitleaks_national_id_allowlist.py",
    "infra/security/vex/core-api-frankenphp-cve-2026-56854.openvex.json",
    "infra/security/vex/core-api-frankenphp-cve-2026-56854.applicability.json",
    ".gitleaks.toml",
    ".semgrep.yml",
    ".github/workflows/pull-request.yaml",
    ".github/workflows/post-merge.yaml",
    ".github/path-filters.yaml",
    "package.json",
    "package-lock.json",
    "apps/core-api/composer.lock",
    "apps/core-api/package-lock.json",
    "tests/e2e/package-lock.json",
    "tests/desktop-e2e/package-lock.json",
)

WORKFLOW_LEVEL_FORBIDDEN_PERMS = ("packages: write", "id-token: write", "attestations: write")
READ_ONLY_POST_MERGE_JOBS = (
    "promotion-fs-scan",
    "deploy-staging",
    "promote-production",
    "verify-artifacts",
)


class GateError(Exception):
    pass


def fail(message: str) -> None:
    raise GateError(message)


def parse_simple_yaml_mapping(text: str) -> dict[str, list[str]]:
    mapping: dict[str, list[str]] = {}
    current: str | None = None
    for raw in text.splitlines():
        line = raw.split("#", 1)[0].rstrip()
        if not line.strip():
            continue
        if re.match(r"^[A-Za-z0-9_][\w-]*:\s*$", line):
            current = line[:-1]
            mapping[current] = []
            continue
        if current is None:
            fail(f"unexpected path-filter line: {raw!r}")
        item = line.strip()
        if not item.startswith("- "):
            fail(f"unexpected path-filter line: {raw!r}")
        mapping[current].append(item[2:].strip().strip("'\""))
    return mapping


def glob_match(pattern: str, path: str) -> bool:
    if pattern.endswith("/**"):
        prefix = pattern[:-3]
        return path == prefix or path.startswith(prefix + "/")
    if "*" in pattern:
        regex = "^" + re.escape(pattern).replace(r"\*\*", ".*").replace(r"\*", "[^/]*") + "$"
        return re.fullmatch(regex, path) is not None
    return pattern == path


def flags_for(changed: str, filters: dict[str, list[str]]) -> dict[str, bool]:
    return {
        name: any(glob_match(pattern, changed) for pattern in paths)
        for name, paths in filters.items()
    }


def extract_job(yaml_text: str, job_id: str) -> str:
    lines = yaml_text.splitlines()
    in_jobs = False
    capturing = False
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
    if not out:
        fail(f"job {job_id!r} not found")
    return "\n".join(out)


def workflow_level_permissions(yaml_text: str) -> str:
    lines = yaml_text.splitlines()
    out: list[str] = []
    capturing = False
    for line in lines:
        if line.startswith("permissions:"):
            capturing = True
            out.append(line)
            continue
        if capturing:
            if line[:1] not in (" ", "\t") and line.strip():
                break
            out.append(line)
    return "\n".join(out)


def job_needs(job_text: str) -> str:
    lines = job_text.splitlines()
    for index, line in enumerate(lines):
        if not re.match(r"^\s+needs:", line):
            continue
        inline = line.split("needs:", 1)[1].strip()
        if inline:
            return inline
        collected: list[str] = []
        for nxt in lines[index + 1 :]:
            if re.match(r"^\s+- ", nxt):
                collected.append(nxt.strip())
            elif not nxt.strip():
                continue
            else:
                break
        return " ".join(collected)
    return ""


def load_json(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        fail(f"malformed JSON {path}: {exc}")
    except OSError as exc:
        fail(f"unreadable JSON {path}: {exc}")


def assert_path_filters() -> None:
    filters = parse_simple_yaml_mapping(PATH_FILTERS.read_text(encoding="utf-8"))
    pr = PR_WORKFLOW.read_text(encoding="utf-8")
    if "filters: .github/path-filters.yaml" not in pr:
        fail("pull-request.yaml must load .github/path-filters.yaml")
    packaged = extract_job(pr, "desktop-packaged-e2e")
    if "needs.changes.outputs.desktop" not in packaged:
        fail("desktop-packaged-e2e must follow the desktop path filter")
    for name in CLIENT_FILTERS:
        paths = filters.get(name) or []
        for required in ROOT_NODE_PATHS:
            if required not in paths:
                fail(f"filter {name} missing {required}")
    ts_clients = ("admin_web", "desktop")
    for name in ts_clients:
        paths = filters.get(name) or []
        covered = SHARED_TS_CONFIG in paths or any(
            glob_match(pattern, SHARED_TS_CONFIG) for pattern in paths
        )
        if not covered:
            fail(f"filter {name} must include shared TypeScript config {SHARED_TS_CONFIG}")
    for name in UNRELATED_FILTERS:
        paths = filters.get(name) or []
        for forbidden in ROOT_NODE_PATHS:
            if forbidden in paths:
                fail(f"filter {name} must not include {forbidden}")
    for changed in ROOT_NODE_PATHS:
        flags = flags_for(changed, filters)
        for name in CLIENT_FILTERS:
            if not flags.get(name):
                fail(f"{changed} must select {name}")
        for name in UNRELATED_FILTERS:
            if flags.get(name):
                fail(f"{changed} must not select {name}")
        rendered = " ".join(f"{name}={str(flags.get(name, False)).lower()}" for name in (*CLIENT_FILTERS, *UNRELATED_FILTERS))
        print(f"path-filters: {changed} => {rendered}")
    print("path-filters: PASS")


def assert_immutable_refs() -> None:
    pins = load_json(PINS)
    expected_refs = {item["ref"] for item in pins["images"]}
    workflows = [PR_WORKFLOW, POST_MERGE_WORKFLOW]
    blob = "\n".join(path.read_text(encoding="utf-8") for path in workflows)
    for ref in expected_refs:
        if ref not in blob:
            fail(f"pinned image {ref} is not referenced by a workflow")
    if "aquasecurity/setup-trivy" in blob:
        fail("workflows must not use setup-trivy; install Trivy with a checksum")
    if "semgrep-agent:v1" in blob:
        fail("mutable semgrep-agent:v1 reference remains")
    if "returntocorp/semgrep-action" in blob:
        fail("semgrep-action remains; it pulls a mutable scanner image")
    if "tufin/oasdiff:v1.9.8@sha256:" not in blob:
        fail("tufin/oasdiff is not digest-pinned")
    if re.search(r"tufin/oasdiff(?!:v1\.9\.8@sha256:)", blob):
        fail("mutable tufin/oasdiff reference remains")
    for path in workflows:
        text = path.read_text(encoding="utf-8")
        for match in re.finditer(r"^\s+image:\s+(\S+)", text, re.M):
            ref = match.group(1).strip("\"'")
            if ref.startswith("${{") or ref.startswith("clinic-pr/"):
                continue
            if "@sha256:" not in ref:
                fail(f"mutable service image in {path.name}: {ref}")
            if ref not in expected_refs and "ghcr.io" not in ref:
                fail(f"workflow image ref missing from pin catalog: {ref}")
    installer = (REPO_ROOT / "scripts" / "ci" / "install-trivy.sh").read_text(encoding="utf-8")
    if "ci-executable-pins.json" not in installer:
        fail("install-trivy.sh must use the pinned Trivy checksum catalog")
    if "sha256sum" not in installer or " -c" not in installer:
        fail("install-trivy.sh must fail closed on checksum mismatch")
    print("immutable-refs: PASS")


def assert_workflow_permissions(workflow_path: Path | None = None) -> None:
    path = workflow_path or POST_MERGE_WORKFLOW
    text = path.read_text(encoding="utf-8")
    top = workflow_level_permissions(text)
    for perm in WORKFLOW_LEVEL_FORBIDDEN_PERMS:
        if perm in top:
            fail(f"{path.name} workflow-level permissions include {perm}")
    if "contents: read" not in top:
        fail(f"{path.name} workflow-level permissions must default to contents: read")
    build = extract_job(text, "build")
    for perm in WORKFLOW_LEVEL_FORBIDDEN_PERMS:
        if perm not in build:
            fail(f"build job must request {perm}")
    for job_id in READ_ONLY_POST_MERGE_JOBS:
        body = extract_job(text, job_id)
        for perm in WORKFLOW_LEVEL_FORBIDDEN_PERMS:
            if perm in body:
                fail(f"{job_id} must not request {perm}")
    print("workflow-permissions: PASS")


def normalize_license(value: Any) -> list[str]:
    if value is None or value == "":
        return ["UNKNOWN"]
    if isinstance(value, list):
        out: list[str] = []
        for item in value:
            out.extend(normalize_license(item))
        return out
    text = str(value).strip()
    if text == "Unlicense":
        return ["Unlicense"]
    if text.upper() == "UNLICENSED":
        return ["UNLICENSED"]
    if text.upper() in {"UNKNOWN", "NONE", "NOASSERTION"}:
        return ["UNKNOWN"]
    return [text]


def is_first_party(name: str, loc: str, baseline: dict[str, Any]) -> bool:
    if loc in {"", "."}:
        return True
    prefixes = tuple(baseline.get("first_party_name_prefixes") or [])
    names = set(baseline.get("first_party_package_names") or [])
    if name in names:
        return True
    if any(name.startswith(prefix) for prefix in prefixes):
        return True
    return loc.startswith("apps/") or loc.startswith("packages/")


def iter_npm_packages(lock_path: Path) -> list[tuple[str, str, str, str]]:
    data = load_json(lock_path)
    rows: list[tuple[str, str, str, str]] = []
    packages = data.get("packages") or {}
    for loc, meta in packages.items():
        if not isinstance(meta, dict):
            continue
        name = str(meta.get("name") or "")
        if not name:
            if loc in {"", "."}:
                name = str(data.get("name") or lock_path.parent.name)
            elif loc.startswith("node_modules/"):
                name = loc[len("node_modules/") :]
            else:
                name = loc
        version = str(meta.get("version") or "")
        for lic in normalize_license(meta.get("license")):
            rows.append((name, version, loc, lic))
    return rows


def iter_composer_packages(lock_path: Path) -> list[tuple[str, str, str, str]]:
    data = load_json(lock_path)
    rows: list[tuple[str, str, str, str]] = []
    for section in ("packages", "packages-dev"):
        for meta in data.get(section) or []:
            name = str(meta.get("name") or "")
            version = str(meta.get("version") or "")
            for lic in normalize_license(meta.get("license")):
                rows.append((name, version, section, lic))
    return rows


def license_inventory(lock_paths: list[Path], baseline: dict[str, Any]) -> None:
    allowed = set(baseline.get("allowed_license_ids") or [])
    if not allowed:
        fail("license baseline has no allowed_license_ids")
    if baseline.get("policy_id") != "ENGINEERING_LICENSE_BASELINE":
        fail("license baseline policy_id must be ENGINEERING_LICENSE_BASELINE")
    if baseline.get("legal_status") != "NOT_LEGAL_APPROVAL":
        fail("license baseline legal_status must be NOT_LEGAL_APPROVAL")
    recorded_unknown = baseline.get("recorded_unknown_packages") or {}
    allow_first_unlicensed = bool(baseline.get("allow_first_party_unlicensed"))
    seen_unknown: set[str] = set()
    for lock_path in lock_paths:
        rows = (
            iter_composer_packages(lock_path)
            if lock_path.name == "composer.lock"
            else iter_npm_packages(lock_path)
        )
        for name, version, loc, lic in rows:
            first_party = is_first_party(name, loc, baseline)
            key = f"{name}@{version}" if version else name
            if lic == "UNLICENSED":
                if first_party and allow_first_unlicensed:
                    continue
                fail(f"UNLICENSED third-party package {key} in {lock_path}")
            if lic == "UNKNOWN":
                if first_party:
                    continue
                if key in recorded_unknown:
                    seen_unknown.add(key)
                    continue
                fail(f"UNKNOWN license for {key} in {lock_path}")
            if lic not in allowed:
                fail(f"unreviewed license {lic!r} for {key} in {lock_path}")
    missing_recorded = set(recorded_unknown) - seen_unknown
    if missing_recorded:
        fail(f"recorded unknown packages missing from lockfiles: {sorted(missing_recorded)}")


def default_lock_paths() -> list[Path]:
    return [
        REPO_ROOT / "package-lock.json",
        REPO_ROOT / "apps" / "core-api" / "package-lock.json",
        REPO_ROOT / "apps" / "core-api" / "composer.lock",
        REPO_ROOT / "tests" / "e2e" / "package-lock.json",
        REPO_ROOT / "tests" / "desktop-e2e" / "package-lock.json",
    ]


def assert_license_gate(
    lock_paths: list[Path] | None = None,
    baseline_path: Path | None = None,
) -> None:
    baseline = load_json(baseline_path or LICENSE_BASELINE)
    license_inventory(lock_paths or default_lock_paths(), baseline)
    print("license-gate: PASS")


def parse_utc_expiry(value: Any) -> dt.datetime:
    if not isinstance(value, str) or not value:
        fail("missing expiry")
    if not value.endswith("Z"):
        fail("expiry must be a strict UTC timestamp ending with Z")
    try:
        parsed = dt.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError as exc:
        fail(f"malformed expiry: {exc}")
    return parsed.replace(tzinfo=dt.timezone.utc)


def trivy_ignore_ids(text: str) -> list[str]:
    ids: list[str] = []
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        ids.append(line)
    return ids


def extract_zip_version(lock_path: Path, package_key: str) -> str | None:
    data = load_json(lock_path)
    meta = (data.get("packages") or {}).get(package_key)
    if not isinstance(meta, dict):
        return None
    version = meta.get("version")
    return str(version) if version else None


def assert_sf001(
    *,
    now: dt.datetime | None = None,
    ignore_text: str | None = None,
    manifest: dict[str, Any] | None = None,
) -> None:
    manifest = manifest if manifest is not None else load_json(SF001)
    ignore_text = TRIVY_MERGE_IGNORE.read_text(encoding="utf-8") if ignore_text is None else ignore_text
    required = [
        "exception_id",
        "package",
        "affected_version",
        "vulnerability_ids",
        "scope",
        "promotion_allowed",
        "expires_at",
        "owner",
        "justification",
        "compensating_controls",
        "independent_acceptance_status",
    ]
    for key in required:
        if key not in manifest:
            fail(f"SF-001 manifest missing {key}")
    if manifest["exception_id"] != "SF-001":
        fail("exception_id must be SF-001")
    if manifest["package"] != "extract-zip":
        fail("SF-001 package must be extract-zip")
    if manifest["independent_acceptance_status"] in {"APPROVED", "PASS", "ACCEPTED"}:
        fail("independent_acceptance_status must not mark SF-001 accepted")
    if manifest["independent_acceptance_status"] != "PENDING_INDEPENDENT_ACCEPTANCE":
        fail("independent_acceptance_status must be PENDING_INDEPENDENT_ACCEPTANCE")
    if manifest["scope"] != "MERGE_ONLY":
        fail("SF-001 scope must be MERGE_ONLY")
    if manifest["promotion_allowed"] is not False:
        fail("SF-001 promotion_allowed must be false")
    vuln_ids = manifest["vulnerability_ids"]
    if not isinstance(vuln_ids, list) or not vuln_ids:
        fail("vulnerability_ids must be a non-empty list")
    if len(vuln_ids) != len(set(vuln_ids)):
        fail("duplicate vulnerability ids in the manifest")
    ignore_ids = trivy_ignore_ids(ignore_text)
    if len(ignore_ids) != len(set(ignore_ids)):
        fail("duplicate ids in trivy-merge.ignore")
    if set(ignore_ids) != set(vuln_ids):
        extra = sorted(set(ignore_ids) - set(vuln_ids))
        missing = sorted(set(vuln_ids) - set(ignore_ids))
        fail(f"trivy-merge.ignore IDs must equal manifest IDs extra={extra} missing={missing}")
    expiry = parse_utc_expiry(manifest["expires_at"])
    current = now or dt.datetime.now(dt.timezone.utc)
    if current >= expiry:
        fail("SF-001 exception expired")
    lock_path = REPO_ROOT / str(manifest.get("lockfile") or "package-lock.json")
    package_key = str(manifest.get("package_lock_key") or "node_modules/extract-zip")
    version = extract_zip_version(lock_path, package_key)
    expected = str(manifest["affected_version"])
    if version is None:
        fail("SF-001 is stale: extract-zip is missing from the lockfile")
    if version != expected:
        fail(f"SF-001 package/version mismatch: extract-zip is {version}, manifest expects {expected}")
    print("sf001-exception: PASS")


def assert_promotion_isolation(workflow_path: Path | None = None) -> None:
    path = workflow_path or POST_MERGE_WORKFLOW
    text = path.read_text(encoding="utf-8")
    job = extract_job(text, "promotion-fs-scan")
    if "trivy-merge.ignore" in job:
        fail("promotion-fs-scan must not reference trivy-merge.ignore")
    if "SF-001" in job:
        fail("promotion-fs-scan must not use SF-001")
    if "continue-on-error: true" in job:
        fail("promotion-fs-scan must not continue-on-error")
    if "exit-code: '1'" not in job and 'exit-code: "1"' not in job:
        fail("promotion-fs-scan must keep exit-code 1")
    if path.resolve() == POST_MERGE_WORKFLOW.resolve():
        pr = PR_WORKFLOW.read_text(encoding="utf-8")
        security = extract_job(pr, "security")
        if "trivy-merge.ignore" not in security:
            fail("merge filesystem scan must still use trivy-merge.ignore")
        if "continue-on-error: true" in security and "trivyignores: infra/security/trivy-merge.ignore" in security:
            fail("merge filesystem High/Critical scan must not continue-on-error")
    print("promotion-isolation: PASS")


def assert_provenance_wiring(workflow_path: Path | None = None) -> None:
    path = workflow_path or POST_MERGE_WORKFLOW
    text = path.read_text(encoding="utf-8")
    deploy = extract_job(text, "deploy-staging")
    verify = extract_job(text, "verify-artifacts")
    needs = job_needs(deploy)
    if "verify-artifacts" not in needs:
        fail("deploy-staging must need verify-artifacts")
    if "promotion-fs-scan" not in needs and "promotion-fs-scan" not in deploy:
        fail("deploy-staging must still need promotion-fs-scan")
    if "continue-on-error: true" in verify:
        fail("verify-artifacts must not continue-on-error")
    if "verify-signed-images.sh" not in verify:
        fail("verify-artifacts must verify signatures/attestations")
    if "packages: write" in verify or "attestations: write" in verify:
        fail("verify-artifacts must not inherit package/attestation write")
    if "id-token: write" in verify:
        fail("verify-artifacts must not request id-token write")
    verify_needs = job_needs(verify)
    if "build" not in verify_needs:
        fail("verify-artifacts must need the build job")
    if "deploy-staging" in verify_needs:
        fail("verify-artifacts must run before deploy-staging")
    print("provenance-wiring: PASS")


def codeowners_patterns() -> list[tuple[str, str]]:
    rows: list[tuple[str, str]] = []
    for raw in CODEOWNERS.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        rows.append((parts[0], " ".join(parts[1:])))
    return rows


def pattern_matches(pattern: str, rel: str) -> bool:
    pat = pattern
    if pat == "*":
        return True
    if pat.startswith("/"):
        pat = pat[1:]
    if pat.endswith("/"):
        return rel == pat[:-1] or rel.startswith(pat)
    if "*" in pat:
        regex = re.escape(pat).replace(r"\*", "[^/]*")
        return re.fullmatch(regex, rel) is not None
    return rel == pat or rel.startswith(pat.rstrip("/") + "/")


def winning_owners(rel: str) -> str:
    owners = ""
    for pattern, owner in codeowners_patterns():
        if pattern_matches(pattern, rel):
            owners = owner
    return owners


def assert_codeowners_coverage() -> None:
    missing: list[str] = []
    for rel in REQUIRED_CODEOWNERS:
        path = REPO_ROOT / rel
        if not path.exists():
            fail(f"CODEOWNERS required path does not exist: {rel}")
        owners = winning_owners(rel)
        if "@clinic/security" not in owners:
            missing.append(f"{rel} -> {owners or '(default)'}")
    if missing:
        fail("CODEOWNERS missing explicit @clinic/security coverage: " + ", ".join(missing))
    print("codeowners-coverage: PASS")


def verify_checksum(path: Path, expected: str) -> None:
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    if digest != expected.lower():
        fail(f"checksum mismatch for {path.name}: expected {expected}, got {digest}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ISR-015 repository policy gates")
    parser.add_argument("command")
    parser.add_argument("--now")
    parser.add_argument("--ignore-file")
    parser.add_argument("--manifest")
    parser.add_argument("--lock", action="append")
    parser.add_argument("--baseline")
    parser.add_argument("--workflow")
    parser.add_argument("--file")
    parser.add_argument("--sha256")
    args = parser.parse_args(argv)
    workflow = Path(args.workflow) if args.workflow else None
    try:
        if args.command == "path-filters":
            assert_path_filters()
        elif args.command == "immutable-refs":
            assert_immutable_refs()
        elif args.command == "workflow-permissions":
            assert_workflow_permissions(workflow)
        elif args.command == "license-gate":
            locks = [Path(item) for item in args.lock] if args.lock else None
            baseline = Path(args.baseline) if args.baseline else None
            assert_license_gate(locks, baseline)
        elif args.command == "sf001":
            now = parse_utc_expiry(args.now) if args.now else None
            ignore_text = Path(args.ignore_file).read_text(encoding="utf-8") if args.ignore_file else None
            manifest = load_json(Path(args.manifest)) if args.manifest else None
            assert_sf001(now=now, ignore_text=ignore_text, manifest=manifest)
        elif args.command == "promotion-isolation":
            assert_promotion_isolation(workflow)
        elif args.command == "provenance-wiring":
            assert_provenance_wiring(workflow)
        elif args.command == "codeowners-coverage":
            assert_codeowners_coverage()
        elif args.command == "checksum":
            if not args.file or not args.sha256:
                fail("--file and --sha256 are required")
            verify_checksum(Path(args.file), args.sha256)
            print("checksum: PASS")
        else:
            fail(f"unknown command {args.command}")
    except GateError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
