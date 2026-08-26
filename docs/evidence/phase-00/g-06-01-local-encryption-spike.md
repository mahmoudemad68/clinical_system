# G-06-01 — Client local-encryption compatibility spike

Condition of [ADR 0006](../../adr/0006-client-local-encryption.md) and
[ADR 0010](../../adr/0010-electron-react-typescript-desktop-clients.md). Until
this gate is `PASS` on **all five** target platforms, no client may write
clinical content to local storage.

- **Recorded:** 2026-08-26
- **Host:** Linux x64 (this machine). Windows, macOS, Android, and iOS were
  **not executed**.
- **Result:** `PARTIAL`

## What closed on this host

| Check | Linux Electron (Node 22.23.2 / Electron 44.0.0 ABI tests in Node) | Linux Dart (`sqlite3` 3.5.2, `source: sqlite3mc`) |
| --- | --- | --- |
| File bytes do not contain the canary `SYNTHETIC_SPIKE_CANARY_v1` | PASS (`@clinic/encrypted-local-store` vitest) | PASS (`clinic_local_database` flutter test) |
| Key rotation preserves existing rows | PASS | PASS |
| Wrong key fails closed | PASS | PASS |
| Missing wrap does not mint a new key over ciphertext | PASS | n/a (key is supplied by the caller; wrap lives in `clinic_secure_storage`) |
| Linux `basic_text` / weak keystore refuses persistence | PASS (policy unit tests + main-process probe) | PASS (`KeystoreUnavailable`) |
| Backup-exclusion flags encoded | PASS (plan + command argv) | PASS (`migrateWithBackup=false`, iOS/macOS `synchronizable=false` + `first_unlock_this_device`; patient `android:allowBackup="false"`) |
| EOL `sqlcipher_flutter_libs` absent | n/a | PASS (lockfile assertion) |

Pinned native stack:

- Desktop: `better-sqlite3-multiple-ciphers@13.0.3` (SQLite3MultipleCiphers,
  SQLCipher-compat `cipher='sqlcipher'` + `legacy=4`).
- Patient mobile package: `sqlite3@3.5.2` with workspace hook
  `source: sqlite3mc`. Not `sqlcipher_flutter_libs`.
- Key wrapping: Electron `safeStorage` policy in main; Flutter
  `flutter_secure_storage@11.0.0` with backup-hostile options.

Synthetic canary only. No clinical draft, outbox, or PHI row is written by
either desktop main process or the patient app.

## Platform matrix

| Target | Runtime | Executed here | Notes |
| --- | --- | --- | --- |
| Linux | Electron doctor + pharmacy | **yes** (cipher + policy tests; webpack main/renderer compile) | `safeStorage` `basic_text` is fail-closed. Packaged artifact: see below. |
| Linux | Dart sqlite3mc (package tests) | **yes** | Proves the Flutter encryption hook on this OS, not a product Linux Flutter app. |
| Windows | Electron | **no** | Code + attrib `+U` plan exist. Needs a Windows runner. |
| macOS | Electron | **no** | Code + `xattr` backup-exclude plan exist. Needs a macOS runner. |
| Android | Flutter patient | **no device / no SDK on this host** | Options and `allowBackup=false` are in tree. Needs `flutter test` / APK on Android. |
| iOS | Flutter patient | **no Xcode** | Keychain options are in tree. Needs an iOS simulator or device. |

## Commands (reproducible on this host)

```bash
export PATH="/home/mahmoud/sdk/node-v22.23.2-linux-x64/bin:/home/mahmoud/sdk/flutter/bin:$PATH"
npm run test --workspace @clinic/encrypted-local-store
npm run typecheck --workspace @clinic/encrypted-local-store
npm run desktop:typecheck
npm run desktop:test
cd packages/flutter/secure_storage && dart analyze --fatal-infos --fatal-warnings && flutter test
cd packages/flutter/local_database && dart analyze --fatal-infos --fatal-warnings && flutter test
cd apps/patient-app && flutter test test/backup_exclusion_test.dart
```

## What is still forbidden

- Doctor desktop must not persist clinical drafts until this gate is `PASS`
  (Phase 05).
- Pharmacy remains online-authoritative; no local stock/POS database.
- Patient app must not persist clinical rows; `clinic_local_database` is a
  spike package, not wired into the app.

## Packaged Electron artifact

Linux x64 unsigned Forge packages were produced on this host:

- `apps/doctor-desktop/out/Clinic Doctor-linux-x64/` (gitignored)
- `apps/pharmacy-desktop/out/Clinic Pharmacy-linux-x64/`

Webpack main + renderer compile succeeded after adding the Forge webpack
loaders (`ts-loader` 9.6.2, `node-loader` 2.1.0, `css-loader` 7.1.4,
`style-loader` 4.0.0, `@vercel/webpack-asset-relocator-loader` 1.11.0).

`better-sqlite3-multiple-ciphers` was rebuilt for Electron 44 and a canary
write was executed with `ELECTRON_RUN_AS_NODE=1` against Electron 44.0.0
(`NODE_MODULE_VERSION` 149): `electron-abi-canary-ok`. The packaged asar does
not load the addon at process start — Phase 00 still refuses clinical writes.

Signing is absent (Phase 23). Installed-artifact WebdriverIO and fuse
inspection remain G-02-10.
