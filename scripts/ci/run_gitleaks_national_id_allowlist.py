#!/usr/bin/env python3
"""Prove clinic-egyptian-national-id stays strict except one FrankenPHP PURL line.

Uses Gitleaks 8.16.1. Does not weaken the National-ID regex or secretGroup.

14-digit tokens other than the exact allowlisted PURL are assembled at runtime
so this committed file cannot itself match clinic-egyptian-national-id.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
GITLEAKS_TOML = REPO_ROOT / ".gitleaks.toml"
GITLEAKS_IMAGE = "zricethezav/gitleaks:v8.16.1"
GITLEAKS_VERSION = "8.16.1"

NATIONAL_ID_REGEX = r"(^|[^0-9])([23][0-9]{13})([^0-9]|$)"
# Exact upstream PURL. This line is the allowlisted exception (regexTarget=line).
ALLOWED_PURL = (
    "pkg:golang/github.com/dunglas/frankenphp/caddy@v0.0.0-20260806145936-a765b086f5cc"
)
RULE_ID = "clinic-egyptian-national-id"


class GateError(Exception):
    pass


def fail(message: str) -> None:
    raise GateError(message)


def allowed_purl_regex() -> str:
    return ALLOWED_PURL.replace(".", r"\.")


def synthetic_national_id() -> str:
    # Starts with 2, 14 digits, not in the global exact-literal allowlist.
    return "".join(("28", "501", "011", "239", "999"))


def other_pseudo_purl() -> str:
    return (
        "pkg:golang/github.com/dunglas/frankenphp/caddy@v0.0.0-"
        + "".join(("2027", "0101", "125936"))
        + "-bbbbbbbbbbbb"
    )


def frankenphp_timestamp() -> str:
    marker = "@v0.0.0-"
    start = ALLOWED_PURL.index(marker) + len(marker)
    return ALLOWED_PURL[start : start + 14]


def assert_static_config() -> None:
    text = GITLEAKS_TOML.read_text(encoding="utf-8")
    if f'id = "{RULE_ID}"' not in text:
        fail("clinic-egyptian-national-id rule is missing")
    quoted = "regex = '''" + NATIONAL_ID_REGEX + "'''"
    if quoted not in text:
        fail("clinic-egyptian-national-id detection regex must not change")
    rule_start = text.index(f'id = "{RULE_ID}"')
    global_allow = text.rfind("\n[allowlist]\n")
    if global_allow == -1:
        fail("global [allowlist] is missing")
    rule_block = text[rule_start:global_allow]
    if "secretGroup = 2" not in rule_block:
        fail("secretGroup must remain 2")
    if "[rules.allowlist]" not in rule_block:
        fail("must use Gitleaks v8.16 [rules.allowlist] on clinic-egyptian-national-id")
    if "[[rules.allowlists]]" in text:
        fail("do not use [[rules.allowlists]] (not the v8.16 form required here)")
    if 'regexTarget = "line"' not in rule_block:
        fail('rule allowlist must set regexTarget = "line"')
    expected = "'''" + allowed_purl_regex() + "'''"
    if expected not in rule_block:
        fail("rule allowlist regex must be exactly the FrankenPHP/Caddy PURL")
    if rule_block.count("regexes") != 1:
        fail("clinic-egyptian-national-id must have exactly one allowlist regexes list")
    global_block = text[global_allow:]
    if "infra/security/vex" in global_block:
        fail("do not globally allowlist the VEX directory")
    if allowed_purl_regex() in global_block or ALLOWED_PURL in global_block:
        fail("do not move the FrankenPHP PURL into the global allowlist")
    stamp = frankenphp_timestamp()
    if "'''" + stamp + "'''" in global_block:
        fail("do not globally allow the FrankenPHP pseudo-version timestamp")
    print("gitleaks-nid-static: PASS")


def docker_gitleaks(source: Path, config: Path, report: Path) -> tuple[int, str]:
    proc = subprocess.run(
        [
            "docker",
            "run",
            "--rm",
            "-v",
            f"{source.resolve()}:/src:ro",
            "-v",
            f"{config.resolve()}:/config/gitleaks.toml:ro",
            "-v",
            f"{report.parent.resolve()}:/out",
            "-w",
            "/src",
            GITLEAKS_IMAGE,
            "detect",
            "--no-git",
            "--redact",
            "--config=/config/gitleaks.toml",
            "--source=/src",
            "--exit-code=1",
            "--report-format=json",
            f"--report-path=/out/{report.name}",
        ],
        capture_output=True,
        text=True,
    )
    return proc.returncode, (proc.stdout or "") + (proc.stderr or "")


def findings(report: Path) -> list[dict]:
    if not report.exists() or report.stat().st_size == 0:
        return []
    data = json.loads(report.read_text(encoding="utf-8"))
    if isinstance(data, list):
        return data
    fail(f"unexpected gitleaks JSON: {type(data)}")
    return []


def expect_clean(label: str, source: Path, config: Path, work: Path) -> None:
    report = work / f"{label}.json"
    code, output = docker_gitleaks(source, config, report)
    if code != 0:
        fail(f"{label}: expected 0 leaks, gitleaks exited {code}\n{output}")
    if findings(report):
        fail(f"{label}: expected 0 leaks, report was not empty")
    print(f"{label}: PASS (allowlisted / no leak)")


def expect_nid_leak(label: str, source: Path, config: Path, work: Path) -> None:
    report = work / f"{label}.json"
    code, output = docker_gitleaks(source, config, report)
    if code == 0:
        fail(f"{label}: expected clinic-egyptian-national-id leak, gitleaks exited 0")
    hits = [item for item in findings(report) if item.get("RuleID") == RULE_ID]
    if not hits:
        fail(f"{label}: expected {RULE_ID} finding, got {findings(report)!r}\n{output}")
    print(f"{label}: PASS (failed closed: {RULE_ID})")


def write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")


def run_fixture_scans() -> None:
    version = subprocess.run(
        ["docker", "run", "--rm", GITLEAKS_IMAGE, "version"],
        capture_output=True,
        text=True,
        check=False,
    )
    banner = (version.stdout or "") + (version.stderr or "")
    if version.returncode != 0 or GITLEAKS_VERSION not in banner:
        fail(f"expected Gitleaks {GITLEAKS_VERSION}, got rc={version.returncode} {banner!r}")
    print(f"gitleaks-version: {GITLEAKS_VERSION}")

    nid = synthetic_national_id()
    with tempfile.TemporaryDirectory(prefix="gitleaks-nid-") as tmp:
        root = Path(tmp)
        work = root / "reports"
        work.mkdir()
        # The Gitleaks image runs as uid 1000. GitHub Actions creates this
        # directory as the runner user (typically 1001) with 0755, so the
        # container cannot write /out/*.json unless the directory is world-writable.
        work.chmod(0o777)
        config = GITLEAKS_TOML

        allowed = root / "allowed-purl"
        write(allowed / "purl.txt", ALLOWED_PURL + "\n")
        expect_clean("1-exact-frankenphp-purl", allowed, config, work)

        source_tree = root / "source-nid"
        write(source_tree / "apps" / "example.php", f"// {nid}\n")
        expect_nid_leak("2-source-file-national-id", source_tree, config, work)

        vex_tree = root / "vex-nid"
        vex_rel = Path("infra/security/vex/core-api-frankenphp-cve-2026-56854.openvex.json")
        original = (REPO_ROOT / vex_rel).read_text(encoding="utf-8")
        write(vex_tree / vex_rel, original.rstrip() + f'\n{{"decoy": "{nid}"}}\n')
        write(
            vex_tree / "infra/security/vex/core-api-frankenphp-cve-2026-56854.applicability.json",
            json.dumps({"decoy": nid}, indent=2) + "\n",
        )
        expect_nid_leak("3-vex-file-national-id", vex_tree, config, work)

        other = root / "other-pseudo"
        write(other / "purl.txt", other_pseudo_purl() + "\n")
        expect_nid_leak("4-other-go-pseudo-version", other, config, work)

    print("gitleaks-nid-fixtures: PASS")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Gitleaks National-ID allowlist regression")
    parser.add_argument("--static-only", action="store_true")
    args = parser.parse_args(argv)
    try:
        assert_static_config()
        if not args.static_only:
            run_fixture_scans()
    except GateError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
