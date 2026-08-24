# ADR 0009 — Queue ownership boundary between Laravel and Python

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, backend, realtime/jobs, AI platform, security
- **Phase:** 00

## Context

`plan.md` section 102 lists queue lanes including `ai` and `kb_ingestion`, and
section 103 assigns Horizon to monitor Redis-backed queues. Read casually, that
suggests Python workers consuming the same Redis queues.

They cannot. Laravel job payloads are PHP-serialized objects bound to PHP class
names. A Python worker deserializing them would either fail or, if made to
succeed through an unsafe deserializer, would introduce a remote code execution
path into the AI service. The phase file resolves this explicitly: "Laravel
Horizon consumes Laravel-owned Redis jobs only. Python workers must never
deserialize or acknowledge Laravel/PHP job payloads."

This ADR records the resulting boundary, which the phase file requires to be
ADR-approved.

## Decision

**Laravel side.** Lanes `critical`, `notifications`, `files`, `integrations`,
`analytics`, `reports`, `backups`, and Laravel-side AI orchestration are PHP job
classes on Redis, managed and monitored by Horizon. The `ai` lane on the Laravel
side means "Laravel work that talks to the AI service," never "Python work."

**Crossing the boundary.** Laravel starts Python work through an authenticated,
versioned internal HTTP command defined in `packages/contracts/ai_internal/`.
The command carries:

| Field | Purpose |
| --- | --- |
| `idempotency_key` | Cross-boundary duplicate resolution |
| `deadline` | Absolute UTC instant after which the result is not useful |
| `schema_version` | Envelope compatibility |
| object references | Minimal identifiers; never inline clinical payloads |

A typed JSON message envelope over a dedicated transport is an approved
alternative to HTTP for this hop; PHP serialization is not.

**Python side.** FastAPI persists or queues the command through an AI-owned
`TaskQueue` port. Its implementation may use a dedicated Redis namespace or
instance and a Python-native worker library selected in Phase 16. The AI service
owns that queue completely; Horizon does not observe it.

**Returning results.** Python workers return status and result references
through an authenticated callback to Laravel or a polled internal resource. They
never write Laravel core tables.

**Retries.** Both sides keep independent retry budgets. A cross-boundary
duplicate resolves through the same idempotency key. A timeout is an unknown
outcome to reconcile, never permission to create a second task.

## Consequences

### Positive

- No cross-language deserialization, and therefore no deserialization attack
  surface between the two runtimes.
- Each side scales, retries, and monitors its own workers independently.
- The AI service can change its worker library in Phase 16 without touching
  Laravel.

### Negative / accepted cost

- Two queue systems to operate and monitor.
- The crossing is an explicit contract that must be versioned and tested.
- Horizon dashboards do not show Python work; AI queue depth needs its own
  metrics and alerts.

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| A timeout causes a duplicate task | Same idempotency key on retry; the AI side deduplicates before creating work |
| An orphaned task after a Laravel-side failure | Reconciliation compares Laravel's expected tasks against AI-side status; a timeout is reconciled, not assumed |
| Someone points a Python worker at the Laravel Redis database | Separate credentials and namespaces per workload; the AI service holds no Laravel queue credential |
| Clinical payloads cross inline | Contract carries object references only; a payload-shape test rejects inline clinical content |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Shared Redis queue consumed by both runtimes | Requires cross-language deserialization of PHP payloads; unsafe and fragile |
| A neutral broker (RabbitMQ/Kafka) for the hop | Adds an operational component for one hop at this scale; revisit if the number of cross-boundary flows grows |
| Laravel calling the model provider directly | Violates ADR 0001 isolation and puts prompt handling in the process owning PHI |

## Verification

- Integration test: AI service authenticates the internal command, honors the
  deadline, and rejects an unknown schema version safely.
- Test: the same idempotency key replayed across the boundary produces one task.
- Configuration check: the AI service has no Laravel queue or core database
  credential in any environment.
- Metrics exist for AI-side queue depth and age, separate from Horizon.

## Review requirement

Engineering, security, and the AI platform owner.
