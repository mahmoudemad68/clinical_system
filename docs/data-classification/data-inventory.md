# Data inventory

Required by Phase 00 §5.2. One row per field, event, log, metric, cache entry,
and file type the platform holds.

**Scope: Phase 00 and Phase 01.** Later phases extend this file as part of
their own gate; a field with no row here fails review.

Levels and rules: [`classification-policy.md`](classification-policy.md).

Retention owner column: the accountable human who decides the retention period.
The Phase 00 entry criterion naming that owner is met:
[accountable-owners.md](../governance/accountable-owners.md) (Mahmoud, 2026-08-26).
Periods in this file are still **engineering defaults** until a retention
schedule is written. `lawful_basis` for personal/sensitive processing is
`owner_approved_2026-08-27` (Mahmoud, privacy owner). That is an owner
acceptance of the documented purpose, not an Egyptian PDPL article number.

Deletion/purge procedure: [deletion-and-purge.md](deletion-and-purge.md).

## Live PostgreSQL relations (Phase 00/01)

Reconciled to committed Core migrations under
`apps/core-api/database/migrations/` plus PostGIS `CREATE EXTENSION` in
`infra/docker/postgres/initdb/01-roles-and-extensions.sql`. Laravel Modules
under `Modules/` currently ship no additional table migrations.

**Live product/framework tables:** `users`, `sessions`, `cache`, `cache_locks`,
`jobs`, `job_batches`, `failed_jobs`, `outbox_events`, `idempotency_keys`,
`platform_diagnostics`, `features`, `platform_config_audits`, `notifications`,
`identity_national_ids`, `user_devices`, `otp_requests`, `mfa_factors`,
`mfa_recovery_codes`, `mfa_challenges`, `auth_sessions`,
`identity_profile_links`, `contextual_access_grants`, `audit_events`,
`auth_refresh_consumptions`, `recovery_requests`.

**Laravel catalog (not created by an application `Schema::create`):**
`migrations`.

**Reporting views (not tables):** `reporting.account_status_counts`,
`reporting.session_kind_counts`, `reporting.audit_event_name_counts`.

**PostGIS catalog:** `spatial_ref_sys` (EPSG parameters). PostGIS also exposes
catalog views such as `geometry_columns` and `geography_columns`; those are
extension metadata, not clinic product data.

**Not live:** `password_reset_tokens` is created in the Laravel stub migration
and **dropped** in `2026_08_26_200000_create_identity_and_access_tables.php`.
It is recreated only on that migration's `down()`. Do not treat it as a current
holding.

**Local-only (not on the production migration path):** Telescope tables
documented below.

---

## Tables

### `outbox_events`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `event_id` | internal | Consumer idempotency key | app, worker | 7 days after processing | at rest (volume) | Mahmoud |
| `event_type` | internal | Consumer routing | app, worker | as row | at rest | Mahmoud |
| `schema_version` | internal | Compatibility check | app, worker | as row | at rest | Mahmoud |
| `aggregate_type` / `aggregate_id` | internal | Which aggregate changed | app, worker | as row | at rest | Mahmoud |
| `occurred_at` | internal | Transaction time | app, worker | as row | at rest | Mahmoud |
| `actor_id` | personal | Pseudonymous actor reference | app, worker | as row | at rest | Mahmoud |
| `correlation_id` / `causation_id` | internal | Tracing an effect to its cause | app, worker | as row | at rest | Mahmoud |
| `classification` | internal | Declared payload level | app, worker | as row | at rest | Mahmoud |
| `payload` | varies | Minimal event facts | worker | as row | at rest | Mahmoud |
| `status`, `attempts`, `available_at`, `claimed_*`, `lease_expires_at`, `processed_at` | internal | Delivery state | app, worker | as row | at rest | Mahmoud |
| `last_error_class` | internal | Stable failure label, never a provider message | app, worker | as row | at rest | Mahmoud |

`payload` inherits the classification declared in the `classification` column,
constrained by a CHECK to `public`, `internal`, `personal`, or `sensitive`.
`credential` is rejected by the database: credentials never travel in an event.

**Retention note.** Only `PROCESSED` rows are pruned. `DEAD_LETTER` rows are
retained until an operator resolves them — they are the only record that a
committed change failed to produce its effect.

### `idempotency_keys`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `key_hash` | internal | Scoped key; SHA-256 of operation + actor + tenant + client key | app | 72 hours | at rest | Mahmoud |
| `operation_id` | internal | Which operation the key belongs to | app | as row | at rest | Mahmoud |
| `request_hash` | internal | Canonical request fingerprint | app | as row | at rest | Mahmoud |
| `state`, `status_code` | internal | Replay decision | app | as row | at rest | Mahmoud |
| `response_reference` | varies | Pointer to the outcome, not the outcome | app | as row | at rest | Mahmoud |
| `safe_error_class` | internal | Stable failure label | app | as row | at rest | Mahmoud |
| `created_at`, `updated_at`, `expires_at` | internal | Lifecycle | app | as row | at rest | Mahmoud |

The key is hashed rather than stored raw so the table discloses neither the
client's key nor the actor's identity to anyone reading it.

**Retention must exceed the longest client retry and offline window.** The
doctor desktop local outbox makes this concrete: a device offline for two days
replays its queue on reconnect, and a purged key would let that replay create a
second effect. 72 hours is the engineering default; the retention owner is Mahmoud.

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
| `name`, `scope` | internal | Flag identity | app | until deleted | at rest | Mahmoud |
| `value` | internal | Serialized boolean or metadata. Never a secret. | app | as row | at rest | Mahmoud |

### `platform_config_audits`

Configuration, flag, and secret-access audit. Secret *values* are withheld.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Row identity | app, operator | 90 days (default) | at rest | Mahmoud |
| `kind` | internal | `flag`, `config`, or `secret_access` | app, operator | as row | at rest | Mahmoud |
| `key` | internal | Config/flag name | app, operator | as row | at rest | Mahmoud |
| `from_value` / `to_value` | internal | Booleans or `[withheld]` | app, operator | as row | at rest | Mahmoud |
| `actor_key` | internal | Pseudonymous actor fingerprint | app, operator | as row | at rest | Mahmoud |
| `occurred_at` | internal | When | app, operator | as row | at rest | Mahmoud |

### `notifications`

Laravel Database Notifications inbox. This is the source of truth for a user-visible notice. Push is delivery only and must not invent a parallel inbox table.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Notification identity (UUID) | app | until deleted / retention job | at rest | Mahmoud |
| `type` | internal | PHP notification class name | app | as row | at rest | Mahmoud |
| `notifiable_type` / `notifiable_id` | personal | Actor reference, not a clinical record | app | as row | at rest | Mahmoud |
| `data` | internal | `notification_type` plus opaque resource refs. Never clinical text, national IDs, or device tokens. | app | as row | at rest | Mahmoud |
| `read_at` | internal | Inbox read state | app | as row | at rest | Mahmoud |
| `created_at` / `updated_at` | internal | Lifecycle | app | as row | at rest | Mahmoud |

### `jobs`

Laravel database queue table (`config/queue.php` `connections.database.table`,
default `jobs`). Config default `QUEUE_CONNECTION=database`. Local Compose
(`infra/environments/local.env`) sets `QUEUE_CONNECTION=redis`; phpunit uses
`sync`. Horizon consumes Redis, not this table, when the Redis connection is
selected. The table remains in the live schema either way.

**Writer.** `clinic_app` enqueue; `clinic_worker` claim/update/delete (table DML
plus `jobs_id_seq` USAGE). **Readers.** Same roles; operators via Artisan.
`clinic_reporter` is revoked.

**PII / sensitive / credential.** `payload` is a serialized PHP job. Jobs must
not serialize secrets, tokens, National IDs, or PHI (outbox/classification
rules). That invariant is not a proof that a given row is clean: treat
`payload` as **possibly personal or security-relevant**. No envelope encryption
of the column.

**Retention / deletion.** Successful database-driver jobs are deleted by the
Laravel worker when the job completes. There is no clinic `platform:prune` or
scheduled `queue:prune-*` for leftover rows. Legal retention period:
**pending**.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Queue row identity | app, worker | until job deleted | at rest (volume) | Mahmoud |
| `queue` | internal | Lane name | app, worker | as row | at rest | Mahmoud |
| `payload` | varies | Serialized job class and data | app, worker | as row | at rest (not enveloped) | Mahmoud |
| `attempts` | internal | Delivery attempts | app, worker | as row | at rest | Mahmoud |
| `reserved_at`, `available_at`, `created_at` | internal | Lease and schedule (unix timestamps) | app, worker | as row | at rest | Mahmoud |

`lawful_basis` for a payload that identifies a person: **pending** (not a PDPL
article; not covered by a written legal schedule).

### `job_batches`

Laravel job-batch metadata (`config/queue.php` `batching.table` = `job_batches`).

**Writer / readers.** `clinic_app` and `clinic_worker` DML. Reporter revoked.

**PII / sensitive / credential.** `name`, `options`, and `failed_job_ids` are
operational metadata. Do not assume `options` is free of identifiers.

**Retention / deletion.** Rows remain after the batch finishes unless an
operator prunes them. No clinic prune job. Legal retention: **pending**.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Batch identity | app, worker | until operator prune | at rest | Mahmoud |
| `name` | internal | Batch label | app, worker | as row | at rest | Mahmoud |
| `total_jobs`, `pending_jobs`, `failed_jobs` | internal | Progress counters | app, worker | as row | at rest | Mahmoud |
| `failed_job_ids` | internal | Serialized failed member ids | app, worker | as row | at rest | Mahmoud |
| `options` | varies | Batch options blob | app, worker | as row | at rest | Mahmoud |
| `cancelled_at`, `created_at`, `finished_at` | internal | Lifecycle (unix timestamps) | app, worker | as row | at rest | Mahmoud |

### `failed_jobs`

Laravel failed-job log (`config/queue.php` `failed.table` = `failed_jobs`,
driver default `database-uuids`). Failed jobs are written here even when the
primary queue transport is Redis, unless `QUEUE_FAILED_DRIVER` is changed.

**Writer / readers.** `clinic_app` and `clinic_worker` DML (`failed_jobs_id_seq`
USAGE for the worker). Operators read via `queue:failed` / Horizon UI against
this table when the database failed driver is used.

**PII / sensitive / credential.** `payload` is the serialized job.
`exception` is a `longText` stack and message. **Do not assume either column is
free of personal, clinical, or security data** (SQL bindings, identifiers,
request fragments). There is no redaction job on this table.

**Retention / deletion.** No scheduled `queue:prune-failed`. Not covered by
`auth:prune-expired` or `platform:prune`. Legal retention: **pending**.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Row identity | app, worker, operator | until operator prune | at rest | Mahmoud |
| `uuid` | internal | Failed-job UUID | app, worker, operator | as row | at rest | Mahmoud |
| `connection`, `queue` | internal | Which connection and lane failed | app, worker, operator | as row | at rest | Mahmoud |
| `payload` | varies | Serialized job at failure | app, worker, operator | as row | at rest (not enveloped) | Mahmoud |
| `exception` | varies | Exception message and stack; may contain sensitive fragments | app, worker, operator | as row | at rest (not enveloped) | Mahmoud |
| `failed_at` | internal | When it failed | app, worker, operator | as row | at rest | Mahmoud |

`lawful_basis` if a payload or exception identifies a person: **pending**.

### `cache`

Laravel database cache store (`config/cache.php` store `database`, table
default `cache`). Config default `CACHE_STORE=database`. Local Compose uses
`CACHE_STORE=redis`; phpunit uses `array`. Auth rate-limit counters use Redis
database index 3 (`ratelimit`) and are **not** this table (see Caches).

**Writer / readers.** `clinic_app` DML. `clinic_worker` is revoked on this
table.

**PII / sensitive / credential.** `value` is a serialized cache blob.
Classification follows the key (documented prefixes in Caches are
`public`/`internal`). PHI is not cached by default (ADR 0007 /
classification policy). Treat an undocumented key as **not proven clean**.

**Retention / deletion.** `expiration` is a unix timestamp; Laravel's database
cache garbage-collects expired rows on access lottery. No clinic prune job.
Legal retention: **pending** (transient operational data; no statutory period
recorded).

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `key` | internal | Cache key | app | until expiry / delete | at rest | Mahmoud |
| `value` | varies | Serialized cache payload | app | as row | at rest (not enveloped) | Mahmoud |
| `expiration` | internal | Unix expiry | app | as row | at rest | Mahmoud |

### `cache_locks`

Laravel cache atomic locks (`cache_locks`). Same driver/lifecycle as `cache`.

**Writer / readers.** `clinic_app` DML. Worker revoked.

**PII / sensitive / credential.** `owner` is a lock token string, not an
identity record. Still operational security metadata.

**Retention / deletion.** Expired locks are released by the cache driver. No
clinic prune job. Legal retention: **pending**.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `key` | internal | Lock name | app | until lock released / expired | at rest | Mahmoud |
| `owner` | internal | Lock owner token | app | as row | at rest | Mahmoud |
| `expiration` | internal | Unix expiry | app | as row | at rest | Mahmoud |

### `sessions`

Laravel framework session store (`config/session.php` `table` default
`sessions`). Distinct from `auth_sessions` (normalized identity sessions).
Created in `0001_01_01_000000`; `user_id` was changed from bigint to nullable
UUID in the identity expand. Config default `SESSION_DRIVER=database`. Local
Compose uses Redis; phpunit uses `array`. `SESSION_ENCRYPT` defaults to
**false**. Cookie encryption, when enabled, is not the same as encrypting this
row's `payload`.

**Writer / readers.** `clinic_app` DML. `clinic_worker` revoked.

**PII / sensitive / credential.** **Yes, may contain personal and security
data:** `user_id` (nullable UUID), `ip_address`, `user_agent`, and `payload`
(serialized session: CSRF, flash, login state, and whatever the application
puts in the session). Do not treat `payload` as free of personal or security
data.

**Retention / deletion.** Idle lifetime is `SESSION_LIFETIME` (default 120
minutes) — an engineering config, not a legal retention period. Database-driver
lottery `[2, 100]` may delete expired rows when that driver is used. Not
covered by `auth:prune-expired` (that command prunes `auth_sessions` /
OTP ciphertext). Legal retention and subject-erasure: **pending**.

`lawful_basis` for `user_id` / `ip_address` / `user_agent` / `payload`:
**pending**. The 2026-08-27 owner acceptance records identity-table purposes; it
is not a PDPL article and is not treated here as covering framework session
payloads.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Session id (cookie value) | app | until lottery / expiry / logout | at rest | Mahmoud |
| `user_id` | personal | Nullable UUID of the logged-in user | app | as row | at rest | Mahmoud |
| `ip_address` | personal | Client address (varchar 45, nullable) | app | as row | at rest | Mahmoud |
| `user_agent` | personal | Client User-Agent (nullable text) | app | as row | at rest | Mahmoud |
| `payload` | varies | Serialized session body; may include personal or security data | app | as row | at rest; `SESSION_ENCRYPT` default false | Mahmoud |
| `last_activity` | internal | Last-activity unix timestamp (indexed) | app | as row | at rest | Mahmoud |

### Telescope tables (`telescope_entries`, `telescope_entries_tags`, `telescope_monitoring`)

Local debugging only. Migrations live under `database/telescope/` and are loaded when `APP_ENV=local`. They are **not** on the production migration path and must not exist in staging or production schemas. `content` can hold request/query snapshots, so the tables are treated as credential-capable even though they are never a product inbox.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `content` | credential | Local request/query debug snapshot | local operator | local prune | at rest (developer volume) | engineering (local only) |
| remaining columns | internal | Indexing and display for the local UI | local operator | local prune | at rest | engineering (local only) |

### `migrations`

Laravel schema-history catalog. Created by the migrator, not by an application
`Schema::create` in this repository.

**Writer / readers.** `clinic_migrator` during `php artisan migrate`. Serving
roles do not own this table as a product store.

**PII / sensitive / credential.** No. Filenames and batch numbers only.

**Retention / deletion.** Lifetime of the schema. No product prune.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `migration` | internal | Migration filename | migrator | life of schema | at rest | Mahmoud |
| `batch` | internal | Applied batch number | migrator | as row | at rest | Mahmoud |

### `spatial_ref_sys`

PostGIS EPSG spatial-reference catalog, created by `CREATE EXTENSION postgis`
in `infra/docker/postgres/initdb/01-roles-and-extensions.sql`. Not a clinic
product table.

**Writer.** Extension install / PostGIS, not application DML.

**PII / sensitive / credential.** No. Public geodetic parameters.

**Retention / deletion.** Lifetime of the PostGIS extension. No clinic prune.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| SRID / auth / proj4 columns (PostGIS catalog) | public | Coordinate-system definitions | PostGIS, spatial queries | extension lifetime | at rest | platform-architecture |

### Reporting views (`reporting.*`)

Created in `2026_08_26_210000_harden_identity_privileges_and_session_integrity.php`.
These are views, not stored tables. `clinic_reporter` has `SELECT` only.

| View | Class | Purpose | Read by | Stored retention | Notes |
| --- | --- | --- | --- | --- | --- |
| `reporting.account_status_counts` | internal | `account_type`, `status`, `count(*)` from `users` | reporter | none (computed) | Aggregates only; no names, phones, or National IDs in the view definition |
| `reporting.session_kind_counts` | internal | `session_kind`, `count(*)` of non-revoked `auth_sessions` | reporter | none (computed) | Counts, not session hashes or user ids |
| `reporting.audit_event_name_counts` | internal | `event_name`, truncated day, `count(*)` from `audit_events` | reporter | none (computed) | Event-name counts, not metadata payloads |

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
| Application log | internal | Correlation ID, event type, status, stable error class | 30 days (default) | `PatternRedactor` runs before a payload is treated as having left the process |
| In-process HTTP attributes | internal | `traceparent` echo, correlation ID, service, version, bounded attributes | process lifetime | Allow-list only; Telescope is local request inspection |
| Error report (Sentry) | internal | Exception class, stack, correlation ID | 90 days (default) | `send_default_pii` is off; message scrubbing applies |

No log, trace, or error report may contain a raw national ID, credential,
clinical note, prescription text, lab content, object key, or unrestricted
prompt or response (invariant 18). This is enforced by `PatternRedactor`,
verified by the canary suite. There is no second collector-stage scrubber.

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
| Auth rate-limit keys (`auth-login-*`, `auth-otp-*`, `auth-refresh-*`, `auth-mfa-*`, `auth-recovery-*`) | auth | internal | limiter window (60s or 3600s) | TTL | small counters | miss = allow first hit |

Auth counters live in Redis database index 3 (`ratelimit` store). They are not
identity truth and must not contain phones, codes, or tokens as key material
(HMACs and opaque ids only). An empty Redis after restart degrades to a cache
miss; PostgreSQL remains authoritative (G-04-06).

The PostgreSQL `cache` / `cache_locks` tables are the Laravel `database` cache
store (see Tables). They are empty when `CACHE_STORE` is Redis or `array`, but
they remain live schema objects.

## Files

| Type | Class | Store | Retention | Notes |
| --- | --- | --- | --- | --- |
| *(none in Phase 00)* | — | — | — | — |

Private object storage is provisioned; no file type is defined until Phase 02.

---

## Phase 01 tables (identity and access)

Engineering draft. Synthetic data only in tests. `lawful_basis` is the privacy
owner's acceptance of the documented purpose (`owner_approved_2026-08-27`).
It is not a statutory citation.

### `users`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Actor identifier | app, worker (FK only) | until account erasure (owner-approved engineering procedure) | at rest | Mahmoud | n/a |
| `name` | personal | Display; never authorization input | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `phone_e164_encrypted` | personal | Contact / login | app | as row; rotate via KMS path | envelope | Mahmoud | owner_approved_2026-08-27 |
| `phone_lookup_hmac` | personal | Blind lookup | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |
| `password_hash` | credential | Authentication | app | as row | Argon2id | Mahmoud | n/a |
| `account_type`, `status`, `language`, `credential_version` | internal | Server-owned actor state | app | as row | at rest | Mahmoud | n/a |

### `identity_national_ids`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `user_id` | internal | FK | app | as row | at rest | Mahmoud | n/a |
| `national_id_encrypted` | sensitive | Recovery / later verification | app (audited decrypt) | until erasure (owner-approved engineering TTL) | envelope | Mahmoud | owner_approved_2026-08-27 |
| `national_id_lookup_hmac` | sensitive | Blind match | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |

### `otp_requests`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Challenge id | app, worker | row TTL then delete | at rest | Mahmoud | n/a |
| `purpose` | internal | registration / recovery / … | app | as row | at rest | Mahmoud | n/a |
| `subject_lookup_hmac` | personal | Blind phone handle | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |
| `code_hash` | credential | Verify attempt | app | until row delete | HMAC | Mahmoud | n/a |
| `code_ciphertext` | credential | Worker send only | worker | **NULL on consume/invalidate** | envelope | Mahmoud | n/a |
| `destination_ciphertext` | personal | Worker send only | worker | **NULL on consume/invalidate** | envelope | Mahmoud | owner_approved_2026-08-27 |
| `attempts`, `max_attempts`, `expires_at`, `consumed_at`, `invalidated_at` | internal | Lifecycle | app | as row | at rest | Mahmoud | n/a |

Engineering row TTL: 30 days after consume/invalidate, then `DELETE`.

### `mfa_factors`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id`, `user_id`, `factor_type` | internal | Factor identity | app | until disabled + purge | at rest | Mahmoud | n/a |
| `secret_ciphertext` | credential | TOTP secret | app (audited decrypt) | tombstone on disable | envelope | Mahmoud | n/a |
| `verified_at`, `disabled_at`, `last_used_counter` | internal | Lifecycle | app | as row | at rest | Mahmoud | n/a |

### `mfa_recovery_codes`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `code_hash` | credential | One-time backup | app | delete unused on rotate/disable | HMAC | Mahmoud | n/a |
| `consumed_at` | internal | Single use | app | as row until parent delete | at rest | Mahmoud | n/a |

Plaintext codes exist only in the enroll/rotate HTTP response, once.

### `mfa_challenges`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id`, `user_id`, `client_class`, `platform`, `device_label` | internal | In-flight MFA | app | 24h then delete | at rest | Mahmoud | n/a |
| `expires_at`, `consumed_at`, `attempts` | internal | Lifecycle | app | as row | at rest | Mahmoud | n/a |

### `user_devices`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id`, `user_id`, `client_class`, `platform`, `device_label` | personal | Device record | app | until revoked + TTL | at rest | Mahmoud | owner_approved_2026-08-27 |
| `refresh_token_hash`, `previous_refresh_token_hash`, `access_token_hash` | credential | Token binding | app | as row until delete | HMAC | Mahmoud | n/a |
| `refresh_replay_ciphertext` | credential | Lost-response replay | app | TTL `refresh_replay_expires_at` then NULL | envelope | Mahmoud | n/a |
| `refresh_replay_idempotency_hmac` | internal | Replay key binding | app | as ciphertext | HMAC | Mahmoud | n/a |
| `revoked_at`, `expires_at` | internal | Lifecycle | app | as row | at rest | Mahmoud | n/a |

### `auth_sessions`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id`, `user_id`, `device_id`, `session_kind` | internal | Session row | app | revoked row TTL then delete | at rest | Mahmoud | n/a |
| `session_hash` / access hash | credential | Cookie or bearer bind | app | as row until delete | HMAC | Mahmoud | n/a |
| `assurance_level` | internal | AAL recorded server-side | app | as row | at rest | Mahmoud | n/a |
| `absolute_expires_at`, `revoked_at`, `revoked_reason` | internal | Lifetime | app | as row | at rest | Mahmoud | n/a |

Revoked sessions are marked first, then deleted after the engineering TTL (90 days). That is not a legal medical-record period.

### `identity_profile_links`

Phase 01 identity-to-profile binding
(`2026_08_26_200000_create_identity_and_access_tables.php`). Polymorphic
`profile_type` is constrained to `patient`, `doctor`, `clinic_staff`,
`pharmacy_membership`; `link_status` to `pending`, `active`, `revoked`,
`disputed`. Partial unique index on `(profile_type, profile_id)` where
`link_status = 'active'`. `ON DELETE CASCADE` from `users`.

**Writer.** Identity module owns the table. `clinic_app` has table DML;
`clinic_worker` is revoked. `FEATURE_IDENTITY_PROFILE_CLAIM` defaults to
**false**. No PHP service currently inserts rows; `ResolveActorContext` passes
empty link ids. `MeQuery` would expose link ids if rows existed.

**PII / sensitive / credential.** **Personal identity-linking data:** `user_id`
plus `profile_type`/`profile_id` binds a person to a later patient/doctor/staff
/membership profile. `proof_reference` is a nullable UUID (opaque pointer, not
the proof document). Not a credential store.

**Retention / deletion.** No clinic prune job. User-row `DELETE` would cascade;
subject-erasure is **not** implemented as an operator workflow. Legal retention
period: **pending**.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Link row identity | app | until row deleted | at rest | Mahmoud | n/a |
| `user_id` | personal | Which identity is linked | app | as row; CASCADE if user deleted | at rest | Mahmoud | owner_approved_2026-08-27 |
| `profile_type` | personal | Kind of profile bound to the user | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `profile_id` | personal | Target profile UUID | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `link_status` | internal | pending / active / revoked / disputed | app | as row | at rest | Mahmoud | n/a |
| `assurance_level` | internal | Recorded assurance at link time | app | as row | at rest | Mahmoud | n/a |
| `proof_reference` | internal | Optional UUID pointing at a proof artifact (not the artifact) | app | as row | at rest | Mahmoud | n/a |
| `linked_at`, `revoked_at` | internal | Link lifecycle | app | as row | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

`owner_approved_2026-08-27` is owner acceptance of documented identity
processing, not an Egyptian PDPL article.

### `auth_refresh_consumptions`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `family_id`, `generation` | internal | Refresh family ledger | app | with family | at rest | Mahmoud | n/a |
| `token_hash` | credential | Consumed refresh HMAC | app | with family | HMAC | Mahmoud | n/a |
| `consumed_at` | internal | When the generation was retired | app | as row | at rest | Mahmoud | n/a |

### `recovery_requests`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id`, `user_id`, `otp_id`, `status` | internal | Recovery state machine | app, operator | 90 days after terminal status | at rest | Mahmoud | owner_approved_2026-08-27 |
| `new_password_hash` | credential | Proposed password | app | NULL after apply/reject | Argon2id | Mahmoud | n/a |
| `cooling_off_until`, `applied_at` | internal | Cooling-off / apply | app | as row | at rest | Mahmoud | n/a |

Privileged recoveries stay `manual_review` until an AAL2 operator applies.
Patient cooling-off uses `IDENTITY_RECOVERY_COOLING_OFF_SECONDS` (default 86400;
tests may set 0).

### `contextual_access_grants`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| capability, resource, context ids | internal | Resource-scoped grant | app | until revoked + TTL | at rest | Mahmoud | n/a |
| `issued_by_id` | internal | Initiator, never client-supplied | app | as row | at rest | Mahmoud | n/a |

### `audit_events`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `event_name`, `object_*`, `actor_*` | internal | Append-only trail | app SELECT; insert via DEFINER function | retained (not deleted by prune; owner-approved) | at rest | Mahmoud | owner_approved_2026-08-27 |
| `metadata` | internal | Reason codes / ids only | app | as row | at rest | Mahmoud | n/a |
| `row_hash`, `previous_hash`, `chain_sequence` | internal | Tamper-evident chain | verifier | as row | at rest | Mahmoud | n/a |

Serving role: `SELECT` + `EXECUTE clinic_append_audit_event`. No table INSERT.

### Events

| Event | Version | Class | Payload | Consumers | Retention |
| --- | --- | --- | --- | --- | --- |
| `auth.otp_delivery_requested` | 1 | internal | challenge id / handle only | OTP worker | 7 days |
| `auth.session_revoked` | 1 | internal | session/user ids, reason | disconnect consumer | 7 days |
| `auth.credential_version_changed` | 1 | internal | user id, credential_version, reason_code | later projections | 7 days |
| `access.grant_issued` / `access.grant_revoked` | 1 | internal | identifiers | later projections | 7 days |
| `identity.account_registered` | 1 | personal | user id, status, locale | later projections | 7 days |
| `identity.phone_verified` | 1 | personal | user id, verified_at | later projections | 7 days |
| `identity.status_changed` | 1 | personal | user id, old/new status, reason_code | later projections | 7 days |
| `identity.profile_linked` | 1 | personal | user_id, profile_type, profile_id, assurance_level | later projections | 7 days |

`credential` classification is rejected by the outbox CHECK. Event retention
above is the outbox `PROCESSED` engineering default, not a legal schedule.

---

## Gaps (explicitly OPEN)

1. **Day counts remain engineering defaults**, not a statutory retention
   schedule. Clinical and financial retention in Egypt still needs a written
   schedule if the product stores those records.
2. **`lawful_basis` is `owner_approved_2026-08-27`** (Mahmoud, privacy owner)
   for documented identity processing. That is owner acceptance of purpose, not
   an Egyptian PDPL article number and not independent legal certification.
3. **Subject-erasure for production** is not operator self-serve. Engineering
   purge jobs cover expired OTP/session ciphertext only
   ([deletion-and-purge.md](deletion-and-purge.md)). A full rights workflow is
   still unimplemented.
4. **Audit-row erasure** is not implemented (append-only). A legal order would
   need a Phase 22/23 procedure.
5. **Framework operational tables** (`jobs`, `job_batches`, `failed_jobs`,
   `cache`, `cache_locks`, `sessions`) have no clinic prune job and no approved
   legal retention period. Laravel worker deletion of completed `jobs` rows and
   session lottery sweeps are engineering behavior only. `lawful_basis` for
   session IP/user-agent/payload and for `failed_jobs.exception` / job payloads
   that may identify a person is **pending**.
6. **`identity_profile_links` retention / erasure period** is **pending**. The
   table is inventoried; no writer currently populates it.

Tracked as G-05-02. **G-08-04 stays OPEN** until independent retest; owner
approval does not close that gate.
