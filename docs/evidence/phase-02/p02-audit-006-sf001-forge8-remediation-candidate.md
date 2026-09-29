# P02-AUDIT-006 / SF-001 — Forge 8 extract-zip remediation candidate

Controller-authorized **Forge `8.0.0-alpha.10`** candidate. Draft. **Not**
independent QA. **Not** merge. **Not** SF-001 acceptance.

## Freshness

- Base main: `03ca1d64685647d31f7358cbc0865f040235d0d7`
- Tree: `c1844868d2ccafe6f2dee226a1ffe17c00fce144`

## Graph

| Item | Pin / resolution |
| --- | --- |
| Direct Forge packages (Doctor + Pharmacy) | `8.0.0-alpha.10` |
| `@electron-forge/core` | `8.0.0-alpha.10` |
| `@electron/packager` | declared `^20.0.1`, lockfile `20.3.0` |
| Packager override | **absent** |
| `@electron/fuses` | `2.0.0` (plugin-fuses peer `^2.0.0`) |
| `electron` | `44.0.0` |
| WDIO | `9.31.3` / `@wdio/electron-service@10.2.0` |
| `@puppeteer/browsers` (desktop-e2e override) | `3.2.3` |
| Unscoped `extract-zip` | **ABSENT** from both npm lockfiles |
| `@electron-internal/extract-zip` | may remain; different npm package |

Failed Option A (PR #35, Forge 7.11.2 + undeclared Packager 20.3.0) remains
historical evidence of `TypeError: done is not a function` during Finalizing
package. That PR is closed without merge (`af77d21`); its branch is preserved.

Linux `electron-forge package` for Doctor and Pharmacy reached
`Finalizing package` without that TypeError. Linux Deb `make` succeeded after
setting MakerDeb `bin` to the existing `executableName` (`clinic-doctor` /
`clinic-pharmacy`). `electron-installer-common` otherwise defaults `bin` to
the scoped npm package name (`@clinic/doctor-desktop`), which is not the
Packager 20 binary.

Native `better-sqlite3-multiple-ciphers` rebuild ran during package
(`Preparing native dependencies`). Forge Webpack still packs only `.webpack`;
packaged `app.asar` has an empty `node_modules` and no `.node` (same ignore
architecture as Forge 7). `@clinic/encrypted-local-store` unit tests load the
addon (16 passed). Phase 00 packaged startup does not import the addon.

Unscoped `extract-zip` is absent from both packaged ASARs. Debian packages
keep `chrome-sandbox` as root SUID (`-rwsr-xr-x`). Unpackaged Forge output
leaves that helper `0755` until the existing Linux E2E sandbox helper.

## Security gate

ISR-015 proves absence of unscoped `extract-zip` in:

- `package-lock.json`
- `tests/desktop-e2e/package-lock.json`

CVE-2026-56876 / GHSA-jmr9-qjv8-65gv are not listed in
`infra/security/trivy-merge.ignore`. SF-001 remains OPEN pending independent
QA. P02-AUDIT-006 remains OPEN.

## Explicit non-claims

- Not security acceptance of SF-001
- Not Profile Claim / T46 / P02-AUDIT-005 / P02-AUDIT-007 / G-08-04 changes
- Not Vite, not Electron major, not WDIO major
- Forge 8 alpha is an authorized engineering-risk tooling migration
