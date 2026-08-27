# ADR 0001 — Modular monolith for core, isolated service for AI

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, backend, AI platform, security
- **Phase:** 00
- **Supersedes / Superseded by:** none

## Context

`plan.md` section 2 rejects a microservice decomposition for V1 and selects a
modular monolith. Section 94 and phase file section "System boundaries" require
the AI subsystem to be isolated so that an AI outage is not a core outage
(`plan.md` section 141), and so that untrusted model output can never reach a
core table directly (`docs/phases/README.md` invariant 15).

Two independent pressures act on the same decision:

1. Clinical, appointment, prescription, and financial workflows span modules and
   need one database transaction. Distributed transactions across services would
   force compensation logic onto invariants that must be strongly consistent
   (`plan.md` section 173).
2. The AI subsystem has a different runtime (Python), a different failure
   profile (provider latency, GPU capacity), a different trust level (untrusted
   input and output), and a different scaling curve. Sharing a process with the
   core would couple all four.

## Decision

The core platform is a single Laravel deployment artifact containing internally
separated modules under `apps/core-api/Modules/<Name>/`, managed by
`nwidart/laravel-modules`. Modules communicate through public module services
and published events, never through direct cross-module table writes.
Cross-module workflows that require one transaction are implemented as explicit
coordinating services (see ADR 0004). First-party admin UI is Inertia.js inside
`apps/core-api`; do not scaffold a sibling `apps/admin-web` SPA.

The AI subsystem is a separate FastAPI deployment artifact at
`apps/ai-service/`. It owns its own storage (Qdrant), its own queue, and its own
credentials. Laravel calls it through a versioned internal HTTP contract at
`packages/contracts/ai_internal/`. The AI service holds no core database
credential and cannot write core tables. Results return through an authenticated
callback or a polled internal resource.

## Consequences

### Positive

- Strong-consistency invariants stay inside one PostgreSQL transaction.
- Module boundaries exist from day one, so extracting Pharmacy, Notifications, or
  Chat later is a deployment change rather than a rewrite.
- AI unavailability degrades one capability instead of the platform.
- The Python and PHP dependency trees never mix.

### Negative / accepted cost

- One core artifact means one deployment cadence for all core modules.
- Boundary enforcement is a build-time concern that must be automated or it
  erodes; unenforced conventions are known to decay.
- Two runtimes mean two toolchains, two CI lanes, and two SBOMs.

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| Modules drift into direct table coupling | `deptrac` layer rules plus architecture tests fail CI on a forbidden import (ADR 0002 verification) |
| Core waits on a slow AI call | Internal contract carries a deadline; AI work is dispatched post-commit through the outbox, never inline in a request (`plan.md` section 174) |
| AI readiness drags core readiness down | Core `/ready` excludes AI and Qdrant; proven by the failure-isolation system test |
| A future extraction finds hidden coupling | Module catalog records public ports and prohibited dependencies per module |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Microservices from day one | Booking, consultation completion, and POS sale would need distributed transactions or compensation for invariants that `plan.md` section 173 marks strongly consistent |
| Single process including Python AI | Impossible on one runtime; would also couple untrusted model execution to the process that owns authorization |
| Laravel core calling model providers directly | Puts provider SDKs, prompt handling, and retrieval inside the process that owns PHI and authorization, widening the blast radius of prompt injection |

## Migration and rollback impact

Forward: none, this is the initial shape. Extracting a module later requires its
public ports to already be the only entry point, which the architecture tests
enforce continuously.

Rollback: collapsing the AI service into core is not permitted, because it would
violate the AI isolation invariant. A change of that kind requires a superseding
ADR with security and AI-safety approval.

## Verification

- `deptrac` module configuration in `apps/core-api/deptrac.yaml` fails on a
  forbidden cross-module import, including Platform importing a business module.
- Architecture tests assert the conventional `Modules/<Name>` layout, reject
  `Domain/Application/Infrastructure` trees, and reject `app/Modules`.
- The failure-isolation system test stops the AI service and asserts core
  `/ready` remains `200` while AI readiness reports degraded.
- The AI service has no core database credential in any environment file.

## Review requirement

Engineering and security. AI safety must approve any later change to the
isolation boundary.
