# Architecture Decision Records

An ADR records a decision that constrains later work. If a decision can be
reversed by one developer without discussion, it is not an ADR.

## Rules

1. Any deviation from `plan.md` or a phase invariant requires an ADR with risks,
   migration and rollback impact, and the reviewers named by the phase
   (`docs/phases/README.md`).
2. An ADR is immutable once Accepted. Changing a decision means writing a new
   ADR that supersedes it, with both files updated to point at each other.
3. Every ADR states how the decision is verified. A decision with no enforcement
   is a preference.
4. Engineering cannot self-approve a clinical, pharmacy-regulatory, privacy, or
   legal question. Those need the accountable owner named in the phase.

Copy `0000-adr-template.md` to start. Number sequentially; do not reuse a number.

## Index

| ID | Title | Status | Phase |
| --- | --- | --- | --- |
| [0001](0001-modular-monolith-with-separate-ai-service.md) | Modular monolith for core, isolated service for AI | Accepted | 00 |
| [0002](0002-single-repository-layout-and-boundary-enforcement.md) | Single repository, independent deployment units, enforced boundaries | Accepted | 00 |
| [0003](0003-api-first-contracts.md) | OpenAPI and event schemas are the contract source of truth | Accepted | 00 |
| [0004](0004-transactional-outbox-and-coordinators.md) | Transactional outbox and explicit cross-module coordinators | Accepted | 00 |
| [0005](0005-uuidv7-identifiers.md) | UUIDv7 primary keys, no public sequential identifiers | Accepted | 00 |
| [0006](0006-client-local-encryption.md) | Client-side local storage encryption | Accepted, open spike | 00 |
| [0007](0007-data-ownership-and-consistency.md) | Data ownership and consistency model | Accepted | 00 |
| [0008](0008-package-and-version-policy.md) | Package selection and version policy | Accepted | 00 |
| [0009](0009-queue-ownership-across-php-and-python.md) | Queue ownership boundary between Laravel and Python | Accepted | 00 |

## Open decisions carried by the roadmap

`docs/phases/README.md` lists safety-significant choices that must close before
a named gate. They are not ADRs yet because the accountable human owner has not
recorded the decision. The conservative planning default holds until then.

ADR 0006 is the one Phase 00 ADR with an open condition: the client encryption
compatibility spike (gate G-06-01).
