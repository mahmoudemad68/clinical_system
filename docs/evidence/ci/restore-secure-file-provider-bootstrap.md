# CI: restore Secure-file provider bootstrap

**Date:** 2026-09-27  
**Finding:** `CI_INFRA_MINIO_REGISTRY_PULL`  
**Not:** SF-001 (`extract-zip@2.0.1`)  
**Not:** Phase 02 product policy, PR #29, Admin timeout flake

## Problem

GitHub job `Secure-file providers` failed before product tests started:

```
Unable to find image 'quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z@sha256:a1ea29fa28355559ef137d71fc570e508a214ec84ff8083e39bc5428980b015e' locally
docker: Error response from daemon: unauthorized: access to the requested resource is not authorized
```

Docker exit 125. The same failure reproduced on PR #29 HEAD `ff6c578` and is external to that product change.

## Reproduction (2026-09-27)

Anonymous registry GET of the previously pinned digest:

- `https://quay.io/v2/minio/minio/manifests/sha256:a1ea29fa28355559ef137d71fc570e508a214ec84ff8083e39bc5428980b015e` → HTTP 401 `UNAUTHORIZED`
- anonymous quay token for `repository:minio/minio:pull` has `"actions": []` (no pull)
- `quay.io/minio/mc` index digest `sha256:aead63c77f9db9107f1696fb08ecb0faeda23729cde94b0f663edf4fe09728e3` → HTTP 401
- `https://dl.min.io/server/minio/release/linux-amd64/archive/minio.RELEASE.2025-04-22T22-12-26Z` → HTTP 410
- GitHub source archives for the same Community commits remain 200 with stable SHA-256

## Root cause

The CI fixture depended on anonymously pulling historical MinIO Community container images. Docker Hub `minio/minio` / `minio/mc` were already gone. The 2026-09-20 workaround (`quay.io/minio/...@sha256:...`) stopped working when quay.io stopped granting anonymous pull (`actions: []`). MinIO's own `dl.min.io` binary CDN returns 410. This is a registry/distribution outage, not a product test failure and not SF-001.

## Alternatives not used

| Option | Why rejected |
| --- | --- |
| Arbitrary third-party mirror / Silo / SeaweedFS | Not official MinIO Community source; would change S3 test fixture semantics |
| `latest` / floating tags | Not reproducible; fails ISR-015 |
| Remove digest verification / `continue-on-error` / skip the job | Weakens fail-closed CI |
| AIStor / `quay.io/minio/aistor/*` | Commercial image; this repository has no licensing/product approval |
| Privileged registry credentials | Hides the public-pull failure; not suitable for untrusted PR CI |
| Official `Dockerfile.release` (downloads `dl.min.io`) | `dl.min.io` is 410 |
| GitHub release prebuilt binaries alone | Official and checksummed, but upstream currently expects source/build rather than historical prebuilt images. Kept as a documented fallback, not used. |

## Selected remediation

Construct the CI/local MinIO server and `mc` client from the **same Community releases previously pinned**, using official GitHub source:

| Component | Release | Commit |
| --- | --- | --- |
| minio | `RELEASE.2025-04-22T22-12-26Z` | `0d7408fc9969caf07de6a8c3a84f9fbb10a6739e` |
| mc | `RELEASE.2025-04-16T18-13-26Z` | `b00526b153a31b36767991a4f5ce2cced435ee8e` |

Trust model:

1. GitHub commit archives (`ADD --checksum=sha256:...`)
2. `go.sum` + `GOSUMDB=sum.golang.org` for modules
3. Digest-pinned `golang:1.24.2-alpine3.21` (matches `toolchain go1.24.2`) and `alpine:3.21.3`
4. Local image tags only (`clinic-ci-minio:local`, `clinic-ci-mc:local`); no registry push
5. Pin drift fails closed via `scripts/ci/verify-minio-ci-source-pins.py` (ISR-015)

No secrets. No production object-store configuration change. ClamAV image and malware tests are unchanged.

## Behavioral compatibility

This is a CI test-infrastructure restore, not a MinIO product upgrade. The S3 API used by Laravel tests remains Community `RELEASE.2025-04-22T22-12-26Z`. Application AWS endpoint/credentials/bucket env vars are unchanged.

Compose healthcheck changed from `mc ready local` (requires `mc` inside the server image) to `GET /minio/health/live` with pinned alpine `wget`. Bucket policy remains `anonymous none`. Runtime images run as uid `10001` (`USER clinic`), matching `core-api.Dockerfile`. Local compose does not mount a named volume over `/data` so the non-root user can write the ephemeral fixture.

## SF-001

Unchanged. Canonical SF-001 remains `extract-zip@2.0.1` / MERGE_ONLY.
