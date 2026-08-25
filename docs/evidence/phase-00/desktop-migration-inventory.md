# Desktop migration inventory — Flutter Desktop to Electron

Required by Phase 00 §2.1: *"Inventory the current doctor/pharmacy Flutter
scaffolds, shared-package imports, CI paths, application identifiers, signing
placeholders, and any user-authored or local-data migration need. Record the
disposition of every item before replacement; never silently delete a real
desktop database."*

Authority: [ADR 0010](../../adr/0010-electron-react-typescript-desktop-clients.md).

- **Recorded:** 2026-08-25, before any file was removed
- **Migration commit:** the single reviewed commit that follows this record
- **Scope:** `apps/doctor-desktop`, `apps/pharmacy-desktop` only. `apps/patient-app`
  and every patient-owned Dart package stay in the Melos workspace.

---

## 1. Local data and user-authored code

This is the question the phase file singles out, so it is answered first.

| Check | Result |
| --- | --- |
| `*.db`, `*.sqlite*`, `*.sql` under either app | **none found** |
| Encrypted local store configured | **no** — ADR 0006's spike (G-06-01) never closed, so no build was ever permitted to write clinical content locally |
| Application ever packaged, signed, or distributed | **no** |
| User-authored Dart beyond the generated health slice | **no** — `lib/main.dart` only, written in the Phase 00 foundation commit |
| Real desktop database at risk | **no** |

**Disposition: safe to replace.** No production desktop database exists, no
export/import or parallel-run plan is required, and no user data is destroyed.
Had any of the above been present, ADR 0010's migration clause would require a
separately tested export/import plan before proceeding.

## 2. Files being removed

| Path | Content | Disposition |
| --- | --- | --- |
| `apps/doctor-desktop/lib/main.dart` | Generated health slice (Riverpod + shared packages) | Replaced by the Electron renderer equivalent |
| `apps/doctor-desktop/pubspec.yaml` | Dart manifest, workspace member | Replaced by `package.json` |
| `apps/doctor-desktop/{linux,macos,windows}/` | Flutter desktop runner scaffolding | Removed; Electron Forge owns packaging |
| `apps/doctor-desktop/analysis_options.yaml` | Dart lint config | Replaced by ESLint |
| `apps/doctor-desktop/.dart_tool/`, `*.iml`, `.idea/` | Generated tool state | Removed, not tracked |
| `apps/pharmacy-desktop/**` | Same structure, 78 files | Same disposition |

## 3. Shared Dart package imports to sever

Both desktop apps imported six Dart packages:

| Package | Still needed by patient app? | Desktop replacement |
| --- | --- | --- |
| `clinic_api_client` | yes | `packages/typescript/api_client` |
| `clinic_common_models` | yes | Types generated from OpenAPI + `packages/typescript/api_client` |
| `clinic_design_system` | yes | `packages/typescript/design_tokens` (tokens only; no shared widgets across runtimes) |
| `clinic_error_handling` | yes | `packages/typescript/error_handling` |
| `clinic_localization` | yes | `packages/typescript/localization` |
| `clinic_networking` | yes | Desktop transport lives in the Electron **main** process, not a shared package |

Every one of these packages **stays** in the Melos workspace: the patient mobile
app still consumes all six. Only the desktop dependency edges are severed.

Note the asymmetry, which is deliberate: `clinic_networking` gets no TypeScript
twin. On desktop the authenticated transport belongs to the main process behind
an IPC capability (ADR 0010), so a shared renderer-importable networking package
would be an invitation to violate exactly the boundary this migration exists to
create.

## 4. Application identifiers

Flutter scaffolds used `eg.clinic` with the app name as suffix. Phase 00 §2.3
requires the two Electron apps to differ across **every** security-relevant
namespace, so a shared TypeScript package cannot collapse their contexts:

| Dimension | doctor-desktop | pharmacy-desktop |
| --- | --- | --- |
| Application ID | `eg.clinic.doctor.desktop` | `eg.clinic.pharmacy.desktop` |
| Product name | Clinic Doctor | Clinic Pharmacy |
| Executable | `clinic-doctor` | `clinic-pharmacy` |
| User-data directory | `clinic-doctor` | `clinic-pharmacy` |
| Protocol scheme | `clinic-doctor://` | `clinic-pharmacy://` |
| App asset protocol | `clinic-doctor-app://` | `clinic-pharmacy-app://` |
| Encrypted DB namespace | `doctor.encrypted.v1` | `pharmacy.encrypted.v1` |
| Device-credential namespace | `eg.clinic.doctor.device` | `eg.clinic.pharmacy.device` |
| IPC capability registry | `doctorCapabilities` | `pharmacyCapabilities` |
| Update channel | `doctor-stable` | `pharmacy-stable` |

## 5. Signing placeholders

`macos/Runner.xcodeproj/project.pbxproj` in both apps carried Xcode's default
empty `CODE_SIGN_IDENTITY`. Nothing was ever configured with a real identity.

**Disposition:** removed with the scaffold. Electron signing and notarization
are owned by `clinic-production-dr-release` in Phase 23; Phase 00 CI produces
unsigned verification artifacts only and must not receive signing credentials.

## 6. CI paths and configuration to update

| Item | Change |
| --- | --- |
| `pull-request.yaml` `flutter` filter | Narrow to `apps/patient-app` + `packages/flutter` |
| `pull-request.yaml` new `desktop` job | Add, filtered on `apps/*-desktop` + `packages/typescript` |
| CODEOWNERS | Desktop paths move from mobile/desktop Dart owners to `@clinic/desktop` |
| Root `pubspec.yaml` workspace list | Drop both desktop entries |
| Root `package.json` workspaces | Add both desktop apps and `packages/typescript/*` |
| `contracts:generate:ts` | Emit to shared TypeScript package consumed by admin and both desktops |
| Compose launch helpers | No change: desktop apps are not containerized |
| Evidence templates | Add Electron gates G-02-06 through G-02-09 |

## 7. What this migration does not do

- Does not implement encrypted local storage. ADR 0006's spike is still open and
  ADR 0010 forbids local PHI until it and the Phase 05/22 gates pass.
- Does not configure signing, notarization, or updates (Phase 23).
- Does not add clinical or pharmacy behavior. Phase 00 ships a health slice.
- Does not share renderer components between admin and desktop. Admin uses
  cookie/CSRF; desktop uses device tokens behind IPC. Only pure tokens,
  localization helpers, error types, and generated contracts are shared.
