# G-02-10 — Packaged Electron E2E

- **Gate:** G-02-10
- **Result:** PASS
- **Candidate SHA:** `4a98fac6538546b52f6eff0c5ef98a9608714b90`
- **Workflow run:** [33155677159](https://github.com/mahmoudemad68/clinical_system/actions/runs/33155677159)
- **Recorded:** 2026-08-28
- **Command:** `node scripts/desktop/run-packaged-e2e.mjs` (CI step `Packaged Doctor and Pharmacy WebdriverIO`)

This is packaged-runtime evidence. It is not Vitest, not `electron-forge start`,
not a Vite/Webpack dev server, and not ASAR string inspection alone.

Each matrix job **packaged and launched both** Clinic Doctor and Clinic Pharmacy
Forge applications, then ran WebdriverIO with `@wdio/electron-service` against
those binaries. The harness fails the step if either application fails to
package, launch, or pass the spec.

Phase 00 remains **OPEN**. This file does not close the phase.

## OS matrix

A cell is PASS only when the packaged Doctor and Pharmacy binaries actually ran
on that OS.

| OS | Runner | Job ID | Executed | Result |
| --- | --- | ---: | --- | --- |
| Linux | `ubuntu-latest` | [98797779682](https://github.com/mahmoudemad68/clinical_system/actions/runs/33155677159/job/98797779682) | yes | PASS |
| Windows | `windows-latest` | [98797779679](https://github.com/mahmoudemad68/clinical_system/actions/runs/33155677159/job/98797779679) | yes | PASS |
| macOS | `macos-latest` | [98797779637](https://github.com/mahmoudemad68/clinical_system/actions/runs/33155677159/job/98797779637) | yes | PASS |

All three jobs reached and passed the step **Packaged Doctor and Pharmacy WebdriverIO**.

Same-SHA supporting job (not this gate): Electron desktops
[98797779615](https://github.com/mahmoudemad68/clinical_system/actions/runs/33155677159/job/98797779615)
(type-check, trust-boundary/IPC, shared TypeScript packages).

## Applications launched on every OS

| App | Origin | Ubuntu | Windows | macOS |
| --- | --- | --- | --- | --- |
| Clinic Doctor | `clinic-doctor-app://-` | launched + tested | launched + tested | launched + tested |
| Clinic Pharmacy | `clinic-pharmacy-app://-` | launched + tested | launched + tested | launched + tested |

Logs: artifacts `g-02-10-ubuntu-latest`, `g-02-10-windows-latest`,
`g-02-10-macos-latest`.

## What was asserted at runtime

- Renderer document origin is the privileged custom `clinic-*-app://` scheme, not `file://`.
- `window.require` / `window.process` are absent (no Node in the renderer).
- `window.clinic` exists; login form is shown; session panel is not (unsigned-in).
- `localStorage`, `sessionStorage`, and cookies do not hold tokens.
- Hostile navigation to `https://example.com` stays on the packaged origin.
- `window.open` is denied.
- Locale switch English → Arabic sets `dir=rtl`.
- Binary fuse wire matches Forge intent, including `GrantFileProtocolExtraPrivileges=DISABLE`.

## Residual

Signing and notarization remain Phase 23. This gate does not close Phase 00.
