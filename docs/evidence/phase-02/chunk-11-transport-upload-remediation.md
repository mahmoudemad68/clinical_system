# Phase 02 chunk 11 — Electron transport and upload remediation (not phase PASS, not chunk CLOSE)

This remediation fixes two independently evidenced product defects:

- **DOC-LOGIN-CSRF-001** — Electron device `net.fetch` must be explicitly cookieless.
- **DOC-UPLOAD-HOST-001** — Core S3 upload grants must not project `Host` / hop-by-hop headers.

It does **not** itself close Chunk 11.
**Chunk 11 remains OPEN** until post-merge exploratory GUI QA completes the mandatory Doctor GUI journeys **without** cookie-strip reverse proxy or upload-provider substitution.
**Phase 02 remains NOT PASS.**

- **Branch:** `cursor/phase-02-chunk-11-transport-upload-remediation-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/19
- **Baseline (GitHub `main`):** `4541ec03323f6c7f14546a85bdfc7f8eda616403`
  This work does **not** reopen or continue PR #17 or PR #18.
- **Implementation HEAD:** `50be552e3b8af0be717da4adc4f76f32f7b5bfd5`
- **GitHub CI:** (filled after the final-head `pull-request` run)
- **Recorded:** 2026-09-21

## DOC-LOGIN-CSRF-001

**Root cause:** Doctor and Pharmacy Electron main-process transports called `net.fetch` from `session.defaultSession` without `credentials: 'omit'`. Core treats device/bearer flows as stateless, but `/auth/login` and `/auth/mfa/.../verify` run through `identity.session`. If the Electron session acquired `clinic_session` / `XSRF-TOKEN`, later device POSTs entered `ValidateCookieCsrf` and could return `CSRF_MISMATCH`, mapped in the UI to a generic upstream failure.

QA previously proved the GUI worked only when a cookie-strip proxy removed those cookies. That proxy is a workaround and is not required after this change.

**Fix:** Every Core API `net.fetch` and every issued-upload PUT goes through one helper that sets `credentials: 'omit'` in both:

- `apps/doctor-desktop/src/main/platform-gateway.ts`
- `apps/pharmacy-desktop/src/main/platform-gateway.ts`

The helper builds a `RequestInit` object, assigns `body` / `redirect` only when present (so `exactOptionalPropertyTypes` stays satisfied), and calls `net.fetch` once. The device client does not read `XSRF-TOKEN`, does not send CSRF headers, and does not depend on Laravel cookie session state. `ValidateCookieCsrf` is unchanged. Admin browser cookie/CSRF behavior is unchanged. There is no `doctor_desktop` / `pharmacy_desktop` CSRF bypass.

Upload PUT keeps `redirect: 'error'`, does not send the Core bearer token, and does not persist the signed target.

**Exact cookieless Electron transport behavior:** `DEVICE_NET_FETCH_CREDENTIALS = 'omit'` is passed as `credentials: DEVICE_NET_FETCH_CREDENTIALS` on every device Core request (health, meta, login, MFA verify, refresh, `/me`, capabilities, logout, sessions, Doctor APIs, Pharmacy APIs, Verification APIs) and on `putIssuedUploadBytes()`.

**Proof no QA cookie-strip proxy is required:** `npm run desktop:cookieless-electron-transport` binds to `127.0.0.1`, fails if `HTTP_PROXY`/`HTTPS_PROXY`/`ALL_PROXY` is set, and uses a local fixture that *sets* Laravel-style session cookies. Login and MFA succeed only when those cookies are not sent. Control `session.fromPartition` with `credentials: 'include'` and `useSessionCookies: true` still observes cookie *names* `clinic_session` and `XSRF-TOKEN` (counts only; values are not logged), proving the fixture emits cookies. `session.defaultSession` cookie names for those two remain empty after `omit`.

Local runtime result (`tests/desktop-e2e/logs/cookieless-electron-transport.json`, gitignored):

- `proxyEnvPresent: false`
- `fixtureHost: 127.0.0.1`
- `credentialsMode: omit`
- `controlCookieCount: 2` (`XSRF-TOKEN`, `clinic_session`)
- `defaultCookieCount: 0`
- `loginStatus: 200`, `mfaStatus: 200`, `meStatus: 200`, `uploadStatus: 200`
- `csrfMismatch: false`
- `fixtureSawDeviceCookies: false`
- `uploadHadAuthorization: false`

## DOC-UPLOAD-HOST-001

**Root cause:** `S3StoreObject::issueUploadGrant()` copied `temporaryUploadUrl()` headers into `ObjectUploadGrant`. The local S3/MinIO provider can return `Host`. Doctor and Pharmacy `parseIssuedUploadTarget()` fail-closed on `Host` / `Connection` / `Transfer-Encoding`, so Core could emit a grant Electron clients refused before PUT.

**Fix:** Core normalizes grant headers in `S3StoreObject::clientSafeUploadHeaders()` so a public grant contains only client-settable request headers. `Host`, `Connection`, and `Transfer-Encoding` are removed case-insensitively. `Content-Type`, legitimate `Content-Length`, and safe `x-amz-*` / other provider headers are preserved. Unfamiliar provider headers are kept. The signed URL is not rewritten. Desktop parsers remain fail-closed.

Defense in depth: Core normalizes **and** desktop still rejects raw forbidden headers.

Applicant-safe HTTP JSON still omits `"storage_locator"`, `"object_key"`, `"object_id"`, and `"bucket"` fields. Path-style MinIO signed URLs may contain the object key in the URL path; that is the signed PUT target owned by main, not a renderer-safe projection. Renderer IPC continues to drop the signed target. `__debugInfo` still redacts locator and URL.

## Tests

Doctor and Pharmacy:

- platform-gateway cookieless credentials on health, login, MFA, refresh, `/me`, capabilities, and upload PUT
- Set-Cookie response does not attach `Cookie` / CSRF headers on the next device POST
- access/refresh tokens still never cross IPC
- `parseIssuedUploadTarget()` still rejects `Host` / `host` / `HOST` / `Connection` / `transfer-encoding`
- doctor/pharmacy main transport: login → MFA → onboard/profile → open case → create upload → PUT → complete (`available` in the mocked complete projection)

Core:

- `S3StoreObject` grant contract (case-insensitive forbidden headers, preserve Content-Type and signed provider headers, exact URL, no locator in `__debugInfo`)
- live MinIO create → inspect safe grant (no Host/Connection/Transfer-Encoding; Content-Type kept) → PUT exact bytes → complete → scan → `available` (Doctor and Pharmacy), not InMemoryStoreObject

Runtime:

- `npm run desktop:cookieless-electron-transport` — real Electron `net.fetch`

Packaged security invariants are unchanged (source trust-boundary tests plus packaged E2E): `nodeIntegration=false`, `contextIsolation=true`, `sandbox=true`, renderer has no fetch/tokens/cookies/signed URL/raw path, no raw IPC / generic invoke, packaged HTTPS allowlist, packaged HTTP refused.

## Local commands

| Command | Result |
| --- | --- |
| `npm run typecheck --workspace apps/doctor-desktop` | passed |
| `npm run test --workspace apps/doctor-desktop` | **161 passed** / 14 files |
| `npm run typecheck --workspace apps/pharmacy-desktop` | passed |
| `npm run test --workspace apps/pharmacy-desktop` | **138 passed** / 13 files |
| `npm run typecheck --workspace packages/typescript/desktop_bridge_contracts` | passed |
| `npm run test --workspace packages/typescript/desktop_bridge_contracts` | **5 passed** / 1 file |
| `node --test scripts/desktop/cookieless-electron-transport.test.mjs` | **2 passed** |
| `npm run desktop:cookieless-electron-transport` | passed (see runtime JSON above) |
| `AWS_ENDPOINT=http://127.0.0.1:19000` Pest `S3StoreObjectUploadGrantHeadersTest` + `S3StoreObjectContractTest` + `VerificationSecureFileProviderTest` | **10 passed** / 144 assertions |
| same endpoint Pest including `VerificationUploadFlowsTest` + `AdminVerificationDocumentAccessProviderTest` | **26 passed** / 498 assertions |
| Pest `tests/Feature/Verification` + selected Admin verification + `tests/Unit/Platform` | **350 passed**, 1 local failure unrelated to this change (see remaining risks) |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 errors |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations |
| `./vendor/bin/pint --dirty` | passed |
| Packaged Electron E2E | GitHub `desktop-packaged-e2e` on final HEAD |
| Forge Doctor smoke | GitHub `desktop` job on final HEAD |

Local MinIO was `http://127.0.0.1:19000` (anonymous GET 403). Port 9000 on this VM is occupied by another HTTP listener, so phpunit.xml’s default `AWS_ENDPOINT=http://127.0.0.1:9000` is overridden for live object-store tests. CI starts MinIO on 9000 via `scripts/ci/start-secure-file-providers.sh`.

## Prior HEAD `f352dec` CI (superseded)

https://github.com/mahmoudemad68/clinical_system/actions/runs/35616299055 on `f352dec1638dae57a745fd42c45929086b289d04`:

- Core API, Contracts, Security scans, Admin web, Packaged Electron E2E ubuntu/macOS: success
- Electron desktops: failed `exactOptionalPropertyTypes` on `redirect: RequestRedirect \| undefined` — **fixed in this HEAD** by assigning optional `RequestInit` fields only when present
- Secure-file providers: 2 failed assertions that a path-style signed URL must omit the object-key substring — **fixed in this HEAD** by asserting missing JSON fields (`"storage_locator"`, `"object_key"`, `"object_id"`, `"bucket"`) and client-safe header names instead of requiring the signed URL path to hide the locator
- Packaged Electron E2E windows-latest: Electron v44 zip **504 Gateway Time-out** during `node_modules/electron/install.js` (download flake; ubuntu/macOS packaged E2E passed)
- Flutter: `clinic_local_database` sqlite3 hook hash mismatch for `libsqlite3mc.x64.linux.so` (Patient Flutter is out of scope; job ran because root `package.json` gained the cookieless npm script)

## Remaining risks

This remediation does not itself close Chunk 11.
Chunk 11 requires post-merge exploratory GUI QA without cookie-strip or upload-provider workarounds.
Phase 02 remains NOT PASS.

Local live clamd on this VM returned `Clean` for the EICAR sample in `ClamdScanObjectTest` (expected `Infected`). That scanner signature gap is environmental, not part of this transport/grant change. CI starts its own clamd. Verification live MinIO tests here used the reachable clamd for clean PDFs and completed `available`.

Path-style MinIO signed PUT URLs include the object key in the URL path. Device HTTP create responses therefore contain that path inside `upload_target.url`. Renderer IPC still drops the signed target; Core `__debugInfo` still redacts locator/URL.

Windows packaged E2E may flake on Electron binary download (504). Flutter CI may flake on sqlite3mc asset hash when root `package.json` changes.

Out of scope and unchanged: Doctor clinic-location/staff UI, Patient Flutter, extra Pharmacy branches/memberships, DEF-SEC-MFA-001, SF-001, G-08-04, staging provisioning, general Doctor profile PATCH, Phase 03, production TLS/KMS, `designs/**`.
