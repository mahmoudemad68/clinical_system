# G-02-10 — Packaged Electron E2E

- **Gate:** G-02-10
- **Result:** PARTIAL
- **Candidate SHA:** `7e0df60b55598e6ad0e797794d4f6063b37f6bcc`
- **Recorded:** 2026-08-27T17:12:59.561Z
- **Host OS executed:** linux
- **Host not executed:** windows, macos
- **Command:** `node scripts/desktop/run-packaged-e2e.mjs`

This is packaged-runtime evidence. It is not Vitest, not `electron-forge start`,
not a Vite/Webpack dev server, and not ASAR string inspection alone.

Phase 00 remains **OPEN**. This file does not close the phase.

## OS matrix

| OS | Executed | Result |
| --- | --- | --- |
| Linux | yes | PASS |
| Windows | no | NOT_RUN |
| macOS | no | NOT_RUN |

A cell is PASS only when the packaged binary actually ran on that OS.

## Applications

| App | Packaged binary | Fuses | WebdriverIO |
| --- | --- | --- | --- |
| Clinic Doctor | `apps/doctor-desktop/out/Clinic Doctor-linux-x64/clinic-doctor` (asar d810414fa91d…) | PASS | PASS (5 passing / 0 failing) |
| Clinic Pharmacy | `apps/pharmacy-desktop/out/Clinic Pharmacy-linux-x64/clinic-pharmacy` (asar b1144c5b21a6…) | PASS | PASS (5 passing / 0 failing) |

## Installer / maker artifacts

_None produced on this host._

## What was asserted at runtime

- Renderer document origin is the privileged custom scheme, not `file://`.
- `window.require` / `window.process` are absent (no Node in the renderer).
- `window.clinic` exists; login form is shown; session panel is not (unsigned-in).
- `localStorage`, `sessionStorage`, and cookies do not hold tokens.
- Hostile navigation to `https://example.com` stays on the packaged origin.
- `window.open` is denied.
- Locale switch English → Arabic sets `dir=rtl`.
- Binary fuse wire matches Forge intent, including `GrantFileProtocolExtraPrivileges=DISABLE`.

## Residual

Windows and macOS are not claimed unless a packaged binary ran on those
runners. Signing and notarization remain Phase 23.
