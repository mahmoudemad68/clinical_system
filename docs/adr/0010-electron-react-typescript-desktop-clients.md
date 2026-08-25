# ADR 0010 — Electron, React, and TypeScript for desktop clients

- **Status:** Accepted, with encrypted-storage and distribution spikes required
- **Date:** 2026-08-25
- **Deciders:** Product owner, platform architecture, desktop, security, privacy
- **Phase:** 00; consumed by 01-18 and validated by 21-23
- **Supersedes / extends:** Desktop-client portions of [ADR 0002](0002-single-repository-layout-and-boundary-enforcement.md), [ADR 0003](0003-api-first-contracts.md), and [ADR 0006](0006-client-local-encryption.md); extends [ADR 0008](0008-package-and-version-policy.md) for the Electron runtime and native modules

## Context

The source `plan.md` selected Flutter Desktop for the doctor and pharmacy
applications. The product owner has explicitly changed that implementation
choice to Electron with React and TypeScript. Flutter remains the patient mobile
stack for Android and iOS, and the admin dashboard remains a browser-hosted
React and TypeScript application.

This is a runtime, security-boundary, packaging, local-encryption, testing, and
release decision. Treating Electron as only a renderer swap would leave the
roadmap's Flutter/Dart packages, desktop storage, generated clients, IPC,
signing, and assurance gates internally contradictory.

## Decision

### Client allocation

- `apps/patient-app`: Flutter for Android and iOS only.
- `apps/doctor-desktop`: Electron with React and TypeScript.
- `apps/pharmacy-desktop`: Electron with React and TypeScript.
- `apps/admin-web`: browser-hosted React and TypeScript; it is not packaged in
  Electron and retains its HttpOnly-cookie/CSRF session model.

The existing Flutter doctor/pharmacy scaffolds are migration inputs, not the
target architecture. Their replacement is implementation work for the relevant
phase; this ADR does not authorize deletion of user code or local data.

### Electron process and dependency boundary

The corresponding component view is
[C4 Level 3 — Electron desktop trust boundary](../architecture/c4-component-electron-desktop.md).

```text
React renderer (unprivileged, local packaged assets)
  -> typed contextBridge capabilities
preload (small, isolated, validation only)
  -> allowlisted IPC commands/queries
main / optional utility process
  -> device credential, HTTPS/realtime, encrypted local store, file/print,
     notification, update, and other OS adapters
```

- Renderer windows use `nodeIntegration: false`, `contextIsolation: true`, and
  Chromium sandboxing. Production must not use `--no-sandbox`.
- Renderers load packaged local content only. Remote navigation, new windows,
  permission requests, downloads, external protocols, and `shell.openExternal`
  are denied by default and allowed only through validated, purpose-specific
  policies.
- Renderer assets use a privileged, standard-scheme custom application protocol
  with an exact production origin instead of inheriting broad `file://`
  privileges. Renderer session/cache configuration prevents clinical data from
  entering Chromium cookies, cache, IndexedDB, or service-worker state.
- The preload exposes one typed method per capability. It never exposes
  `ipcRenderer`, Node globals, filesystem/shell primitives, arbitrary channel
  names, arbitrary URLs, or arbitrary SQL.
- Every IPC request and response has a schema, maximum size, caller/window
  check, stable safe error, deadline/cancellation behavior, and test. Zod is the
  default TypeScript schema adapter unless Phase 00 approves an equivalent.
- Laravel remains authoritative for authentication decisions, authorization,
  domain transitions, idempotency outcomes, and every clinical/pharmacy fact.
  Electron main/preload code contains no business-rule bypass.
- Generated HTTP and Reverb clients execute behind main/utility ports using
  Electron networking or an approved nonpersistent session so operating-system
  proxy and TLS behavior is preserved. Renderer code never owns an authenticated
  HTTP or WebSocket connection.

### Credentials and local data

- Desktop device tokens and the wrapped encrypted-database key are accessible
  only outside the renderer. Electron `safeStorage` or an ADR-approved native
  keystore adapter protects them with the OS account facility.
- Sensitive local storage fails closed when strong OS encryption is unavailable.
  In particular, Electron's Linux `basic_text` backend is not acceptable for a
  device token, database key, or clinical draft.
- The doctor desktop may store only the Phase 05 transient encrypted draft and
  local outbox. The pharmacy desktop remains online-authoritative for stock,
  POS, payments, and financial mutations; any local cart is non-authoritative.
- An encrypted SQLite adapter built against SQLCipher or
  SQLite3MultipleCiphers is selected and pinned only after Windows, macOS, and
  Linux ABI, packaging, encryption, migration, rotation, corrupt/wrong-key,
  recovery, and backup-exclusion tests pass. Native database access stays
  outside the renderer and accepts intent-named operations rather than SQL.
- No local PHI is enabled until that spike and the Phase 05/22 gates pass.

### Tooling, distribution, and updates

- Electron, React, and strict TypeScript are the desktop baseline. Electron
  Forge with its maintained Webpack/TypeScript pipeline is the default build,
  packaging, and maker workflow. The admin web app remains on Vite. An
  Electron Vite plugin or a different packager requires a compatibility ADR
  covering native modules, CSP, packaged assets, signing, and every target OS.
- Desktop packages include `electron`, `@electron-forge/cli`, the approved
  Forge Webpack/fuses/auto-unpack-natives plugins and target makers,
  `@electron/fuses`, and `@electron/rebuild`. Renderer packages include React,
  React DOM, TanStack Query, React Router, React Hook Form, Zod, MUI, and
  i18next. OpenAPI uses `openapi-typescript` and `openapi-fetch` behind a
  main-owned transport.
- `@electron/fuses` disables unused privileged features before signing. Exact
  fuses are threat-modelled and verified on the packaged artifact.
- OpenAPI produces Dart for the patient mobile app and TypeScript for both
  Electron desktops and the admin web app. Generated DTOs remain at the
  transport edge.
- Desktop releases are built for the approved OS/architecture matrix, signed or
  notarized as required, SBOM-scanned, and tested as installed packages—not only
  through the development server.
- Update metadata and artifacts are authenticated and rollback-aware. Built-in
  Electron updating is limited to its supported platforms; Linux uses the
  approved distribution/package-manager channel. A renderer cannot select an
  update URL or trigger arbitrary installation.
- Electron/Chromium security updates are treated as runtime security work and
  pass compatibility/security regression before promotion.
- Vitest, React Testing Library, MSW, and axe cover pure renderer and contract
  behavior. WebdriverIO's Electron service is the default packaged-app E2E
  harness; Playwright's experimental Electron launcher may be adopted only
  after a pinned compatibility spike and never replaces installed-artifact OS
  tests.

## Consequences

### Positive

- Doctor and pharmacy desktop applications use one TypeScript/React ecosystem
  while retaining separate applications and authorization contexts.
- Renderer UI can reuse reviewed pure TypeScript utilities and design tokens
  without exposing Node.js or Electron privileges.
- Desktop-native printing, file selection, notifications, encrypted storage,
  signing, and updates have an explicit capability boundary.

### Negative / accepted cost

- The existing Flutter desktop scaffolds must be replaced and their CI lanes,
  generated clients, and tests migrated.
- Electron ships a Chromium/Node runtime that requires rapid security updates,
  a larger artifact, and stronger supply-chain controls.
- Native encrypted SQLite adapters must be rebuilt and tested against Electron's
  Node ABI for every supported target.
- React admin code cannot be copied wholesale into desktop apps: admin uses
  browser cookie/CSRF semantics, while desktop uses device-token and IPC
  boundaries.

## Alternatives considered

| Alternative | Why not selected |
| --- | --- |
| Keep Flutter Desktop | Superseded by the product owner's explicit desktop-stack decision. |
| Package the admin web application as both desktops | Conflates admin, clinical, and pharmacy authorization/data boundaries. |
| Load a remotely hosted web UI in Electron | Expands remote-content risk and weakens reproducible signed-artifact guarantees. |
| Tauri or another native web shell | Not the selected product stack; adopting it would require a new ADR and compatibility/security evaluation. |

## Migration and rollback impact

Forward migration replaces the unshipped doctor/pharmacy Flutter scaffolds with
independent Electron applications, adds them to the JavaScript workspace, and
regenerates TypeScript clients. No production desktop database is migrated by
assumption; if real local data exists by migration time, a separately tested
export/import or parallel-run plan is required.

Rollback before Electron production release restores the previous source
branch. After release, rollback means a signed compatible Electron artifact or
forward fix; it never downgrades the local database blindly or reintroduces a
Flutter desktop without an explicit superseding decision.

## Verification

- Architecture tests reject Node/Electron imports from renderer feature code
  except the generated typed bridge declaration.
- Packaged-window tests prove sandbox/context isolation, disabled Node
  integration, CSP, navigation/new-window/permission denial, and no raw IPC.
- IPC contract tests cover schema rejection, oversized messages, wrong sender,
  cancellation, timeout, duplicate intent, and safe error serialization.
- Windows, macOS, and Linux installed-package tests cover credential storage,
  encrypted database lifecycle, printing/files, realtime reconnect, signing,
  update provenance, and uninstall/backup behavior.
- Generated Dart and TypeScript clients pass the same OpenAPI contract suite.
- Phase 22 independently verifies Electron security and Phase 23 verifies signed
  distribution, update, rollback, and local-data compatibility.

## Review requirement

Engineering and product accept the stack allocation. Security and privacy must
approve the IPC, keystore, encrypted-storage, signing, and update evidence before
local clinical data or production desktop distribution is enabled.
