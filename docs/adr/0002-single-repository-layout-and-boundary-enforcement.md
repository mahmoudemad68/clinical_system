# ADR 0002 — Single repository, independent deployment units, enforced boundaries

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, devops, backend, mobile, desktop, frontend
- **Phase:** 00
- **Supersedes / Superseded by:** Desktop-client portions superseded by [ADR 0010](0010-electron-react-typescript-desktop-clients.md)

## Context

The system has six deployment units: one Flutter mobile client, two Electron
desktop clients, one React admin, the Laravel core, and the FastAPI AI service.
(`plan.md` section 1 named three Flutter clients; [ADR 0010](0010-electron-react-typescript-desktop-clients.md)
superseded the desktop pair.) Every one of them
consumes the same HTTP and event contracts. A contract change that lands in four
repositories at four different times produces a window in which a generated
client disagrees with the server.

The phase file requires that "contracts and cross-client changes can be atomic
while deployment units remain independent."

## Decision

One git repository holds all deployment units and shared contracts:

```text
apps/        core-api, ai-service, admin-web, patient-app, doctor-desktop, pharmacy-desktop
packages/    flutter/*     shared Dart packages (patient mobile only)
             typescript/*  shared TS packages (admin web + both Electron desktops)
             contracts/    openapi, events, ai_internal
infra/       docker, environments, monitoring, load-tests
docs/        adr, architecture, data-classification, runbooks, threat-models, evidence, phases
```

Deployment units stay independently buildable and independently deployable. No
unit imports another unit's source. Sharing happens only through
`packages/contracts/` (schemas and generated clients), `packages/flutter/`
(Dart packages consumed by the patient mobile app through the Melos
workspace), and `packages/typescript/` (pure TypeScript packages consumed by
the admin web app and both Electron desktops through npm workspaces).

The TypeScript sharing is deliberately narrow. Admin authenticates with an
HttpOnly cookie and CSRF; the desktops authenticate with a device token held in
the Electron main process. Only pure types, design tokens, localization
helpers, error mapping, and generated contracts cross that line — never a
transport, a session, or a storage adapter (ADR 0010).

Workspace and dependency management per language:

| Language | Manager | Committed lock |
| --- | --- | --- |
| PHP | Composer | `composer.lock` |
| JavaScript / TypeScript | npm workspaces | `package-lock.json` |
| Dart / Flutter | Melos + pub workspace | one root `pubspec.lock` |
| Python | `pyproject.toml` + pip-tools | `requirements*.txt` (hashed) |

## Consequences

### Positive

- A contract change and every consumer update land in one reviewable commit.
- CI can regenerate clients and fail the same pull request that broke them.
- CODEOWNERS expresses clinical, pharmacy-financial, identity, infrastructure,
  and AI-safety review rules in one file.

### Negative / accepted cost

- Clone size grows with all six units, the Flutter mobile platform folders,
  and two Electron applications.
- CI must use path filters, or every pull request runs every suite.
- A single repository tempts direct cross-unit imports; this needs enforcement.

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| A client imports core PHP or another app's source | Path-scoped lint and dependency-boundary rules: `deptrac` for PHP, analyzer rules for Dart, and Semgrep boundary rules plus webpack `fallback: false` for the Electron renderer |
| A shared TypeScript package collapses the two desktops' security contexts | Every identity namespace is per-app in `src/shared/app-config.ts`, asserted by a test in each application (Phase 00 §2.3) |
| CI runtime grows until it is bypassed | Per-unit path filters; only changed units run their full suite, while contract validation always runs |
| Lockfile drift between environments | Every install in CI uses the frozen/`ci` install mode; a dirty lockfile fails the build |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| One repository per deployment unit | Cross-client contract changes stop being atomic; a breaking OpenAPI change cannot be proven against consumers in the same pull request |
| Monorepo with a shared build tool (Bazel/Nx) across all four languages | Cost far exceeds benefit at six units; each ecosystem's native tooling is better supported and better understood by its owners |
| Shared runtime library between Laravel and FastAPI | Reintroduces the coupling ADR 0001 removes |

## Migration and rollback impact

Forward: initial layout. Splitting a unit into its own repository later requires
moving its directory plus its CI lane, and pinning it to a published contract
version instead of the in-repo path.

Rollback: not applicable.

## Verification

- `deptrac` runs in CI against `apps/core-api/deptrac.yaml`.
- A deliberately forbidden-dependency fixture proves the check fails, then the
  fixture is removed (Phase 00 §1.5). Evidence gate G-01-05.
- `npm ci`, `composer install --no-interaction`, and hashed `pip install`
  reproduce byte-identical dependency sets from committed locks.

## Review requirement

Engineering. Devops for CI path filters.
