# ADR 0007 — Data ownership and consistency model

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** Platform architecture, PostgreSQL consistency, AI platform, devops, privacy
- **Phase:** 00
- **Supersedes / Superseded by:** none

## Context

`plan.md` section 172 states the data ownership rule and section 173 divides the
system into strongly and eventually consistent concerns. Without a single stated
rule, a cache, a search index, or an analytics table gradually becomes a second
source of truth, and a rebuild that should be routine becomes a data-loss event.

## Decision

Ownership is fixed:

| Store | Role | If lost |
| --- | --- | --- |
| PostgreSQL / PostGIS | Operational and medical source of truth | Restore from backup; this is the protected asset |
| S3-compatible object storage | Original file source of truth | Restore from backup; this is the protected asset |
| Qdrant | Rebuildable retrieval index | Rebuild from PostgreSQL and S3 |
| Redis | Temporary cache, locks, rate limits, queue transport, pub/sub | Re-warm; no authoritative record may be lost |
| Analytics tables | Derived data | Re-aggregate from authoritative sources |

Consistency:

- **Strong**, inside one transaction: patient access grants, appointments,
  medical records, prescriptions, payments, invoices, stock movements, refunds.
- **Eventual**, through the outbox: notifications, analytics, search indexes,
  AI indexing, external pharmacy mirrors.

Two corollaries bind implementation:

1. An empty Redis after restart degrades performance temporarily; it never loses
   medical or business truth. Any design where a Redis key is the only copy of a
   fact is rejected.
2. Qdrant holds no fact that cannot be regenerated. A Qdrant outage never blocks
   a core workflow.

PHI caching is avoided. A reviewed exception must encrypt content, sharply bound
TTL and access, and prove deletion and invalidation.

Every cache entry declares owner, key shape, classification, TTL, invalidation
trigger, maximum payload, and behavior when missing or stale, recorded in
`docs/data-classification/cache-inventory.md`.

## Consequences

### Positive

- Recovery procedures follow from ownership: two stores need restore, three need
  rebuild.
- Backup scope, and therefore backup cost and exposure, is bounded.
- A cache or index outage has a predictable, tested degradation path.

### Negative / accepted cost

- Rebuilding Qdrant after a loss costs embedding time and provider spend.
- Refusing PHI caches costs latency on some read paths; those paths must be made
  fast in PostgreSQL instead.

### Risks and their mitigations

| Risk | Mitigation |
| --- | --- |
| A Redis key silently becomes authoritative | Flush-Redis system test asserts no authoritative record is lost and caches re-warm |
| Analytics diverges and is treated as truth | Analytics is derived and re-aggregatable; reconciliation checks compare against authoritative sources |
| A PHI cache appears without review | Cache inventory is required for every new cache entry; a cache with no inventory entry fails review |
| Qdrant becomes a dependency of a core path | Failure-isolation system test stops Qdrant and asserts core flows continue |

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Redis as a write-through system of record for queue or session state | Makes an in-memory store authoritative for facts that must survive restart |
| Qdrant as the store for clinical document text | Vector stores are rebuildable indexes; medical truth belongs in PostgreSQL and S3 |
| Eventual consistency for stock movements | A sale could allocate stock that another sale already consumed; `plan.md` section 173 requires strong consistency |

## Migration and rollback impact

Forward: initial. Any future store must be classified into this table before it
is introduced.

Rollback: not applicable.

## Verification

- System test: flush Redis and restart workers; authoritative data intact and
  queues/outbox resume without duplicate effects.
- System test: stop Qdrant and the AI service; core health and non-AI smoke flow
  remain available.
- Cache inventory entry exists for every configured cache prefix.
- Backup and restore rehearsal covers PostgreSQL and S3 only, with documented
  rebuild procedures for Qdrant and analytics.

## Review requirement

Engineering, PostgreSQL consistency owner, privacy for the PHI-cache rule.
