#!/usr/bin/env python3
"""Fail closed if module-catalog peak classification drifts from detail rows.

Peak is the highest classification a module handles. Individual events or
reference tables may stay at a lower level.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
CATALOG = REPO_ROOT / "docs" / "architecture" / "module-catalog.md"
INVENTORY = REPO_ROOT / "docs" / "data-classification" / "data-inventory.md"
EVENT_SCHEMA = REPO_ROOT / "packages" / "contracts" / "events" / "doctor" / "profile_created.v1.schema.json"

LEVELS = ("public", "internal", "personal", "sensitive", "credential")
SUMMARY_ROW = re.compile(
    r"^\| `([^`]+)` \| [^|]+ \| [^|]+ \| (" + "|".join(LEVELS) + r") \|",
)
DETAIL_HEADING = re.compile(r"^## `([^`]+)`")
CLASSIFICATION_LINE = re.compile(
    r"^\*\*Classification:\*\*\s*(" + "|".join(LEVELS) + r")\b",
)
# Modules that store protected National ID (or equivalent sensitive identifiers).
NATIONAL_ID_MODULES = {
    "Identity": "sensitive",
    "Patients": "sensitive",
    "Doctors": "sensitive",
}
VERIFICATION_EVENT_SUBMITTED = (
    REPO_ROOT / "packages" / "contracts" / "events" / "doctor" / "verification_submitted.v1.schema.json"
)
VERIFICATION_EVENT_DECIDED = (
    REPO_ROOT / "packages" / "contracts" / "events" / "doctor" / "verification_decided.v1.schema.json"
)


class GateError(Exception):
    pass


def fail(message: str) -> None:
    raise GateError(message)


def parse_summary(text: str) -> dict[str, str]:
    rows: dict[str, str] = {}
    in_summary = False
    for line in text.splitlines():
        if line.startswith("## Summary"):
            in_summary = True
            continue
        if in_summary and line.startswith("## "):
            break
        if not in_summary:
            continue
        match = SUMMARY_ROW.match(line)
        if match:
            rows[match.group(1)] = match.group(2)
    if not rows:
        fail("module-catalog summary table has no peak classification rows")
    return rows


def parse_detail(text: str) -> dict[str, str]:
    current: str | None = None
    found: dict[str, str] = {}
    for line in text.splitlines():
        heading = DETAIL_HEADING.match(line)
        if heading:
            current = heading.group(1)
            continue
        if current is None:
            continue
        match = CLASSIFICATION_LINE.match(line)
        if match and current not in found:
            found[current] = match.group(1)
    return found


def assert_catalog(path: Path) -> None:
    text = path.read_text(encoding="utf-8")
    summary = parse_summary(text)
    detail = parse_detail(text)
    for module, peak in summary.items():
        if module not in detail:
            fail(f"summary module {module!r} has no detailed **Classification:** line")
        if detail[module] != peak:
            fail(
                f"{module} peak mismatch: summary={peak!r} detail={detail[module]!r}"
            )
    for module, required in NATIONAL_ID_MODULES.items():
        actual = summary.get(module)
        if actual != required:
            fail(
                f"{module} stores protected National ID material; peak must be "
                f"{required!r}, got {actual!r}"
            )
    doctors_section = text.split("## `Doctors`")[1].split("## `")[0]
    if "specialties" not in doctors_section:
        fail("Doctors catalog entry must keep specialties as Doctors-owned tables")
    if "doctor.profile_created" not in doctors_section:
        fail("Doctors catalog must keep doctor.profile_created as the safe event projection")
    if "personal identifier-only" not in doctors_section:
        fail("Doctors catalog must keep doctor.profile_created at personal, not peak sensitive")
    print("module-catalog-summary-detail: PASS")


def assert_inventory_and_events() -> None:
    inventory = INVENTORY.read_text(encoding="utf-8")
    if "### `doctor_profiles`" not in inventory:
        fail("data-inventory missing doctor_profiles")
    section = inventory.split("### `doctor_profiles`")[1].split("### `")[0]
    if "| `national_id_ciphertext` | sensitive |" not in section:
        fail("doctor_profiles.national_id_ciphertext must remain sensitive")
    if "| `syndicate_number_ciphertext` | sensitive |" not in section:
        fail("doctor_profiles.syndicate_number_ciphertext must remain sensitive")
    specialties = inventory.split("### `specialties`")[1].split("### `")[0]
    if "| sensitive |" in specialties:
        fail("specialties catalogue must not be raised to sensitive")
    event = EVENT_SCHEMA.read_text(encoding="utf-8")
    if '"classification": "personal"' not in event:
        fail("doctor.profile_created event classification must remain personal")
    if '"classification": "sensitive"' in event:
        fail("do not raise doctor.profile_created to sensitive")
    if "### `verification_cases`" not in inventory:
        fail("data-inventory missing verification_cases")
    cases = inventory.split("### `verification_cases`")[1].split("### `")[0]
    if "| `applicant_id` | personal |" not in cases:
        fail("verification_cases.applicant_id must remain personal")
    documents = inventory.split("### `verification_documents`")[1].split("### `")[0]
    if "| `object_id` | sensitive |" not in documents:
        fail("verification_documents.object_id must remain sensitive")
    decisions = inventory.split("### `verification_decisions`")[1].split("### `")[0]
    if "| `notes_ciphertext` | sensitive |" not in decisions:
        fail("verification_decisions.notes_ciphertext must remain sensitive")
    submitted = VERIFICATION_EVENT_SUBMITTED.read_text(encoding="utf-8")
    decided = VERIFICATION_EVENT_DECIDED.read_text(encoding="utf-8")
    for name, body in (
        ("doctor.verification_submitted", submitted),
        ("doctor.verification_decided", decided),
    ):
        if '"classification": "internal"' not in body:
            fail(f"{name} event classification must remain internal")
        if '"national_id"' in body or '"syndicate_number"' in body or '"object_key"' in body or '"object_id"' in body:
            fail(f"{name} must not declare protected identifier or object-key fields")
    print("module-catalog-inventory-events: PASS")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Verify module-catalog peak classification")
    parser.add_argument("--catalog", type=Path, default=CATALOG)
    parser.add_argument("--skip-inventory", action="store_true")
    args = parser.parse_args(argv)
    try:
        assert_catalog(args.catalog)
        if not args.skip_inventory:
            assert_inventory_and_events()
    except GateError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
