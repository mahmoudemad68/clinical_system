# P02-AUDIT-006 / SF-001 Option A — packaging STOP

Controller-authorized Option A applied:

- root `@electron/packager` override `20.3.0` (Forge `7.11.2` retained)
- `tests/desktop-e2e` `@puppeteer/browsers` override `3.2.3` (WDIO 9.x retained)

Unscoped `extract-zip@2.0.1` is absent from both npm lockfiles after clean
`npm ci`. `@electron-internal/extract-zip@1.0.5` remains and is a different
package.

## Compatibility stop

Forge `7.11.2` cannot package Doctor or Pharmacy with Packager `20.3.0`.

Both `npm run package --workspace apps/doctor-desktop` and
`npm run package --workspace apps/pharmacy-desktop` fail at
**Finalizing package** with:

```
TypeError: done is not a function
at node_modules/@electron-forge/core/dist/api/package.js:76:13
```

Cause: `@electron-forge/core@7.11.2` still supplies callback-style Packager
hooks (`(targets, done) => { ...; done(); }` via
`sequentialFinalizePackageTargetsHooks`). `@electron/packager@20.3.0`
`runHooks` calls `hook(opts)` with a single options object and no `done`
callback.

This is a Forge 7 ↔ Packager 19+/20 hook-API break, not an SF-001
ignore-file issue.

## Authorization boundary

Forge 8 / Forge 8 alpha, WDIO major, Electron major, and broad npm
modernization are **not** authorized.

No Forge 8 upgrade was attempted.

```
FORGE8_OR_ARCHITECTURAL_MIGRATION_REQUIRES_SEPARATE_AUTHORIZATION
```

## What this branch is not

This is **not** `SF001_REMEDIATED_CANDIDATE_AWAITING_INDEPENDENT_QA`.
Packaging, Forge practice E2E, packaged Electron E2E, and ASAR inspection
did not complete because Forge package failed.

P02-AUDIT-006 remains **OPEN**.
SF-001 remains **OPEN** (historical High; Option A graph removal is not
shippable under Forge 7.11.2).
