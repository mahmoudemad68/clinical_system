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

### `features`

Pennant store. Name, scope, and JSON value of a server-owned flag. Values are
booleans for V1 exclusions (always resolved false in application code).

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `name`, `scope` | internal | Flag identity | app | until deleted | at rest | UNASSIGNED |
| `value` | internal | Serialized boolean or metadata. Never a secret. | app | as row | at rest | UNASSIGNED |

### `platform_config_audits`

Configuration, flag, and secret-access audit. Secret *values* are withheld.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Row identity | app, operator | 90 days (default) | at rest | UNASSIGNED |
| `kind` | internal | `flag`, `config`, or `secret_access` | app, operator | as row | at rest | UNASSIGNED |
| `key` | internal | Config/flag name | app, operator | as row | at rest | UNASSIGNED |
| `from_value` / `to_value` | internal | Booleans or `[withheld]` | app, operator | as row | at rest | UNASSIGNED |
| `actor_key` | internal | Pseudonymous actor fingerprint | app, operator | as row | at rest | UNASSIGNED |
| `occurred_at` | internal | When | app, operator | as row | at rest | UNASSIGNED |

### `notifications`

Laravel Database Notifications inbox. This is the source of truth for a user-visible notice. Push is delivery only and must not invent a parallel inbox table.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Notification identity (UUID) | app | until deleted / retention job | at rest | UNASSIGNED |
| `type` | internal | PHP notification class name | app | as row | at rest | UNASSIGNED |
| `notifiable_type` / `notifiable_id` | personal | Actor reference, not a clinical record | app | as row | at rest | UNASSIGNED |
| `data` | internal | `notification_type` plus opaque resource refs. Never clinical text, national IDs, or device tokens. | app | as row | at rest | UNASSIGNED |
| `read_at` | internal | Inbox read state | app | as row | at rest | UNASSIGNED |
| `created_at` / `updated_at` | internal | Lifecycle | app | as row | at rest | UNASSIGNED |

### Telescope tables (`telescope_entries`, `telescope_entries_tags`, `telescope_monitoring`)

Local debugging only. Migrations live under `database/telescope/` and are loaded when `APP_ENV=local`. They are **not** on the production migration path and must not exist in staging or production schemas. `content` can hold request/query snapshots, so the tables are treated as credential-capable even though they are never a product inbox.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `content` | credential | Local request/query debug snapshot | local operator | local prune | at rest (developer volume) | engineering (local only) |
| remaining columns | internal | Indexing and display for the local UI | local operator | local prune | at rest | engineering (local only) |

---

## First-party Inertia props

Phase 00 status pages share only process liveness. No actor, tenant, host, check list, or Telescope payload.

| Prop | Class | Purpose |
| --- | --- | --- |
| `service`, `version`, `status` | public | Process identity and liveness |
| `message`, `labels.*`, `locale` | public | Arabic/English catalogue copy |
| shared `locale` | public | Negotiated `ar` or `en` |

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
`service`, `version`, `method`, `route`, `status`, `status_class`, `check`,
`queue`, `error_class`, `connection`, `rule`, `le`.

Families exported by `/metrics`:

- `clinic_http_responses_total`, `clinic_http_request_duration_seconds`
- `clinic_readiness_status`, `clinic_dependency_status`
- `clinic_outbox_pending_total`, `clinic_outbox_dead_letter_total`, `clinic_outbox_oldest_pending_age_seconds`
- `clinic_db_connections_in_use`, `clinic_db_connections_limit`
- `clinic_db_query_duration_seconds_bucket|_sum|_count`
- `clinic_horizon_queue_depth`
- `clinic_redis_errors_total`
- `clinic_reverb_connections` (0 until the Reverb process exports a live count)
- `clinic_provider_failures_total` (`error_class=push` from the Firebase adapter; other providers still unused)
- `clinic_redaction_canary_total`

**No metric may be labelled** with a patient, doctor, appointment, file,
prescription, user, or free-text value. The collector deletes those keys if they
ever appear, and `Classification::allowedAsMetricLabel()` encodes the rule.

## Caches

| Prefix | Owner | Class | TTL | Invalidation | Max payload | On miss |
| --- | --- | --- | --- | --- | --- | --- |
| `platform:meta:version` | platform | public | 60s | deploy / `platform:cache-warm` | 32 bytes | read APP_VERSION |
| `platform:ready:flag` | platform | internal | 10s | readiness change / warm | 1 byte | recompute readiness |

These keys hold no PHI. An empty Redis after restart degrades to a cache miss;
PostgreSQL remains authoritative (G-04-06).

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
3. **Deletion and anonymization procedures do not exist.**
   `notifications.notifiable_id` is already a personal actor reference.
   Procedures must exist before Phase 01 stores a patient profile.

Tracked as G-05-02 and G-08-04 in the evidence ledger.
