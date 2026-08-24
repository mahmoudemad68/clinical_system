# ADR 0008 — Package selection and version policy

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, devops, security, all stack owners
- **Phase:** 00

## Context

The phase file states: "Versions are resolved during implementation against
Laravel 13 and the current supported Flutter/React/Python runtimes, then pinned
in lockfiles. Do not copy unverified version ranges from this document."
`docs/phases/README.md` adds that package names in later phases express intended
capability and do not authorize floating versions.

The system is a healthcare platform. A dependency that changes behavior between
a CI run and a production deploy is an unreviewed change to a system holding
clinical and financial records.

## Decision

**Resolution.** Package names in `plan.md` and phase files are capability
requests. The exact version is resolved at implementation time against the
registry, checked for compatibility with the target runtime, and pinned.

**Pinning.** Every deployment unit commits a lockfile. CI installs in frozen
mode; a lockfile that would change during install fails the build.

**Container images.** Base images are referenced by digest, not by tag alone. A
tag may accompany a digest for readability, but the digest is what builds.

**Artifacts.** Images and artifacts are built once and promoted unchanged
between environments. Staging and production never rebuild from source.

**SBOM.** Every deployment unit generates a Syft-compatible SBOM at build time,
retained with the artifact.

**Vulnerability policy.**

| Severity | Action |
| --- | --- |
| Critical | Blocks merge and blocks promotion |
| High | Blocks promotion; merge allowed only with a recorded, time-boxed exception |
| Medium | Tracked with an owner and a due date |
| Low / informational | Recorded |

An exception requires a security owner, a stated compensating control, and an
expiry date. An expired exception blocks the build.

**Deprecated and end-of-life packages.** A package that is end of life is not
adopted. The phase file names `sqlcipher_flutter_libs` as one such package
(see ADR 0006). Discovering that an in-use package became end of life opens a
replacement task, not an exception.

**Updates.** Automated dependency update pull requests run the full suite. A
major version bump requires an owner and a compatibility check, never an
auto-merge.

## Consequences

### Positive

- The dependency set in production is the one that passed CI.
- Provenance is recorded per artifact.
- Vulnerability response has a defined blocking policy rather than a judgment
  call under deadline pressure.

### Negative / accepted cost

- Frozen installs mean dependency updates are explicit work.
- Digest pinning makes base-image updates a deliberate change.
- Blocking on critical findings will occasionally block an unrelated release.

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| Pinning leads to stale, unpatched dependencies | Scheduled update pull requests plus the vulnerability policy's due dates |
| An exception becomes permanent | Exceptions expire and an expired exception fails the build |
| A transitive dependency changes under a loose constraint | Lockfiles cover the full transitive set; frozen install is enforced |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Caret/tilde ranges resolved at deploy | Production runs code no one reviewed |
| Copying versions from `plan.md` | The phase file explicitly forbids it; those ranges were never verified against the target runtimes |
| Vendoring dependencies | Enormous repository growth and manual security tracking without removing the need for a policy |

## Verification

- CI installs with `composer install --no-interaction`, `npm ci`, hashed
  `pip install --require-hashes`, and `melos bootstrap` against committed locks.
- A job asserts the working tree is clean after install; a modified lockfile
  fails.
- SBOM generation and vulnerability scan run per unit, enforcing the table above.
- Base images are referenced by digest; a tag-only reference fails a lint check.

## Review requirement

Engineering and security. Security owns the vulnerability policy and every
exception.
