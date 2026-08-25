# Phase 00 — open security findings

Findings that ADR 0008's vulnerability policy blocks on and that engineering
**cannot self-approve**. Each needs a security owner, a stated compensating
control, and an expiry date before it can be accepted.

---

## SF-001 — `tar` advisories in the Electron build toolchain

- **Severity:** critical (1) plus high (25 related Forge packages)
- **Status:** OPEN — blocks merge under ADR 0008 until a security owner rules
- **Discovered:** 2026-08-25, during the Electron migration
- **Advisory ceiling:** every listed advisory requires `tar >= 7.5.21`; the
  highest affected range is `<= 7.5.20` (GHSA-r292-9mhp-454m). The critical is
  GHSA-23hp-3jrh-7fpw, decompression/parse denial of service via unlimited input.

### What is actually installed

```
node_modules/@electron/node-gyp/node_modules/tar          6.2.1
node_modules/@electron-forge/shared-types/node_modules/tar 6.2.1
node_modules/@electron-forge/core-utils/node_modules/tar   6.2.1
node_modules/@electron-forge/core/node_modules/tar         6.2.1
node_modules/cacache/node_modules/tar                      6.2.1
```

`@electron-forge/*@7.11.2` pins `@electron/rebuild@3.7.2` internally, which
depends on `tar@^6`. Our own direct `@electron/rebuild` is already 4.2.0; the
vulnerable copies are Forge's nested ones.

### What was tried

An npm `overrides` entry (`"tar": "^7.5.22"`) is present in the root
`package.json`. **It does not fix these five paths.** npm installs the override
at the top level and marks the nested copies `invalid ... overridden`, but the
physical `tar@6.2.1` directories remain and Node resolves nested
`node_modules` first. The override is retained because it does pin any
root-resolving consumer, but it must not be read as a fix for this finding.

`@electron-forge/cli@7.11.2` is the latest published version. There is no
upstream release that resolves this today. `npm audit fix` proposes downgrading
Forge to 7.6.1, which is a semver-major downgrade that does not fix the issue.

### Compensating controls that genuinely apply

These are offered as input to the security owner's decision, not as a
self-approval:

1. **Build-time only.** `tar` is a `devDependency` used to extract the Electron
   binary during packaging. It is not present in the packaged application and
   will not appear in the runtime SBOM.
2. **Trusted input.** The archives extracted are Electron release artifacts
   fetched over TLS from a known CDN with checksum verification, not
   attacker-supplied files.
3. **Ephemeral builders.** CI runs on disposable runners; the blast radius of
   arbitrary file write during a build is one throwaway workspace.
4. **No signing credentials in the affected lane.** Phase 00 CI produces
   unsigned artifacts and never receives certificates, so a compromised build
   step cannot produce a signed malicious artifact.

None of this makes the finding go away. "It is only a dev dependency" is
precisely the reasoning behind several real build-chain compromises, and the
control that matters most — a fixed upstream — does not exist yet.

### Required decision

A security owner must choose one:

- **Accept with expiry.** Record a time-boxed exception naming the compensating
  controls above and a review date, per ADR 0008. The exception must fail the
  build when it expires.
- **Vendor or patch.** Apply a patch-package-style fix to the nested
  `@electron/rebuild` dependency range.
- **Change tooling.** Move off Electron Forge, which ADR 0010 makes a
  compatibility-ADR decision covering native modules, CSP, packaged assets,
  signing, and every target OS.

Until then the Electron CI lane must be treated as failing its dependency gate.

### Tracking

Evidence ledger gate G-06-05. Do not mark that gate `PASS` while this is open.
