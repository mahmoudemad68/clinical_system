# ADR 0004 — Transactional outbox and explicit cross-module coordinators

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, backend, realtime/jobs, PostgreSQL
- **Phase:** 00
- **Supersedes / Superseded by:** none

## Context

`plan.md` section 104 requires that a committed state change never loses its
side effect, and section 174 forbids a user request from performing PDF
extraction, embedding, push delivery, analytics, or external API work inline.
Section 173 marks appointments, patient access, medical records, prescriptions,
invoices, stock movements, refunds, and payments strongly consistent.

Writing to PostgreSQL and then publishing to Redis, FCM, or an external system
is a dual write. A crash between the two loses the effect; publishing first and
committing second produces an effect for a change that never happened.

Separately, workflows such as booking, consultation start and end, prescription
exposure, purchase receipt, POS sale, cancellation, and refund span modules. If
those are assembled from eventually consistent event chains, an invariant that
must hold at commit time becomes an invariant that holds eventually, which for
stock and access grants is a defect.

## Decision

**Outbox.** A state change and its `outbox_events` row are written in one
PostgreSQL transaction. A worker claims rows with `FOR UPDATE SKIP LOCKED`,
publishes or handles them using `event_id` as the consumer idempotency key,
records attempt count, next attempt, last safe error class, and processed
timestamp, retries only transient failures with capped exponential backoff plus
jitter, and moves exhausted failures to an operator-visible dead-letter state.
A repair command can replay an explicitly selected event or range without
creating duplicate effects.

Events carry identifiers and required non-sensitive facts. They never carry
whole patient, prescription, lab, or AI payloads.

**Coordinators.** A cross-module workflow that requires one transaction is
implemented as one named application coordinator that:

1. owns the use-case contract and the transaction boundary;
2. calls narrow module-owned command ports and never imports another module's
   Eloquent model or writes another module's table;
3. passes the same transaction context to every port and receives typed results,
   not framework models;
4. collects domain events during the transaction and writes them to the outbox
   before commit;
5. starts external, realtime, notification, and analytics work only after
   commit;
6. rolls the whole workflow back on any strong-consistency failure. Compensation
   is reserved for effects that cannot share the database transaction.

## Consequences

### Positive

- No committed change silently loses its notification, realtime event, or
  analytics record.
- Requests return immediately; unbounded work runs in workers.
- The set of cross-module writers is small, named, and testable.

### Negative / accepted cost

- Every asynchronous effect must be idempotent, because at-least-once delivery
  is the guarantee.
- The outbox table is high-volume and needs retention and, eventually, a
  partitioning decision backed by measured volume.
- Coordinators concentrate transaction knowledge; they need careful review.

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| Duplicate side effects on retry | Consumer idempotency keyed on `event_id`; the exactly-once-in-effect test forces duplicate delivery |
| A worker dies mid-processing and rows stall | Lease expiry plus recovery by another worker; proven by the kill-a-worker system test |
| Outbox backlog grows unnoticed | Backlog depth and oldest-unprocessed-age metrics with alerts and a runbook |
| A module bypasses its coordinator | Architecture test asserts only the approved coordinator uses the participating command ports |
| An event handler performs a write the originating invariant needed | Architecture test rejects a delayed write required for the originating invariant |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Publish to Redis inside the transaction | Redis is not transactional with PostgreSQL; the dual-write failure mode remains |
| Change data capture from the WAL | Adds an operational component and infrastructure dependency for a benefit the outbox already provides at this scale; revisit only with volume evidence |
| Saga/compensation for booking and POS | Compensating a committed stock allocation or access grant is not equivalent to never granting it; `plan.md` section 173 requires strong consistency here |
| Event chains instead of coordinators | Moves a commit-time invariant to eventual, which for FEFO allocation and access grants is a correctness bug |

## Migration and rollback impact

Forward: initial. New workflows add coordinators and event types.

Rollback: an outbox row already published cannot be unpublished; correction is a
new compensating event, never a delete.

## Verification

- Integration test: transaction rollback leaves no outbox row.
- Integration test: concurrent workers claim disjoint row sets.
- End-to-end test: forced duplicate delivery produces exactly one effect.
- System test: killing a worker mid-processing lets another recover after lease
  expiry.
- Unit test: retry schedule is capped, jittered, and separates permanent from
  transient failures.

## Review requirement

Engineering and PostgreSQL consistency owner. Realtime/jobs owner for consumer
semantics.
