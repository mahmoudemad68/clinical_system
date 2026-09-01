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
Laravel worker when the job completes. There is no clinic subject-erasure
path for `jobs`. Legal retention period: **OPEN_LEGAL_DECISION**.

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

**Retention / deletion.** Scheduled `queue:prune-failed --hours=` uses
`platform.queue.failed_job_retention_hours` (ENGINEERING_DEFAULT 168). Not a
statutory period. Not covered by `auth:prune-expired` or `platform:prune`.
Legal retention: **OPEN_LEGAL_DECISION**.

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
lottery `[2, 100]` may delete expired rows when that driver is used. Subject
erasure DELETEs rows where `user_id` matches the erased subject
(`EraseSubjectService` via Platform `DiscardSubjectTransientCopies`). Not
covered by `auth:prune-expired` (that command prunes `auth_sessions` /
OTP ciphertext). Legal retention: **OPEN_LEGAL_DECISION**.

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
| `patient.profile_created` | 1 | personal | `patient_id`, `linked_user_id` nullable, `source_type` | later projections | 7 days |
| `patient.account_linked` | 1 | personal | `patient_id`, `user_id`, `assurance_level` | later projections | 7 days |

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

Every metric is `internal`. Label **keys** are allowlisted in
`PlatformMetrics::ALLOWED_LABELS`. Forbidden keys (`patient_id`, `doctor_id`,
`appointment_id`, `prescription_id`, `file_id`, `user_id`) throw. Values are
bounded enums/classes from code, not phones, tokens, or names.

Always-present labels on rendered samples: `service`, `version`.

### Phase 00 families

| Family | Type | Labels actually set | Personal/sensitive values? |
| --- | --- | --- | --- |
| `clinic_http_responses_total` | counter | `method`, `route`, `status`, `status_class` | No |
| `clinic_http_request_duration_seconds` | gauge | `method`, `route`, `status_class` | No |
| `clinic_readiness_status` | gauge | none beyond service/version | No |
| `clinic_dependency_status` | gauge | `check` | No |
| `clinic_outbox_pending_total` / `clinic_outbox_dead_letter_total` / `clinic_outbox_oldest_pending_age_seconds` | gauge | none | No |
| `clinic_db_connections_in_use` / `clinic_db_connections_limit` | gauge | none | No |
| `clinic_db_query_duration_seconds_bucket` | counter | `le` | No |
| `clinic_db_query_duration_seconds_sum` / `_count` | counter | none | No |
| `clinic_horizon_queue_depth` | gauge | `queue` | No |
| `clinic_redis_errors_total` | counter | `connection` | No |
| `clinic_reverb_connections` | gauge | none (exported as 0 until Reverb counts) | No |
| `clinic_provider_failures_total` | counter | `error_class` (`push` from Firebase adapter) | No |
| `clinic_redaction_canary_total` | counter | `rule` | No |

### Phase 01 auth/identity families

Registered on `PlatformMetrics`. Call sites inspected 2026-08-28.

| Family | Type | Labels / values actually emitted | Callers | Personal/sensitive values? |
| --- | --- | --- | --- | --- |
| `clinic_auth_attempts_total` | counter | `result` (`unknown`/`denied`/`issued`/`mfa_required`/`refresh_reuse`/`revoked`), `method` (`password`/`refresh`/`session`), `actor_class` (`patient`/`doctor`/`pharmacy`/`secretary`/`admin`/`unknown`) | `AuthenticatePasswordService`, `RefreshDeviceSessionService`, `SessionRevokedConsumer` | No. `actor_class` is `AccountType::value` or `unknown`, never a user id |
| `clinic_otp_requests_total` | counter | `purpose` (otp purpose string), `result` (`sent`/`provider_disabled`) | `OtpDeliveryConsumer` only. `AuthTelemetry::otp()` has no other production caller | No. Purpose is `registration`/`recovery`/… |
| `clinic_mfa_challenges_total` | counter | `result` (`expired`/`denied`/`issued`) | `CompleteMfaService` | No |
| `clinic_authorization_decisions_total` | counter | *(family registered)* | `AuthTelemetry::authorization()` has **no production caller** as of this inventory | n/a until emitted |
| `clinic_profile_claims_total` | counter | `result` (`manual_review`), `assurance_level` (`aal1`) | `LinkVerifiedPatientAccount` | No |
| `clinic_otp_delivery_age_seconds` | gauge | `purpose` | `OtpDeliveryConsumer`; value is age in seconds | No |
| `clinic_session_revocation_latency_seconds` | gauge | `client_class` (`unknown`) | `SessionRevokedConsumer` | No |
| `clinic_active_sessions` | gauge | `client_class` (`all`) | `auth:prune-expired` | No. Count only |
| `clinic_auth_latency_seconds` | gauge | *(family registered)* | **no production `set`/`increment` caller** | n/a until emitted |

**No metric may be labelled** with a patient, doctor, appointment, file,
prescription, user, or free-text value. `Classification::allowedAsMetricLabel()`
encodes the rule; `PlatformMetrics::assertLabels()` enforces the allowlist.

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
| Flutter `TokenStore` envelope | credential | OS secure storage via `ClinicSecureStorage` (`flutter_secure_storage`) | until `TokenStore.clear()` (logout / fail-closed wipe) | Key `auth.envelope.v1` holds JSON `{version, access, refresh}`. Legacy `auth.access` / `auth.refresh` are deleted after envelope write. Values must not go to Drift, analytics, or crash reports. Android `migrateWithBackup: false`; iOS/macOS `this_device` + `synchronizable: false`. |
| Electron device token vault | credential | Main-process file `{userData}/{namespace}.bin` wrapped with `safeStorage.encryptString` | until `clearDeviceTokens()` | Doctor namespace `eg.clinic.doctor.device`; pharmacy `eg.clinic.pharmacy.device`. Payload is JSON `{access, refresh}` then base64 before OS wrap. Persist is refused when `assessLocalEncryption()` disallows (Linux `basic_text` / unknown backend). Renderer must not import this module. |
| Phase 02 object files | — | private object storage | — | No product file type is defined until Phase 02. |

Private object storage is provisioned; S3 object types remain Phase 02.

---

## Phase 01 tables (identity and access)

Engineering draft. Synthetic data only in tests. `lawful_basis` is the privacy
owner's acceptance of the documented purpose (`owner_approved_2026-08-27`).
It is not a statutory citation.

### `users`

**Writer.** Identity module via `clinic_app`. Worker has no table DML.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Actor identifier | app, worker (FK only) | until account erasure (owner-approved engineering procedure) | at rest | Mahmoud | n/a |
| `name` | personal | Display; never authorization input | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `phone_e164_encrypted` | personal | Contact / login | app | as row; rotate via KMS path | envelope | Mahmoud | owner_approved_2026-08-27 |
| `phone_lookup_hmac` | personal | Blind lookup | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |
| `phone_key_version` | internal | Envelope key version for the phone columns | app | as row | at rest | Mahmoud | n/a |
| `password_hash` | credential | Authentication | app | as row | Argon2id | Mahmoud | n/a |
| `account_type` | internal | Server-owned actor class (`patient`/`doctor`/`pharmacy`/`secretary`/`admin`) | app | as row | at rest | Mahmoud | n/a |
| `status` | internal | Server-owned account state | app | as row | at rest | Mahmoud | n/a |
| `language` | internal | `ar` or `en` | app | as row | at rest | Mahmoud | n/a |
| `credential_version` | internal | Session invalidation generation | app | as row | at rest | Mahmoud | n/a |
| `phone_verified_at` | internal | When phone OTP completed; required for `active` unless `bootstrap_exempt` | app | as row | at rest | Mahmoud | n/a |
| `last_authenticated_at` | internal | Last successful authentication timestamp | app | as row | at rest | Mahmoud | n/a |
| `bootstrap_exempt` | internal | Allows `active` without `phone_verified_at` for bootstrap identities | app | as row | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

### `identity_national_ids`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Row identity | app | as row | at rest | Mahmoud | n/a |
| `user_id` | internal | FK to `users` (CASCADE) | app | as row | at rest | Mahmoud | n/a |
| `national_id_encrypted` | sensitive | Recovery / later verification | app (audited decrypt) | until erasure (owner-approved engineering TTL) | envelope | Mahmoud | owner_approved_2026-08-27 |
| `national_id_lookup_hmac` | sensitive | Blind match | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |
| `key_version` | internal | Envelope/HMAC key version | app | as row | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

### `otp_requests`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Challenge id | app, worker | row TTL then delete | at rest | Mahmoud | n/a |
| `purpose` | internal | `registration` / `phone_change` / `recovery` / `profile_claim` | app | as row | at rest | Mahmoud | n/a |
| `subject_lookup_hmac` | personal | Blind phone handle | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |
| `code_hash` | credential | Verify attempt | app | until row delete | HMAC | Mahmoud | n/a |
| `code_ciphertext` | credential | Worker send only | worker | **NULL on consume/invalidate**; `auth:prune-expired` also NULLs leftover ciphertext | envelope | Mahmoud | n/a |
| `attempts` | internal | Verify tries | app | as row | at rest | Mahmoud | n/a |
| `max_attempts` | internal | Cap | app | as row | at rest | Mahmoud | n/a |
| `expires_at` | internal | Challenge expiry | app | as row | at rest | Mahmoud | n/a |
| `consumed_at` | internal | Successful consume | app | as row | at rest | Mahmoud | n/a |
| `invalidated_at` | internal | Expire / replace | app | as row | at rest | Mahmoud | n/a |
| `requested_ip_prefix` | personal | Truncated requester IP (nullable) | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `device_fingerprint_hmac` | personal | Blind device fingerprint (nullable) | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |
| `provider_message_reference` | internal | Provider message id after send (nullable) | app, worker | as row | at rest | Mahmoud | n/a |
| `locale` | internal | OTP language | worker | as row | at rest | Mahmoud | n/a |
| `destination_ciphertext` | personal | Worker send only | worker | **NULL on consume/invalidate**; prune NULLs leftover | envelope | Mahmoud | owner_approved_2026-08-27 |
| `key_version` | internal | Envelope key version | app, worker | as row | at rest | Mahmoud | n/a |
| `delivery_status` | internal | `pending` / `sent` / `retryable` / `failed` | app, worker | as row | at rest | Mahmoud | n/a |
| `created_at` | internal | Row created | app | as row | at rest | Mahmoud | n/a |

Engineering row TTL: `IDENTITY_OTP_ROW_DAYS` (default 30) after consume/invalidate, then `DELETE` when ciphertext is already NULL. Not a legal period.

### `mfa_factors`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Factor identity | app | until disabled + purge | at rest | Mahmoud | n/a |
| `user_id` | internal | FK to `users` | app | as row | at rest | Mahmoud | n/a |
| `factor_type` | internal | `totp` only in V1 | app | as row | at rest | Mahmoud | n/a |
| `secret_ciphertext` | credential | TOTP secret | app (audited decrypt) | tombstone on disable | envelope | Mahmoud | n/a |
| `key_version` | internal | Envelope key version | app | as row | at rest | Mahmoud | n/a |
| `last_used_counter` | internal | TOTP counter | app | as row | at rest | Mahmoud | n/a |
| `last_used_at` | internal | Last successful TOTP | app | as row | at rest | Mahmoud | n/a |
| `verified_at` | internal | When the factor was confirmed | app | as row | at rest | Mahmoud | n/a |
| `disabled_at` | internal | Disable timestamp | app | as row | at rest | Mahmoud | n/a |
| `disabled_by` | internal | Actor who disabled (nullable UUID) | app | as row | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

### `mfa_recovery_codes`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Code row | app | until parent delete | at rest | Mahmoud | n/a |
| `user_id` | internal | FK | app | as row | at rest | Mahmoud | n/a |
| `factor_id` | internal | Parent factor (CASCADE) | app | as row | at rest | Mahmoud | n/a |
| `code_hash` | credential | One-time backup | app | delete unused on rotate/disable | HMAC | Mahmoud | n/a |
| `consumed_at` | internal | Single use | app | as row until parent delete | at rest | Mahmoud | n/a |
| `created_at` | internal | Issued at | app | as row | at rest | Mahmoud | n/a |

Plaintext codes exist only in the enroll/rotate HTTP response, once.

### `mfa_challenges`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Challenge id | app | `auth:prune-expired` deletes when `expires_at` is older than 1 day | at rest | Mahmoud | n/a |
| `user_id` | internal | Which user must complete MFA | app | as row | at rest | Mahmoud | n/a |
| `client_class` | internal | Client class string | app | as row | at rest | Mahmoud | n/a |
| `platform` | internal | Device platform | app | as row | at rest | Mahmoud | n/a |
| `device_label` | personal | Client-supplied device label | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `expires_at` | internal | Challenge expiry | app | as row | at rest | Mahmoud | n/a |
| `consumed_at` | internal | Completed at | app | as row | at rest | Mahmoud | n/a |
| `attempts` | internal | Verify tries | app | as row | at rest | Mahmoud | n/a |
| `created_at` | internal | Issued at | app | as row | at rest | Mahmoud | n/a |

### `user_devices`

There is no `client_class` column on this table (that field lives on `mfa_challenges`). Access tokens are bound as `token_hash`, not `access_token_hash`.

**Writer.** Auth module via `clinic_app`. On device revoke, hashes and `push_token_ciphertext` are NULLed.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Device row | app | until revoked; then ENGINEERING_DEFAULT `IDENTITY_REVOKED_DEVICE_DAYS` (default 90) via `auth:prune-expired`; subject erasure DELETEs remaining rows | at rest | Mahmoud | n/a |
| `user_id` | personal | Which identity owns the device | app | as row; CASCADE if user deleted | at rest | Mahmoud | owner_approved_2026-08-27 |
| `platform` | internal | `android`/`ios`/`windows`/`macos`/`linux`/`web` | app | as row | at rest | Mahmoud | n/a |
| `device_label` | personal | Client-supplied device label | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `token_hash` | credential | Access-token bind (nullable; unique while active) | app | NULL on revoke | HMAC | Mahmoud | n/a |
| `refresh_token_hash` | credential | Current refresh bind | app | NULL on revoke | HMAC | Mahmoud | n/a |
| `previous_refresh_token_hash` | credential | Prior generation for lost-response | app | NULL on revoke | HMAC | Mahmoud | n/a |
| `refresh_family_id` | internal | Refresh family UUID | app | as row | at rest | Mahmoud | n/a |
| `refresh_generation` | internal | Family generation | app | as row | at rest | Mahmoud | n/a |
| `credential_version` | internal | Copied from user at issue | app | as row | at rest | Mahmoud | n/a |
| `last_seen_at` | internal | Last use | app | as row | at rest | Mahmoud | n/a |
| `expires_at` | internal | Access expiry | app | as row | at rest | Mahmoud | n/a |
| `refresh_expires_at` | internal | Refresh expiry | app | as row | at rest | Mahmoud | n/a |
| `revoked_at` | internal | Revoke timestamp | app | as row | at rest | Mahmoud | n/a |
| `revoked_reason` | internal | Reason code | app | as row | at rest | Mahmoud | n/a |
| `push_token_ciphertext` | credential | FCM device token envelope (nullable; currently written NULL at issue) | app | NULL on revoke | envelope | Mahmoud | n/a |
| `created_ip_prefix` | personal | Truncated IP at device create (nullable; currently written NULL at issue) | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `refresh_replay_ciphertext` | credential | Lost-response replay envelope | app | TTL `refresh_replay_expires_at` then NULL via prune | envelope | Mahmoud | n/a |
| `refresh_replay_idempotency_hmac` | internal | Replay key binding | app | NULLed with ciphertext | HMAC | Mahmoud | n/a |
| `refresh_replay_expires_at` | internal | Replay envelope expiry | app | NULLed with ciphertext | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

### `auth_sessions`

**Writer.** Auth module via `clinic_app`. Distinct from Laravel `sessions`.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Session row | app | revoked row TTL then delete | at rest | Mahmoud | n/a |
| `user_id` | internal | FK to `users` | app | as row | at rest | Mahmoud | n/a |
| `device_id` | internal | FK to `user_devices` (nullable for admin cookie) | app | as row | at rest | Mahmoud | n/a |
| `session_kind` | internal | `device` or `admin_cookie` | app | as row | at rest | Mahmoud | n/a |
| `session_hash` | credential | Cookie or bearer bind (unique) | app | as row until delete | HMAC | Mahmoud | n/a |
| `assurance_level` | internal | AAL recorded server-side | app | as row | at rest | Mahmoud | n/a |
| `csrf_established` | internal | Admin cookie CSRF handshake | app | as row | at rest | Mahmoud | n/a |
| `idle_expires_at` | internal | Idle timeout (nullable) | app | as row | at rest | Mahmoud | n/a |
| `absolute_expires_at` | internal | Hard expiry | app | as row | at rest | Mahmoud | n/a |
| `credential_version` | internal | Copied from user at issue | app | as row | at rest | Mahmoud | n/a |
| `revoked_at` | internal | Revoke timestamp | app | as row until DELETE | at rest | Mahmoud | n/a |
| `revoked_reason` | internal | Reason code | app | as row | at rest | Mahmoud | n/a |
| `last_seen_at` | internal | Last request | app | as row | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

Expired live sessions are marked `revoked_at` first (`revoked_reason=expired`). Rows with `revoked_at` older than `IDENTITY_REVOKED_SESSION_DAYS` (default 90) are `DELETE`d by `auth:prune-expired`. That is an engineering TTL, not a legal medical-record period.

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

**Retention / deletion.** No clinic prune job for stale links. User-row
`DELETE` would cascade; Phase-01 subject erasure DELETEs the subject's
link rows (`EraseSubjectService`). Legal retention period:
**OPEN_LEGAL_DECISION**.

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

### `patient_profiles`

Phase 02 chunk 01 demographic profiles
(`2026_09_01_150000_create_patient_profile_tables.php`). One authoritative
(non-merged) row per National ID blind index. `user_id` attaches to at most
one profile. Ownership is this column, not `identity_profile_links`.

**Writer.** Patients module via `clinic_app`. `clinic_worker` and
`clinic_reporter` are revoked. Height/weight CHECKs are named
ENGINEERING_DEFAULT storage bounds, not clinical protocol.

**PII / sensitive.** National ID and full name are envelope-encrypted.
Lookup is HMAC only. HTTP projections never return ciphertext, HMAC, or
key versions.

**Retention / deletion.** Linked-profile subject erasure tombstones crypto
fields, unlinks `user_id`, and sets `archived`. Unlinked walk-in profiles
are not selected by user-id erasure. Legal retention:
**OPEN_LEGAL_DECISION**.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | UUIDv7 profile identity | app | until row deleted | at rest | Mahmoud | n/a |
| `user_id` | personal | Attached account (nullable unique while set) | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `national_id_ciphertext` | sensitive | Recovery / later verification | app (audited decrypt) | until erasure tombstone | envelope | Mahmoud | owner_approved_2026-08-27 |
| `national_id_lookup_hmac` | sensitive | Blind match; unique while `status <> merged` | app | as row | HMAC | Mahmoud | owner_approved_2026-08-27 |
| `national_id_key_version` | internal | Envelope/HMAC key version | app | as row | at rest | Mahmoud | n/a |
| `full_name_ciphertext` | personal | Display name | app | until erasure tombstone | envelope | Mahmoud | owner_approved_2026-08-27 |
| `gender` | personal | ENGINEERING_DEFAULT closed vocabulary | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `date_of_birth` | personal | Self-reported date (nullable) | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `height_cm` | personal | Self-reported; ENGINEERING_DEFAULT bounds | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `weight_kg` | personal | Self-reported; ENGINEERING_DEFAULT bounds | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `marital_status` | personal | Self-reported closed vocabulary (nullable) | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `blood_type` | personal | Self-reported ABO/Rh; not lab-verified | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `status` | internal | `active` / `disputed` / `merged` / `restricted` / `archived` | app | as row | at rest | Mahmoud | n/a |
| `created_by_type` / `created_by_id` | internal | Provenance | app | as row | at rest | Mahmoud | n/a |
| `version` | internal | Optimistic concurrency | app | as row | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

### `patient_demographic_revisions`

Append-only demographic field history. No clinical facts. Mutation is
denied by trigger. `clinic_app` may SELECT+INSERT only.

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Revision identity | app | retained with profile | at rest | Mahmoud | n/a |
| `patient_profile_id` | internal | Parent profile | app | as row | at rest | Mahmoud | n/a |
| `field_name` | internal | Allowlisted demographic field | app | as row | at rest | Mahmoud | n/a |
| `old_protected` / `new_protected` | personal | Previous/new name ciphertext when the field is `full_name` | app | as row; erasure residual | envelope | Mahmoud | owner_approved_2026-08-27 |
| `old_plain` / `new_plain` | personal | Previous/new non-name demographic value | app | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `actor_type` / `actor_id` | internal | Who changed the field | app | as row | at rest | Mahmoud | n/a |
| `reason_code` / `source_type` | internal | Why / which workflow | app | as row | at rest | Mahmoud | n/a |
| `profile_version` | internal | Profile version after the change | app | as row | at rest | Mahmoud | n/a |
| `request_id` | internal | Correlation identifier | app | as row | at rest | Mahmoud | n/a |
| `created_at` | internal | When the revision was appended | app | as row | at rest | Mahmoud | n/a |

### `auth_refresh_consumptions`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `family_id` | internal | Refresh family ledger | app | ENGINEERING_DEFAULT `IDENTITY_REFRESH_CONSUMPTION_DAYS` then delete | at rest | Mahmoud | n/a |
| `token_hash` | credential | Consumed refresh HMAC | app | with family | HMAC | Mahmoud | n/a |
| `generation` | internal | Family generation | app | with family | at rest | Mahmoud | n/a |
| `consumed_at` | internal | When the generation was retired | app | as row | at rest | Mahmoud | n/a |

### `recovery_requests`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Recovery id | app, operator | 90 days after terminal status (ENGINEERING_DEFAULT; `auth:prune-expired`) | at rest | Mahmoud | owner_approved_2026-08-27 |
| `user_id` | internal | FK to `users` | app, operator | as row | at rest | Mahmoud | n/a |
| `otp_id` | internal | Challenge that authorized recovery | app | as row | at rest | Mahmoud | n/a |
| `status` | internal | `cooling_off` / `manual_review` / `applied` / `rejected` / `expired` | app, operator | as row | at rest | Mahmoud | n/a |
| `new_password_hash` | credential | Proposed password | app | NULL after apply/reject | Argon2id | Mahmoud | n/a |
| `cooling_off_until` | internal | Patient cooling-off deadline | app | as row | at rest | Mahmoud | n/a |
| `applied_at` | internal | When applied | app | as row | at rest | Mahmoud | n/a |
| `created_at`, `updated_at` | internal | Row lifecycle | app | as row | at rest | Mahmoud | n/a |

Privileged recoveries stay `manual_review` until an AAL2 operator applies.
Patient cooling-off uses `IDENTITY_RECOVERY_COOLING_OFF_SECONDS` (default 86400;
tests may set 0).

### `contextual_access_grants`

**Writer.** Access module via `clinic_app`. Obsolete revoked/expired rows are
deleted by `access:prune-expired` (ENGINEERING_DEFAULT
`IDENTITY_REVOKED_GRANT_DAYS`, default 90).

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Grant identity | app | until revoked/expired; then ENGINEERING_DEFAULT TTL delete | at rest | Mahmoud | n/a |
| `actor_user_id` | internal | Grantee (FK `users`) | app | as row | at rest | Mahmoud | n/a |
| `capability` | internal | Action string | app | as row | at rest | Mahmoud | n/a |
| `resource_type` | internal | Resource class | app | as row | at rest | Mahmoud | n/a |
| `resource_id` | internal | Resource UUID | app | as row | at rest | Mahmoud | n/a |
| `context_type` | internal | Context class | app | as row | at rest | Mahmoud | n/a |
| `context_id` | internal | Context UUID | app | as row | at rest | Mahmoud | n/a |
| `valid_from` | internal | Not-before (nullable) | app | as row | at rest | Mahmoud | n/a |
| `valid_until` | internal | Not-after (nullable) | app | as row | at rest | Mahmoud | n/a |
| `revoked_at` | internal | Revoke timestamp | app | as row | at rest | Mahmoud | n/a |
| `reason_code` | internal | Why issued/revoked | app | as row | at rest | Mahmoud | n/a |
| `issued_by_type` | internal | Initiator kind (never client-supplied) | app | as row | at rest | Mahmoud | n/a |
| `issued_by_id` | internal | Initiator UUID | app | as row | at rest | Mahmoud | n/a |
| `version` | internal | Optimistic version | app | as row | at rest | Mahmoud | n/a |
| `created_at` | internal | Issued at | app | as row | at rest | Mahmoud | n/a |

### `audit_events`

| Field | Class | Purpose | Read by | Retention | Encryption | Owner | lawful_basis |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `id` | internal | Event row | app SELECT; insert via DEFINER function | retained (not deleted by prune) | at rest | Mahmoud | n/a |
| `event_name` | internal | Stable event name | app SELECT | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `actor_id` | internal | Actor UUID (nullable) | app SELECT | as row | at rest | Mahmoud | owner_approved_2026-08-27 |
| `actor_type` | internal | Actor kind (nullable) | app SELECT | as row | at rest | Mahmoud | n/a |
| `object_type` | internal | Object class | app SELECT | as row | at rest | Mahmoud | n/a |
| `object_id` | internal | Object UUID | app SELECT | as row | at rest | Mahmoud | n/a |
| `metadata` | internal | Reason codes / ids only | app SELECT | as row | at rest | Mahmoud | n/a |
| `previous_hash` | internal | Prior chain hash | verifier | as row | at rest | Mahmoud | n/a |
| `row_hash` | internal | This row hash | verifier | as row | at rest | Mahmoud | n/a |
| `occurred_at` | internal | Event time | app SELECT | as row | at rest | Mahmoud | n/a |
| `chain_sequence` | internal | Serialized chain position | verifier | as row | at rest | Mahmoud | n/a |

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
| `patient.profile_created` | 1 | personal | patient_id, linked_user_id nullable, source_type | later projections | 7 days |
| `patient.account_linked` | 1 | personal | patient_id, user_id, assurance_level | later projections | 7 days |

`credential` classification is rejected by the outbox CHECK. Event retention
above is the outbox `PROCESSED` engineering default, not a legal schedule.

---

## Live non-PostgreSQL holdings (Phase 00/01)

These are current runtime destinations or artefacts. They are not PostgreSQL
tables. `lawful_basis` and statutory retention for each remain
**OPEN_LEGAL_DECISION** (EXTERNAL_HUMAN). Engineering behaviour below is not a
legal approval.

### Audit checkpoint files

**Store / path.** Laravel disk `audit_checkpoints`
(`config/filesystems.php`). Default driver `local`, root
`AUDIT_CHECKPOINT_ROOT` or `storage/app/private/audit-checkpoints`, prefix
`AUDIT_CHECKPOINT_PREFIX` default `checkpoints`. Production should point
`AUDIT_CHECKPOINT_DISK` at object-lock/WORM storage that the database owner
does not control (ADR 0015).

**Contents (as actually written).** Canonical JSON
`clinic.audit.checkpoint.v1` plus a detached Ed25519 signature: `format`,
`sequence`, `row_hash` (64 hex chars of the chained audit row hash),
`checkpointed_at`, `key_id`. Files are named `*.json`. **No direct personal
data** (no phone, National ID, name, token, or ciphertext).

**Classification.** Internal security evidence.

**Purpose.** External chain tip so a database rewrite of `audit_events` can be
detected.

**Readers / writers.** Audit module (`CreateAuditChainCheckpoint`,
`FilesystemAuditChainCheckpointStore`, `audit:checkpoint-chain`,
`VerifyAuditChain`). Serving application roles do not DML these files through
PostgreSQL.

**Retention status.** Engineering: retain as chain evidence. Legal duration
for how long checkpoints must be kept, and whether they may be erased:
**OPEN_LEGAL_DECISION**.

**Deletion / preservation action.** `PRESERVE_SECURITY_AUDIT`. Subject
erasure does not rewrite or delete checkpoint files.

**Accountable owner.** Mahmoud.

### Firebase / FCM

**Destination.** Google Firebase Cloud Messaging, reached through
`kreait/laravel-firebase` (`FirebaseSendPush` implementing `SendPush`).
Credentials: `platform.firebase.credentials` /
`FIREBASE_CREDENTIALS`. Empty credentials bind `DisabledSendPush`.

This is a **third-party processor / destination** outside the Core trust
boundary. Push tokens and message envelopes leave the clinic process when a
send succeeds.

**What the current implementation sends.** Device token (from decrypted
`user_devices.push_token_ciphertext`); lock-screen `title` `Clinic` and
`body` `You have a new notice`; data map with `type` plus any **scalar**
keys from the caller. Non-scalars are dropped. The adapter test forbids
clinical phrasing such as chest pain and `patient_id` in the payload.

Do not assume future notification types or richer payloads.

**Local copy.** Encrypted `push_token_ciphertext` on `user_devices`. Subject
erasure and device revoke NULL then DELETE that column/row. **Remote FCM
token copies are OPERATIONAL_FOLLOW_THROUGH** — the server has no delivery
or ack protocol that proves Google deleted the token.

**Classification.** Credential (token) in transit to the processor; lock-screen
copy is generic.

**Retention / deletion.** Server cannot wipe FCM provider backups or device
token registries. Legal retention at the processor: **OPEN_LEGAL_DECISION**.

**Accountable owner.** Mahmoud.

### Backup artefacts

**Location / model.** PostgreSQL role `clinic_backup` is SELECT-only on
application tables (initdb `01-roles-and-extensions.sql` plus
`2026_08_26_230000_audit_definer_insert_and_ciphertext_purge.php` GRANT
SELECT). The role is not wired into the application. Intended production
model is PostgreSQL base backup plus WAL/PITR. Encrypted isolated backup
object storage and KMS wrapping are **not implemented** in this repository
(Phase 23). Phase 20 `BackupStatusService` remains a null
`UNKNOWN / not configured` adapter.

**Protection.** SELECT-only backup credential (cannot DML identity rows).
Local Compose passwords are development-only. Production backup encryption
and isolated store: not present.

**Lifecycle / rotation.** No production backup expiry/rotation job is
implemented. **OPERATIONAL_FOLLOW_THROUGH.**

**Deletion semantic.** **Subject erasure does NOT imply immediate mutation of immutable historical backups.** A completed erasure removes or tombstones
live operational identity state. Historical dump/WAL artefacts retain the
pre-erasure bytes until an externally approved backup lifecycle expires or
rewrites them. The server does not rewrite backup files as part of
`EraseSubjectService`.

**Legal retention duration.** **OPEN_LEGAL_DECISION** / EXTERNAL_HUMAN.

**Accountable owner.** Mahmoud.

---

## ISR-013 technical vs external

**Technical (this inventory + coordinator + prune tests): PASS** for the
Phase-01 field inventory including audit checkpoint files, Firebase/FCM as a
processor destination, and backup artefacts; for `EraseSubjectService` /
`ExportSubjectDataService`; for scheduled `platform:prune`; and for
engineering prune of OTP ciphertext, sessions, recovery_requests, revoked
`user_devices`, `auth_refresh_consumptions`, and obsolete contextual grants.

**External / legal / production (not claimed):** Egyptian PDPL article
numbers, qualified-reviewer lawful basis, statutory retention schedule,
whether audit evidence may legally be erased, backup legal retention, and
privacy/legal sign-off. **G-08-04 stays OPEN.**

## Gaps (explicitly OPEN)

1. **Day counts remain ENGINEERING_DEFAULT values**, not a statutory retention
   schedule. Clinical and financial retention in Egypt still needs a written
   schedule if the product stores those records. **OPEN_LEGAL_DECISION.**
2. **`lawful_basis` is `owner_approved_2026-08-27`** (Mahmoud, privacy owner)
   for documented identity processing already inventoried under that label.
   That is owner acceptance of purpose, not an Egyptian PDPL article number
   and not independent legal certification. New holdings in this file use
   **OPEN_LEGAL_DECISION**. Never treat that token as an invented article.
3. **Subject-erasure legal approval** is unresolved. Engineering coordinator
   `EraseSubjectService` tombstones Phase-01 operational identity state.
   Disable/suspend/revoke (`DisableIdentityService`) is not erasure. A
   qualified privacy/legal decision is EXTERNAL_HUMAN.
4. **Audit-row erasure** is not implemented (append-only,
   `PRESERVE_SECURITY_AUDIT`). Whether audit may legally be erased is
   **OPEN_LEGAL_DECISION**.
5. **Framework operational tables** (`jobs`, `job_batches`, `cache`,
   `cache_locks`) follow Laravel lifecycle. `failed_jobs` has scheduled
   `queue:prune-failed` using `platform.queue.failed_job_retention_hours`
   (ENGINEERING_DEFAULT 168). Legal retention remains
   **OPEN_LEGAL_DECISION**. Subject erasure deletes Laravel `sessions` rows
   for that `user_id`; it does not prune `jobs`.
6. **Backup artefacts** are not rewritten by subject erasure. Production
   expiry/rotation is **OPERATIONAL_FOLLOW_THROUGH**. Legal backup retention
   is **OPEN_LEGAL_DECISION**.
7. **Offline client vaults and remote FCM copies** cannot be physically
   wiped by the server. **OPERATIONAL_FOLLOW_THROUGH.**

Tracked as G-05-02. **G-08-04 stays OPEN** until independent retest; owner
approval does not close that gate.
