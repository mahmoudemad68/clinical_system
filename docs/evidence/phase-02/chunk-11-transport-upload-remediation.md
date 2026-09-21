# Phase 02 chunk 11 — Electron transport and upload remediation (not phase PASS, not chunk CLOSE)

This remediation fixes two independently evidenced product defects:

- **DOC-LOGIN-CSRF-001** — Electron device `net.fetch` must be explicitly cookieless.
- **DOC-UPLOAD-HOST-001** — Core S3 upload grants must not project `Host` / hop-by-hop headers.

It does **not** itself close Chunk 11.
**Chunk 11 remains OPEN** until post-merge exploratory GUI QA completes the mandatory Doctor GUI journeys **without** cookie-strip reverse proxy or upload-provider substitution.
**Phase 02 remains NOT PASS.**

- **Branch:** `cursor/phase-02-chunk-11-transport-upload-remediation-cc7f`
- **Draft PR:** (filled after open)
- **Baseline (GitHub `main`):** `4541ec03323f6c7f14546a85bdfc7f8eda616403`
  This work does **not** reopen or continue PR #17 or PR #18.
- **Implementation HEAD:** (filled after commit)
- **GitHub CI:** (filled after the final-head `pull-request` run)
- **Recorded:** 2026-09-21

## DOC-LOGIN-CSRF-001

**Root cause:** Doctor and Pharmacy Electron main-process transports called `net.fetch` from `session.defaultSession` without `credentials: 'omit'`. Core treats device/bearer flows as stateless, but `/auth/login` and `/auth/mfa/.../verify` run through `identity.session`. If the Electron session acquired `clinic_session` / `XSRF-TOKEN`, later device POSTs entered `ValidateCookieCsrf` and could return `CSRF_MISMATCH`, mapped in the UI to a generic upstream failure.

QA previously proved the GUI worked only when a cookie-strip proxy removed those cookies. That proxy is a workaround and is not required after this change.

**Fix:** Every Core API `net.fetch` and every issued-upload PUT goes through one helper that sets `credentials: 'omit'` in both:

- `apps/doctor-desktop/src/main/platform-gateway.ts`
- `apps/pharmacy-desktop/src/main/platform-gateway.ts`

The device client does not read `XSRF-TOKEN`, does not send CSRF headers, and does not depend on Laravel cookie session state. `ValidateCookieCsrf` is unchanged. Admin browser cookie/CSRF behavior is unchanged. There is no `doctor_desktop` / `pharmacy_desktop` CSRF bypass.

Upload PUT keeps `redirect: 'error'`, does not send the Core bearer token, and does not persist the signed target.

**Proof no QA cookie-strip proxy is required:** the runtime Electron probe binds to `127.0.0.1`, fails if `HTTP_PROXY`/`HTTPS_PROXY` is set, and uses a local fixture that *sets* Laravel-style session cookies. Login and MFA succeed only when those cookies are not sent. Control `session.fromPartition` with `credentials: 'include'` still observes cookie *names* `clinic_session` and `XSRF-TOKEN`, proving the fixture emits cookies. `session.defaultSession` cookie names for those two remain empty after omit.

## DOC-UPLOAD-HOST-001

**Root cause:** `S3StoreObject::issueUploadGrant()` copied `temporaryUploadUrl()` headers into `ObjectUploadGrant`. The local S3/MinIO provider can return `Host`. Doctor and Pharmacy `parseIssuedUploadTarget()` fail-closed on `Host` / `Connection` / `Transfer-Encoding`, so Core could emit a grant Electron clients refused before PUT.

**Fix:** Core normalizes grant headers in `S3StoreObject` so a public grant contains only client-settable request headers. `Host`, `Connection`, and `Transfer-Encoding` are removed case-insensitively. `Content-Type`, legitimate `Content-Length`, and safe `x-amz-*` / other provider headers are preserved. The signed URL is not rewritten. Desktop parsers remain fail-closed.

Defense in depth: Core normalizes **and** desktop still rejects raw forbidden headers.

## Tests

Doctor and Pharmacy:

- platform-gateway cookieless credentials on health, login, MFA, refresh, `/me`, capabilities, and upload PUT
- Set-Cookie response does not attach `Cookie` / CSRF headers on the next device POST
- access/refresh tokens still never cross IPC
- `parseIssuedUploadTarget()` still rejects `Host` / `host` / `HOST` / `Connection` / `transfer-encoding`
- doctor/pharmacy main transport: login → MFA → onboard/profile → open case → create upload → PUT → complete (`available` in the mocked complete projection)

Core:

- `S3StoreObject` grant contract (case-insensitive forbidden headers, preserve Content-Type and signed provider headers, exact URL, no locator in `__debugInfo`)
- live MinIO create → inspect safe grant → PUT exact bytes → complete → scan → `available` (Doctor and Pharmacy), not InMemoryStoreObject

Runtime:

- `npm run desktop:cookieless-electron-transport` — real Electron `net.fetch`

Packaged security invariants are unchanged (source trust-boundary tests plus packaged E2E): `nodeIntegration=false`, `contextIsolation=true`, `sandbox=true`, renderer has no fetch/tokens/cookies/signed URL/raw path, no raw IPC / generic invoke, packaged HTTPS allowlist, packaged HTTP refused.

## Local commands (filled after execution)

| Command | Result |
| --- | --- |
| `npm run typecheck --workspace apps/doctor-desktop` | pending |
| `npm run test --workspace apps/doctor-desktop` | pending |
| `npm run typecheck --workspace apps/pharmacy-desktop` | pending |
| `npm run test --workspace apps/pharmacy-desktop` | pending |
| `node --test scripts/desktop/cookieless-electron-transport.test.mjs` | pending |
| `npm run desktop:cookieless-electron-transport` | pending |
| Core Pest (grant headers + verification/platform provider tests) | pending |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | pending |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | pending |
| Packaged Electron E2E | GitHub `desktop-packaged-e2e` on final HEAD |
| Forge Doctor smoke | GitHub `desktop` job on final HEAD |

## Remaining risks

This remediation does not itself close Chunk 11.
Chunk 11 requires post-merge exploratory GUI QA without cookie-strip or upload-provider workarounds.
Phase 02 remains NOT PASS.

Out of scope and unchanged: Doctor clinic-location/staff UI, Patient Flutter, extra Pharmacy branches/memberships, DEF-SEC-MFA-001, SF-001, G-08-04, staging provisioning, general Doctor profile PATCH, Phase 03, production TLS/KMS, `designs/**`.
