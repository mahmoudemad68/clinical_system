# Data inventory

Required by Phase 00 §5.2. One row per field, event, log, metric, cache entry,
and file type the platform holds.

**Scope: Phase 00 only.** The platform currently holds three tables, one event
type, and a small set of metrics. Every later phase extends this file as part of
its own gate; a field with no row here fails review.

Levels and rules: [`classification-policy.md`](classification-policy.md).

Retention owner column: the accountable human who decides the retention period.
`UNASSIGNED` means the Phase 00 entry criterion naming that owner has not been
met, and the value shown is an engineering default, not an approved policy.

---

## Tables

### `outbox_events`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `event_id` | internal | Consumer idempotency key | app, worker | 7 days after processing | at rest (volume) | UNASSIGNED |
| `event_type` | internal | Consumer routing | app, worker | as row | at rest | UNASSIGNED |
| `schema_version` | internal | Compatibility check | app, worker | as row | at rest | UNASSIGNED |
| `aggregate_type` / `aggregate_id` | internal | Which aggregate changed | app, worker | as row | at rest | UNASSIGNED |
| `occurred_at` | internal | Transaction time | app, worker | as row | at rest | UNASSIGNED |
| `actor_id` | personal | Pseudonymous actor reference | app, worker | as row | at rest | UNASSIGNED |
| `correlation_id` / `causation_id` | internal | Tracing an effect to its cause | app, worker | as row | at rest | UNASSIGNED |
| `classification` | internal | Declared payload level | app, worker | as row | at rest | UNASSIGNED |
| `payload` | varies | Minimal event facts | worker | as row | at rest | UNASSIGNED |
| `status`, `attempts`, `available_at`, `claimed_*`, `lease_expires_at`, `processed_at` | internal | Delivery state | app, worker | as row | at rest | UNASSIGNED |
| `last_error_class` | internal | Stable failure label, never a provider message | app, worker | as row | at rest | UNASSIGNED |

`payload` inherits the classification declared in the `classification` column,
constrained by a CHECK to `public`, `internal`, `personal`, or `sensitive`.
`credential` is rejected by the database: credentials never travel in an event.

**Retention note.** Only `PROCESSED` rows are pruned. `DEAD_LETTER` rows are
retained until an operator resolves them — they are the only record that a
committed change failed to produce its effect.

### `idempotency_keys`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `key_hash` | internal | Scoped key; SHA-256 of operation + actor + tenant + client key | app | 72 hours | at rest | UNASSIGNED |
| `operation_id` | internal | Which operation the key belongs to | app | as row | at rest | UNASSIGNED |
| `request_hash` | internal | Canonical request fingerprint | app | as row | at rest | UNASSIGNED |
| `state`, `status_code` | internal | Replay decision | app | as row | at rest | UNASSIGNED |
| `response_reference` | varies | Pointer to the outcome, not the outcome | app | as row | at rest | UNASSIGNED |
| `safe_error_class` | internal | Stable failure label | app | as row | at rest | UNASSIGNED |
| `created_at`, `updated_at`, `expires_at` | internal | Lifecycle | app | as row | at rest | UNASSIGNED |

The key is hashed rather than stored raw so the table discloses neither the
client's key nor the actor's identity to anyone reading it.

**Retention must exceed the longest client retry and offline window.** The
doctor desktop local outbox makes this concrete: a device offline for two days
replays its queue on reconnect, and a purged key would let that replay create a
second effect. 72 hours is the engineering default pending an owner.

### `platform_diagnostics`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Synthetic record identifier | app, worker | 7 days | at rest | platform-architecture |
| `label` | internal | Test-run slug; constrained to a lowercase slug with no 10+ digit run | app, worker | as row | at rest | platform-architecture |
| `echo_delay_ms` | internal | Timing-test parameter | worker | as row | at rest | platform-architecture |
| `outbox_event_id` | internal | Paired outbox row | app, worker | as row | at rest | platform-architecture |
| `correlation_id` | internal | Tracing | app, worker | as row | at rest | platform-architecture |
| `recorded_at`, `consumed_at`, `consumed_count` | internal | Exactly-once evidence | app, worker | as row | at rest | platform-architecture |

**Contains no personal or clinical data by construction.** The table is
flag-gated, environment-restricted, and dropped when Phase 01 delivers real
slices. Its owner is engineering because nothing in it is regulated.

---

## Events

| Event | Version | Class | Payload | Consumers | Retention |
| --- | --- | --- | --- | --- | --- |
| `platform.diagnostics_round_trip_recorded` | 1 | internal | `diagnostics_id`, `label`, `echo_delay_ms`, `recorded_at` | `platform.diagnostics_consumer` | 7 days |

---

## Logs and traces

| Signal | Class | Contents | Retention | Notes |
| --- | --- | --- | --- | --- |
| Application log | internal | Correlation ID, event type, status, stable error class | 30 days (default) | `PatternRedactor` runs before export; OTel Collector scrubs again at the boundary |
| Distributed trace | internal | `traceparent`, correlation ID, service, version, bounded attributes | 7 days (default) | Attribute deny-list plus value-pattern scrubbing in the collector |
| Error report (Sentry) | internal | Exception class, stack, correlation ID | 90 days (default) | `send_default_pii` is off; message scrubbing applies |

No log, trace, or error report may contain a raw national ID, credential,
clinical note, prescription text, lab content, object key, or unrestricted
prompt or response (invariant 18). This is enforced by `PatternRedactor`,
verified by the canary suite, and scrubbed again at the collector.

## Metrics

Every Phase 00 metric is `internal` and carries only bounded labels:
`service`, `version`, `status`, `check`, `connection`, `event_type`.

**No metric may be labelled** with a patient, doctor, appointment, file,
prescription, user, or free-text value. The collector deletes those keys if they
ever appear, and `Classification::allowedAsMetricLabel()` encodes the rule.

## Caches

| Prefix | Owner | Class | TTL | Invalidation | Max payload | On miss |
| --- | --- | --- | --- | --- | --- | --- |
| *(none in Phase 00)* | — | — | — | — | — | — |

Phase 00 configures Redis connections but caches nothing. Every future cache
entry needs a row here before it ships, including behaviour when missing or
stale (ADR 0007). A cache with no inventory row fails review.

## Files

| Type | Class | Store | Retention | Notes |
| --- | --- | --- | --- | --- |
| *(none in Phase 00)* | — | — | — | — |

Private object storage is provisioned; no file type is defined until Phase 02.

---

## Gaps

1. **No retention period here is approved.** Every `UNASSIGNED` owner is an
   unmet Phase 00 entry criterion. Clinical and financial record retention in
   Egypt is a legal question, not an engineering default.
2. **No lawful-basis column is filled in.** That requires the privacy and legal
   owners, and stating a basis without them would be worse than leaving it blank.
3. **Deletion and anonymization procedures do not exist.** Phase 00 holds no
   personal data, so nothing is currently at risk, but they must exist before
   Phase 01 stores a patient profile.

Tracked as G-05-02 and G-08-04 in the evidence ledger.
