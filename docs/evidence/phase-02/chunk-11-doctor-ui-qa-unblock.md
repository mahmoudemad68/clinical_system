# Phase 02 chunk 11 — Doctor UI QA unblock (not phase PASS, not chunk CLOSE)

This remediation only unblocks Chunk 11 GUI QA.
It does **not** itself close Chunk 11.
**Phase 02 remains NOT PASS.**

Chunk 11 remains **QA BLOCKED** until the mandatory Doctor onboarding →
verification GUI journey is successfully re-run on a merged main SHA after
independent review of this change.

- **Branch:** `cursor/phase-02-chunk-11-doctor-qa-unblock-cc7f`
- **Baseline (GitHub `main`):** `64d4ca6b533b146855a3bcb2f1d915aeb7105d46`
  (merge of PR #17). This work does **not** reopen or continue PR #17.
- **Final HEAD:** _recorded after exact-HEAD GitHub CI_
- **GitHub CI:** _pending exact-HEAD wait_
- **Recorded:** 2026-09-21

## Why Forge development was blank

Packaged Doctor correctly refuses `http://localhost:8080` (`INSECURE_TRANSPORT`).
That production behavior is unchanged.

Forge development rendered a blank window because three development defaults
collided:

1. `@electron-forge/plugin-webpack` sets renderer `devtool` to
   `eval-source-map` when `!isProd`.
2. Doctor `forge.config.ts` therefore historically included `'unsafe-eval'` in
   `devContentSecurityPolicy` so those maps could run.
3. `apps/doctor-desktop/src/main/index.ts` always installed
   `session.defaultSession.webRequest.onHeadersReceived` and **overwrote** every
   response CSP with the strict packaged `contentSecurityPolicy()`
   (`script-src 'self' clinic-doctor-app:`, `connect-src 'none'`, no
   `'unsafe-eval'`).

The packaged header won. Webpack's eval-based development maps were blocked.
Chromium refused script evaluation. The renderer stayed blank. This is a
development-only CSP collision, not a product-flow defect in onboarding or
verification.

## Development vs packaged CSP

| Runtime | CSP authority | `unsafe-eval` | Renderer origin | API HTTP |
| --- | --- | --- | --- | --- |
| Forge development (`!app.isPackaged`) | Forge `devContentSecurityPolicy` (webpack-dev-server header). Main does **not** overwrite CSP. Packaged HTML meta is omitted (`webpackConfig.mode !== 'production'`). | absent | loopback `http://localhost:3000` | `http://localhost:8080` allowed |
| Packaged (`app.isPackaged`) | `packagedContentSecurityPolicy()` via response headers + production HTML meta | absent | `clinic-doctor-app://-` | HTTP refused (`INSECURE_TRANSPORT`) |

Development CSP (Forge header only):

- `script-src 'self'`
- `style-src 'self' 'unsafe-inline'` (current MUI/Emotion)
- `connect-src 'self' ws://127.0.0.1:* ws://localhost:*` (HMR websocket, loopback only)
- no `*`, no `data:`/`blob:`/`unsafe-inline` for script, no remote hosts

Packaged CSP is unchanged in intent: `default-src 'none'`, `connect-src 'none'`,
`script-src 'self' clinic-doctor-app:`, no `'unsafe-eval'`.

Unchanged controls: `nodeIntegration=false`, `contextIsolation=true`,
`sandbox=true`, navigation/child-window/permission/webview/download denial,
custom packaged origin, compile-time HTTPS allowlist, Electron fuses.

## Non-eval webpack

`apps/doctor-desktop/webpack.renderer.config.ts` sets `devtool: 'source-map'`.
Forge merges this renderer config **after** its base config, so it replaces
the plugin's development `eval-source-map`. Forbidden modes (`eval`,
`eval-source-map`, `eval-cheap-module-source-map`) are regression-tested.

The compiled Forge development HTML contains **no** packaged CSP meta tag.
The renderer bundle contains no `eval(` source-generation.

## Real Forge development smoke

Command: `npm run desktop:forge-doctor-smoke`
(`scripts/desktop/run-forge-doctor-smoke.mjs` — actual `electron-forge start`,
not Vitest and not a packaged binary).

Observed 2026-09-21 in this workspace:

- Renderer origin `http://localhost:3000/main_window/index.html`
- Product title `Clinic Doctor`
- Honest `keystore-unavailable` (OS keystore not available in this agent)
- `window.clinic` object, `window.clinic.doctor` object, `window.clinic.pharmacy` undefined
- `window.require` / `window.process` / `window.electron` undefined
- no generic `invoke`
- no CSP `unsafe-eval` / EvalError lines in protocol or Forge logs
- Core API `GET http://localhost:8080/api/v1/health` → HTTP 200 from the smoke
  process; renderer health panel received a caller-visible health projection
  (`overall-status` + `health-message`) through main-owned IPC

This is **not** the mandatory QA GUI journey and **not** Chunk 11 closeout.

## Packaged Electron E2E

Local `node scripts/desktop/run-packaged-e2e.mjs` with `CLINIC_DESKTOP_SKIP_MAKE=1`
(real Forge-packaged binaries, not Forge dev server / Vitest):

| App | Origin | WebdriverIO |
| --- | --- | --- |
| Clinic Doctor | `clinic-doctor-app://-` | **5 passing / 0 failing** |
| Clinic Pharmacy | `clinic-pharmacy-app://-` | **5 passing / 0 failing** |

Doctor assertions covered: custom origin, Doctor bridge present, Pharmacy bridge
absent, no Node globals, no generic `invoke`, hostile navigation refused,
Arabic RTL. Doctor `app.asar` SHA-256
`552885edc51bc88c49649ecc0484fc401530c313146647f216ad9ab37c0a97a9`.

Cross-OS packaged proof is GitHub `desktop-packaged-e2e` on final HEAD.

Packaged HTTP rejection remains unit-tested:

- packaged + `http://localhost:8080` → `INSECURE_TRANSPORT`
- packaged + unlisted HTTPS → `ORIGIN_REFUSED`
- packaged + empty allowlist → `PACKAGED_ALLOWLIST_MISSING`
- packaged + exact baked HTTPS → allowed
- runtime env cannot enlarge the baked list
- `CLINIC_API_BASE_URL` still only selects an already-baked HTTPS origin

## Exact local test counts (this workspace)

| Command | Result |
| --- | --- |
| `npm run typecheck --workspace apps/doctor-desktop` | pass |
| `npm run test --workspace apps/doctor-desktop` | **14 files, 149 passed** |
| `npm run test --workspace apps/pharmacy-desktop` | **13 files, 126 passed** |
| `node --test scripts/desktop/forge-doctor-smoke.test.mjs` | **3 passed** |
| `npm run desktop:forge-doctor-smoke` | pass (see above) |

GitHub CI exact run ID is recorded after the wait on **final HEAD**.

## Residuals

- Chunk 11 GUI QA must be re-run after merge; this PR does not close Chunk 11
- Doctor clinic-location/staff UI remains
- Patient Flutter Phase-02 completion remains
- Pharmacy additional branches/membership management remains
- Phase-02 p95/load closeout remains
- `professional_id` remains ENGINEERING_DEFAULT
- DEF-SEC-MFA-001 remains OPEN / not addressed
- SF-001 remains MERGE_ONLY / production promotion blocked
- staging remains unprovisioned
