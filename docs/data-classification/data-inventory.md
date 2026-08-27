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
| `access.grant_issued` / `access.grant_revoked` | 1 | internal | identifiers | later projections | 7 days |

`credential` classification is rejected by the outbox CHECK.

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

Tracked as G-05-02. **G-08-04 stays OPEN** until independent retest; owner
approval does not close that gate.
