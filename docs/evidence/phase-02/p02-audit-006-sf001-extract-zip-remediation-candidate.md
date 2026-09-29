# P02-AUDIT-006 / SF-001 — extract-zip graph-removal candidate

Engineering evidence for **P02-AUDIT-006 / SF-001** Option A. This file
records a **remediation candidate**. It does **not** independently accept
SF-001, does **not** close P02-AUDIT-006, does **not** waive
CVE-2026-56876 / GHSA-jmr9-qjv8-65gv, and does **not** mark Phase 02 PASS.

Historical fact: SF-001 existed as High `extract-zip@2.0.1` with a
time-boxed `MERGE_ONLY` exception (`promotion_allowed=false`,
`independent_acceptance_status=PENDING_INDEPENDENT_ACCEPTANCE`). That
exception is **historical**. It must not be used to ignore the advisory
on merge.

| Field | Value |
| --- | --- |
| Finding | SF-001 |
| Advisory | CVE-2026-56876 / GHSA-jmr9-qjv8-65gv |
| Historical package | `extract-zip@2.0.1` |
| Historical exception | `MERGE_ONLY` (merge permitted; promotion blocked) |
| Authorized path | Option A parent overrides |
| Root override | `@electron/packager` `20.3.0` (Forge `7.11.2` retained) |
| desktop-e2e override | `@puppeteer/browsers` `3.2.3` (WDIO 9.x retained) |
| Graph status | unscoped `extract-zip` **ABSENT** from both npm lockfiles |
| Distinct package | `@electron-internal/extract-zip` may remain; it is not `extract-zip` |
| Merge ignore | CVE/GHSA IDs **removed** from `infra/security/trivy-merge.ignore` |
| ISR-015 | fails if `extract-zip` reappears in either lockfile or if the ignore is restored |
| Independent acceptance | `PENDING_INDEPENDENT_ACCEPTANCE` |
| **P02-AUDIT-006** | **OPEN** |
| SF-001 | `REMEDIATED_CANDIDATE_AWAITING_INDEPENDENT_QA` |
| Promotion blocked specifically by SF-001 merge exception | **no** (old ignore is gone; package is absent). Remaining hold is independent QA plus unrelated open gates. |

## STOP — Forge 7.11.2 cannot package with Packager 20.3.0

Doctor and Pharmacy `electron-forge package` both fail at Finalizing
package:

`TypeError: done is not a function` in
`@electron-forge/core@7.11.2` `package.js:76`
(`sequentialFinalizePackageTargetsHooks`). Packager 20 invokes promise
hooks as `hook(opts)` and does not pass a `done` callback.

Forge 8 was **not** attempted.

See [p02-audit-006-sf001-option-a-packaging-stop.md](p02-audit-006-sf001-option-a-packaging-stop.md).

This implementer cannot close SF-001. Independent QA must verify the
graphs, Forge 7 + Packager 20 packaging, WDIO + Browsers 3 E2E, Trivy,
and exact-head CI before any acceptance decision.

```
FORGE8_OR_ARCHITECTURAL_MIGRATION_REQUIRES_SEPARATE_AUTHORIZATION
```

