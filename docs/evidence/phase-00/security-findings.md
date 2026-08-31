# Phase 00 — open security findings

Findings ADR 0008's vulnerability policy blocks on. Engineering cannot
self-approve any of them.

**Policy, quoted exactly** ([ADR 0008](../../adr/0008-package-and-version-policy.md)):

| Severity | Action |
| --- | --- |
| Critical | Blocks merge and blocks promotion |
| High | Blocks promotion; merge allowed only with a recorded, time-boxed exception |

There is **no exception path for a Critical finding.** Accepting one would
require a superseding ADR changing the policy itself, and Phase 22 §176 keeps
the "no critical security findings" release rule regardless.

---

## SF-001 — `extract-zip` symlink path traversal in the Electron build toolchain

- **Severity:** high (1 true root; 20 packages flagged along transitive paths)
- **Status:** OPEN — blocks **promotion**. Merge is permissible only with a
  recorded, time-boxed exception under ADR 0008.
- **Advisory:** [GHSA-…](https://github.com/advisories) *extract-zip unvalidated
  symlink path traversal*, affected range `<= 2.0.1`
- **Discovered:** 2026-08-25 · **Superseded diagnosis:** 2026-08-25 (see below)

### Correction to the original diagnosis

**The first version of this finding was wrong and is retained here as a
correction rather than quietly rewritten.**

It claimed a Critical `tar` advisory that could not be fixed because "Forge
7.11.2 pins `@electron/rebuild@3.7.2` internally" and because npm `overrides`
"provably does not reach the five nested copies". Three errors:

1. **Forge does not pin.** `@electron-forge/core`, `core-utils`, and
   `shared-types` at 7.11.2 all declare `@electron/rebuild: ^3.7.0` — a range.
   The lockfile selected 3.7.2.
2. **The override does work.** A clean install (`rm -rf node_modules
   package-lock.json && npm install`) under the project's required toolchain
   resolves the entire tree to a single `tar@7.5.22`. The five nested
   `tar@6.2.1` copies were **stale dependency-tree and lockfile
   reconciliation**, not a Forge limitation. npm documents root `overrides` as
   the supported mechanism for replacing a transitive dependency.
3. **The toolchain mattered.** Some earlier installs ran on the default shell's
   Node 20.20.2 / npm 10.8.2, below this project's `engines` requirement of
   Node ≥ 22.12 and npm ≥ 10.9. The repair must run on the recorded
   Node 22.23.2 / npm 10.9.8.

The severity classification was also misleading: 25 packages were reported as
"high" when they were transitive paths through a small number of roots, not
independent findings.

### Verified current state

Reproduced on Node 22.23.2 / npm 10.9.8, clean `npm ci` from the committed lock:

```
tar                 7.5.22   (single copy)
tmp                 0.2.7    (single copy)
uuid                11.1.1   (single copy)
webpack-dev-server  5.2.6    (single copy)
extract-zip         2.0.1    (single copy — no fix published)
```

| Severity | Before | After |
| --- | ---: | ---: |
| Critical | 1 | **0** |
| High | 25 flagged / 1 root | 20 flagged / **1 root** |
| Moderate | 3 | **0** |
| Low | 3 | **0** |

Root overrides now in the root `package.json`:

```json
"overrides": {
  "tar": "^7.5.22",
  "tmp": "^0.2.7",
  "uuid": "^11.1.1",
  "webpack-dev-server": "^5.2.6"
}
```

`webpack-dev-server` is pinned to `^5.2.6` rather than the 6.0.0 latest
deliberately: `@electron-forge/plugin-webpack@7.11.2` declares `^4.0.0`, and
5.2.6 clears every advisory without forcing a two-major jump on the packaging
toolchain.

### The one remaining root

`extract-zip@2.0.1`, reached through `@electron/packager` → Electron Forge.
**2.0.1 is the latest published version and the advisory covers `<= 2.0.1`.**
There is no version to upgrade to. An override cannot fix what has no fix.

`npm audit` proposes `@electron-forge/cli@6.4.2`, a semver-major *downgrade*
that does not resolve it.

### Compensating controls (input to the decision, not a self-approval)

1. **Build-time only.** `extract-zip` unpacks the Electron binary during
   packaging. It is a `devDependency`, is absent from the packaged application,
   and will not appear in the runtime SBOM.
2. **Trusted input.** The archives extracted are Electron release artifacts
   fetched over TLS from a known CDN with checksum verification — not
   attacker-supplied zips.
3. **Ephemeral builders.** CI runs on disposable runners, so the blast radius
   of a symlink write during a build is one throwaway workspace.
4. **No signing credentials in the affected lane.** Phase 00 CI produces
   unsigned artifacts and never receives certificates, so a compromised build
   step cannot emit a signed malicious artifact.

"It is only a dev dependency" is how real build-chain compromises happen, so
none of the above closes the finding. They are the facts the owner needs.

### Required decision (security owner)

High severity, so ADR 0008 permits an exception for **merge** with an owner, a
compensating control, and an expiry that fails the build when reached.
**Promotion remains blocked** until the finding is resolved or the policy is
changed by a superseding ADR.

Options:

- **Time-boxed exception for merge**, with a review date and a watch on
  upstream `extract-zip` / `@electron/packager`.
- **Vendor or patch** the dependency.
- **Change packaging tooling**, which ADR 0010 makes a compatibility-ADR
  decision covering native modules, CSP, packaged assets, signing, and every
  target OS.

### Recorded time-boxed merge exception (ADR 0008)

Authority is
[`infra/security/exceptions/SF-001.json`](../../../infra/security/exceptions/SF-001.json),
not this paragraph. Merge of this High finding is permitted only while that
manifest remains unexpired, `scope: MERGE_ONLY`, and
`promotion_allowed: false`. Independent acceptance is
`PENDING_INDEPENDENT_ACCEPTANCE`. Assessor/remediator separation is lost;
this exception is not independent approval. Promotion remains blocked.

### Canonical exception (does not close the finding)

The merge-only exception is the machine-readable manifest
[`infra/security/exceptions/SF-001.json`](../../../infra/security/exceptions/SF-001.json).
That file is the authority for IDs, `extract-zip@2.0.1`, `MERGE_ONLY` scope,
`promotion_allowed: false`, UTC expiry, and
`independent_acceptance_status: PENDING_INDEPENDENT_ACCEPTANCE`.
`infra/security/trivy-merge.ignore` may list only the same IDs. Independent
acceptance is **not** recorded here.

Post-merge `promotion-fs-scan` runs the **same** Trivy filesystem scan **without**
that ignore file and with `exit-code: 1`. Image scans never use the merge
ignore and never set `ignore-unfixed`. SF-001 therefore still blocks promotion.

This implementer cannot close SF-001. Independent retest remains required (G-08-04 OPEN).

### Tracking

Evidence ledger gate G-06-05. It is `PARTIAL`, not `PASS`, and not `BLOCKED`:
the Critical is resolved, one High root remains with no upstream fix.
