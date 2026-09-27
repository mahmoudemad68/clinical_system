#!/usr/bin/env python3
"""Fail closed if MinIO CI source pins drift from Dockerfiles, scripts, or workflows."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PINS = ROOT / "infra" / "security" / "minio-ci-source-pins.json"
EXECUTABLE_PINS = ROOT / "infra" / "security" / "ci-executable-pins.json"
DOCKERFILE = ROOT / "infra" / "docker" / "minio-ci.Dockerfile"
COMPOSE = ROOT / "infra" / "docker" / "compose.yaml"
START = ROOT / "scripts" / "ci" / "start-secure-file-providers.sh"
PROVISION = ROOT / "scripts" / "ci" / "provision-minio-bucket.sh"
PR_WORKFLOW = ROOT / ".github" / "workflows" / "pull-request.yaml"


def fail(message: str) -> None:
    raise SystemExit(f"FAIL: {message}")


def main() -> int:
    pins = json.loads(PINS.read_text(encoding="utf-8"))
    dockerfile = DOCKERFILE.read_text(encoding="utf-8")
    compose = COMPOSE.read_text(encoding="utf-8")
    start = START.read_text(encoding="utf-8")
    provision = PROVISION.read_text(encoding="utf-8")
    workflow = PR_WORKFLOW.read_text(encoding="utf-8")
    catalog = json.loads(EXECUTABLE_PINS.read_text(encoding="utf-8"))
    catalog_refs = {item["ref"] for item in catalog["images"]}

    golang = pins["build_images"]["golang"]
    alpine = pins["build_images"]["alpine"]
    minio_tag = pins["local_image_tags"]["minio"]
    mc_tag = pins["local_image_tags"]["mc"]

    for needle, label in (
        (golang, "golang build image"),
        (alpine, "alpine runtime image"),
        (pins["minio"]["commit"], "minio commit"),
        (pins["minio"]["source_archive_sha256"], "minio archive sha256"),
        (pins["minio"]["source_archive_url"], "minio archive url"),
        (pins["minio"]["release_tag"], "minio release tag"),
        (pins["mc"]["commit"], "mc commit"),
        (pins["mc"]["source_archive_sha256"], "mc archive sha256"),
        (pins["mc"]["source_archive_url"], "mc archive url"),
        (pins["mc"]["release_tag"], "mc release tag"),
        ("ADD --checksum=sha256:", "ADD checksum"),
    ):
        if needle not in dockerfile:
            fail(f"Dockerfile missing {label}: {needle}")

    if golang not in catalog_refs:
        fail(f"ci-executable-pins.json missing golang ref {golang}")
    if alpine not in catalog_refs:
        fail(f"ci-executable-pins.json missing alpine ref {alpine}")
    for ref in catalog_refs:
        if "quay.io/minio/" in ref:
            fail(f"ci-executable-pins.json still catalogues an unpullable MinIO image: {ref}")

    if golang not in workflow:
        fail("pull-request.yaml must reference the pinned golang build image")
    if alpine not in workflow:
        fail("pull-request.yaml must reference the pinned alpine runtime image")
    if "quay.io/minio/minio:" in workflow or "quay.io/minio/mc:" in workflow:
        fail("pull-request.yaml still references quay.io/minio images")

    if "quay.io/minio/" in compose:
        fail("compose.yaml still references quay.io/minio")
    if "minio-ci.Dockerfile" not in compose:
        fail("compose.yaml must build from minio-ci.Dockerfile")
    if minio_tag not in compose or mc_tag not in compose:
        fail("compose.yaml must tag the local source-built MinIO images")

    if "minio-ci.Dockerfile" not in start:
        fail("start-secure-file-providers.sh must build minio-ci.Dockerfile")
    if minio_tag not in start or mc_tag not in start:
        fail("start-secure-file-providers.sh must use the local source-built image tags")
    if "quay.io/minio/" in start:
        fail("start-secure-file-providers.sh still references quay.io/minio")

    if f'MINIO_MC_IMAGE:-{mc_tag}' not in provision and f'MINIO_MC_IMAGE:-{mc_tag}"' not in provision:
        if mc_tag not in provision:
            fail("provision-minio-bucket.sh must default to the local source-built mc image")
    if "quay.io/minio/" in provision:
        fail("provision-minio-bucket.sh still references quay.io/minio")

    print("minio-ci-source-pins: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
