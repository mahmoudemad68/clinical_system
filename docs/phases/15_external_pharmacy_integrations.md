# Phase 15 — External Pharmacy Integrations

## Objective

Deliver secure, adapter-based inbound synchronization for pharmacy branches whose existing system remains the stock source of truth.

Each INTEGRATED branch receives a canonical, read-only PostgreSQL mirror with explicit product mapping, sync generation/cursor, idempotent upserts, freshness state, error quarantine, and review queues. Search sees only a successfully promoted complete generation or validated incremental state. Native inventory, purchasing, and POS stock-changing commands remain disabled for integrated branches.

## Plan traceability

- Sections 62-66, lines 1989-2111: NATIVE/INTEGRATED source ownership, adapter/canonical format, external product mapping, sync-run telemetry, idempotent upsert, and stale availability.
- Sections 67-68, lines 2113-2176: patient medicine and prescription-coverage search consuming the mirror.
- Section 70, lines 2195-2211: PostgreSQL search baseline.
- Sections 102-107, lines 2949-3107: integrations queue, Horizon, outbox, OpenAPI, and external-sync idempotency.
- Sections 109-114, lines 3117-3303: connector/mapping/sync tables, indexes, and Redis limitations.
- Sections 117, 119-124, lines 3346-3514: private infrastructure, throttling, audit/log privacy, and no client access to internal services.
- Sections 132-143, lines 3640-3915: medicine-search targets, scaling, readiness, and connector/dependency monitoring.
- Sections 159-162, lines 4254-4330: connector replay correctness, load, and stress.
- Sections 165-169, lines 4367-4482: environment isolation, containers, CI, migrations, and secrets.
- Sections 171-174, lines 4503-4622: supplier API/branch transfer exclusions, source-of-truth, eventual mirror consistency, and background-work rule.

## Entry criteria and dependencies

- Phase 10 provides branch operating modes, connector-service identity/capability, catalog references, and strict mode-transition guard.
- Phases 11-13 enforce that INTEGRATED branches cannot mutate native stock, purchase, or POS truth.
- Phase 14 supplies the public `BranchAvailabilityService` and freshness/redaction contract.
- Phase 00 supplies Horizon, outbox, idempotency, secret management, OpenAPI/events, telemetry, and security environments.
- At least one named external system supplies a documented, authorized, read-only API/database/export contract and test fixture.
- Pharmacy owner, external vendor, security, privacy, and operations approve credentials, deployment/egress, frequency, freshness threshold, and support ownership.

## Non-goals

- No generic arbitrary SQL connector, bidirectional stock write-back, supplier API, automated purchasing, branch transfer, remote POS control, or inventory mutation in the external source.
- No cumulative application of external quantities to the native immutable ledger.
- No patient price display, exact quantity, or confidence when data is stale.
- No schema assumption shared by all vendors; every system has its own adapter.
- No activation of an unmatched product in patient search.
- No connector access to clinical, identity, prescription, chat, AI, payment, or unrelated branch data.

## Laravel module ownership and services

### Ownership

    PharmacyIntegrations module
      connectors, credentials references, product mappings,
      sync commands/runs/pages/generations, canonical validation,
      mirror rows, freshness, quarantine/review state

    Vendor adapters
      read one external API/database/export and map to canonical records

    MedicationCatalog
      owns medication identity; mapping references it but cannot edit it

    PharmacyOrganizations
      owns branch mode/status and connector-service authorization

    MedicineDiscovery
      reads the public mirror through BranchAvailabilityService

    Native Inventory
      remains separate and receives no integrated sync movement

### Module services and external integrations

    ExternalPharmacyConnector
      probe(deadline)
      readPage(cursor, page_limit, deadline)
      acknowledgeCheckpoint(cursor)

    CanonicalProductMapper
      map(external_product)

    IntegrationMirrorWriter
      stagePage(run_id, generation_id, canonical_rows)
      promoteGeneration(run_id, generation_id)

    IntegrationAvailabilityService
      availableBranches(medication_ids, point, freshness_policy)

    ConnectorCredentialProvider
    ConnectorEgressPolicy
    SyncRunService
    Clock

- Connector implementations pass one contract suite and expose typed transient, permanent-auth, schema, mapping, timeout, and source-unavailable failures.
- Vendor integrations cannot obtain Eloquent models or generic database handles from another module; their owning service supplies typed canonical data.
- Canonical records use absolute observed quantities/status, not additive deltas. Repeating a page cannot double stock.
- A server-owned connector/branch binding determines tenant scope; no request payload selects another branch.

## Packages and runtime components

### Laravel/PHP

- Laravel 13 HTTP client/Guzzle for bounded API adapters.
- PDO/database drivers only in isolated vendor adapters using read-only, query-allowlisted credentials; no configurable arbitrary SQL text.
- Horizon integrations queue, PostgreSQL staging/mirror, outbox, audit, Prometheus, Laravel Telescope (local), and Sentry redaction.
- Symfony UID/Laravel UUIDv7, deptrac/deptrac, Larastan/PHPStan, Pest/PHPUnit, Eris, and Testcontainers/Docker vendor fixtures.
- Secret-manager adapter for external credentials; the database stores credential references, never plaintext secrets.

If a store-side relay is required, it uses a separately reviewed, signed, least-privilege HTTPS client and typed canonical contract. It does not receive Laravel database credentials.

### Clients

- Pharmacy Electron desktop uses React/TypeScript and the generated TypeScript client behind a typed preload/main boundary. It provides owner-authorized connector status/mapping review, never credential plaintext, raw database access, arbitrary endpoints, or SQL configuration.
- Admin remains a browser React application and may provide a separately capability-gated support view with safe run diagnostics, never clinical/financial/raw source payloads.

## Persistent schemas, invariants, and indexes

### PostgreSQL

    integration_connectors
      id UUIDv7 primary key
      branch_id UUID not null unique
      adapter_key string not null
      status enum DISABLED | CONFIGURING | ACTIVE | PAUSED | ERROR
      credential_reference string not null
      schedule_seconds / freshness_threshold_seconds integer not null
      configuration_version bigint not null
      last_successful_sync_at timestamptz nullable
      active_generation_id UUID nullable
      created_at / updated_at

    integration_product_mappings
      id UUIDv7 primary key
      connector_id UUID not null
      external_product_id string not null
      external_version string nullable
      master_medication_id UUID nullable
      status enum MATCHED | UNMATCHED | IGNORED | RETIRED
      mapped_by / mapped_at UUID/timestamptz nullable
      version bigint not null

    integration_sync_runs
      id UUIDv7 primary key
      connector_id / branch_id UUID not null
      generation_id UUID not null
      mode enum FULL | INCREMENTAL
      status enum ACCEPTED | RUNNING | VALIDATING | PROMOTED |
                  FAILED_RETRYABLE | FAILED_PERMANENT | CANCELLED
      start_cursor / final_cursor string nullable
      records_read / staged / matched / unmatched / failed bigint
      started_at / finished_at / promoted_at timestamptz nullable
      safe_error_code string nullable
      idempotency_key_hash bytea not null
      configuration_version bigint not null

    integration_sync_pages
      run_id UUID
      page_sequence bigint
      source_cursor / next_cursor string nullable
      source_page_hash bytea not null
      status enum STAGED | FAILED
      record_count integer
      primary key (run_id, page_sequence)

    integration_inventory_mirror_staging
      generation_id / connector_id / branch_id UUID
      external_product_id string
      master_medication_id UUID nullable
      quantity_smallest bigint nullable
      availability enum AVAILABLE | UNAVAILABLE | UNKNOWN
      external_updated_at / observed_at timestamptz
      source_record_version string nullable
      source_hash bytea not null
      primary key (generation_id, external_product_id)

    integration_inventory_mirror
      connector_id / branch_id / external_product_id UUID/string
      master_medication_id UUID not null
      quantity_smallest bigint nullable
      availability enum AVAILABLE | UNAVAILABLE | UNKNOWN
      generation_id UUID not null
      external_updated_at / observed_at / promoted_at timestamptz
      source_record_version string nullable
      primary key (connector_id, external_product_id)

    integration_record_failures
      id UUIDv7 primary key
      run_id UUID
      external_product_id_hash bytea nullable
      safe_error_code string
      payload_reference encrypted/quarantined nullable
      created_at

Indexes/constraints:

- One connector per integrated branch in V1; branch must be INTEGRATED before ACTIVE.
- Unique mapping(connector_id, external_product_id); matched mapping requires active medication.
- Unique run(connector_id, idempotency_key_hash) and generation.
- Mirror(branch_id, master_medication_id, availability), mirror(generation_id), and observed/freshness indexes.
- Quantities are nonnegative smallest-unit bigint when known; unknown quantity cannot be treated as AVAILABLE unless adapter contract provides an explicit trustworthy availability flag.
- Cursor, external IDs, versions, configuration, pages, and payload sizes are bounded.

### Hard invariants

1. NATIVE branches reject connector mirror promotion/writes; INTEGRATED branches reject native ledger/POS/purchasing writes.
2. External system is authoritative only for its bound branch; connector scope cannot be supplied by a client/page.
3. Full sync pages are invisible until the complete generation validates and atomically promotes.
4. Replaying a run/page/upsert replaces the same canonical record by stable identity/version; it never adds quantity.
5. Unmatched/retired/invalid products never appear in patient availability.
6. last_successful_sync_at advances only after successful promotion/committed incremental checkpoint.
7. When now minus last_successful_sync_at exceeds threshold, public availability is STALE/UNCERTAIN or excluded, never confidently AVAILABLE.
8. A mapping change is versioned/audited and triggers a controlled re-projection; it never rewrites native medication history.
9. Branch mode transition requires paused connector/native writes, completed reconciliation, atomic mode/version update, and credential rotation/disable.

## Detailed success, failure, concurrency, and data flows

### Configure and activate connector

1. Owner with MFA selects an allowlisted adapter for an approved INTEGRATED branch.
2. Secrets are written directly to the secret manager; Laravel stores only reference/version.
3. Egress policy validates fixed host/database identity, TLS/mTLS, port, and read-only scope.
4. Probe performs a bounded read-only health/schema check without logging records.
5. Connector remains CONFIGURING until a dry-run full sync, mappings, reconciliation, and approval pass.
6. Activation compare-and-sets branch/connector configuration and audits.

### Full synchronization

1. Scheduler/outbox creates one idempotent run for connector/configuration version.
2. Horizon worker claims it and adapter reads bounded pages with deadline/cursor.
3. Validate schema, types, IDs, quantities, timestamps, page hash, and resource limits before staging.
4. Resolve product mappings; unmatched rows enter review and are excluded from public mirror.
5. Store each page idempotently under a new generation. A duplicate page hash is a no-op; same sequence/different hash fails.
6. After final page, validate counts, uniqueness, mapping policy, snapshot completeness, freshness, and reconciliation tolerances.
7. In a short transaction lock connector/configuration, ensure branch remains INTEGRATED/ACTIVE, replace/promote matched mirror generation, advance cursor/last success, audit/outbox, commit.
8. Delete old staging by retention job only after rollback/review window.

### Incremental synchronization

Each record is an absolute canonical observation with stable external version/hash. Apply an idempotent upsert and checkpoint only after the page transaction commits. Deletes require explicit source tombstones/snapshot absence rules; silence is not deletion. If adapter cannot prove safe incremental semantics, use full generations.

### Mapping review

Authorized pharmacy/catalog reviewer sees sanitized external identifiers/name metadata and candidate medication references. Mapping command locks version, selects one master medication, records actor/reason, and schedules re-projection. It cannot create/edit master medications or reveal another connector's products.

### Failure/concurrency

- Only one promoting run per connector/config version. Another run waits/cancels safely.
- Authentication/schema/permanent mapping configuration failure pauses promotion and alerts; it is not retried indefinitely.
- Timeout/eligible source 429/5xx uses capped retry plus jitter within run deadline.
- Partial page/source outage leaves active generation unchanged and freshness continues aging.
- Credential rotation invalidates old configuration runs.
- Branch switches/suspends during sync: promotion recheck fails and staged generation remains invisible.
- PostgreSQL/Redis restart resumes from committed pages/cursor without quantity duplication.

## API, event, and job contracts

### Pharmacy/support API

    POST /api/v1/pharmacy/branches/{branch_id}/connector/configure
    POST /api/v1/pharmacy/branches/{branch_id}/connector/probe
    POST /api/v1/pharmacy/branches/{branch_id}/connector/sync-runs
    GET  /api/v1/pharmacy/branches/{branch_id}/connector/sync-runs?cursor=...
    GET  /api/v1/pharmacy/branches/{branch_id}/connector/unmatched-products?cursor=...
    PUT  /api/v1/pharmacy/branches/{branch_id}/connector/product-mappings/{mapping_id}
    POST /api/v1/pharmacy/branches/{branch_id}/connector/pause

Credentials never appear in API responses. Connector activation/mode transition uses `BranchModeTransitionService`, not a generic branch PATCH.

Stable errors include CONNECTOR_ACCESS_DENIED, BRANCH_MODE_MISMATCH, CONNECTOR_NOT_READY, CONNECTOR_AUTH_FAILED, CONNECTOR_SCHEMA_UNSUPPORTED, CONNECTOR_SOURCE_UNAVAILABLE, SYNC_ALREADY_RUNNING, SYNC_PAGE_CONFLICT, SYNC_CONFIGURATION_CHANGED, SYNC_RECONCILIATION_FAILED, PRODUCT_UNMATCHED, MAPPING_VERSION_CONFLICT, and MIRROR_STALE.

### Canonical connector contract

    schema_version
    connector_id / run_id / page_sequence
    source_cursor / next_cursor / is_last_page
    records:
      external_product_id
      source_record_version
      observed_at
      absolute_quantity_smallest nullable
      explicit_availability
      tombstone boolean

Unknown fields reject unless versioned compatibility explicitly permits them. No SQL, URL, credential, branch ID override, code, or executable expression is accepted in record payload.

### Events/jobs

- integration.sync_started/failed/promoted.v1, product_unmatched.v1, mapping_changed.v1, and mirror_became_stale/fresh.v1 use IDs/counts/status/safe error only.
- Promoted events invalidate Phase 14 availability cache by connector/branch/generation without raw product data.
- RunConnectorSync, ProcessSyncPage, ValidateGeneration, PromoteGeneration, EvaluateMirrorFreshness, and PurgeStaging are Laravel/Horizon integration jobs.
- Jobs carry stable IDs/config version/deadline, never credentials or raw pages; claim/lease, retry, dead-letter, cancel, and replay are observable.

## Client work

### Pharmacy Electron desktop (React + TypeScript)

- Owner-only connector status, last successful sync/freshness, safe error, run counters, unmatched mapping review, manual sync, and pause controls.
- No raw credentials, SQL editor, arbitrary endpoint, full payload dump, or native stock/POS controls for INTEGRATED branches.
- Clearly distinguish fresh, stale, running, failed, and read-only mirror state.
- Confirm mapping and pause actions; handle optimistic versions and unknown outcomes.
- Renderer capabilities are limited to typed status, mapping-review, manual-sync, and pause requests. Main owns device-authenticated API/realtime transport; neither renderer nor preload receives connector secrets, database drivers, arbitrary URLs, or generic IPC.
- Arabic/English, keyboard/scanner navigation, accessible tables/status, bounded exports, and formula-safe cells.

### Patient Flutter

- Reuses Phase 14 availability DTO. Stale/unknown is omitted or clearly uncertain under approved wording; no connector/vendor name is exposed.

### Admin React

- Optional operations-only aggregate status through a dedicated capability. It cannot view credentials, raw records, pharmacy financial data, or patient searches.

## Security and privacy controls

- Threat-model each adapter and deployment path. Use read-only external DB/API accounts, TLS/mTLS, certificate validation, egress allowlists, fixed hosts, query allowlists, timeouts, and resource quotas.
- Never build SQL, table, column, path, or URL from untrusted connector records/configuration. Adapter code owns fixed parameterized statements.
- Store secrets in a secret manager with per-connector/branch identity, rotation, revocation, access audit, and no logs/fixtures/events/API echo.
- Authenticate relays/services with short-lived workload identity, request signatures, nonce/timestamp replay checks, and branch-bound authorization.
- Bound pages, records, strings, quantities, timestamps, nesting, decompression, and total run bytes; quarantine malformed source payload references.
- Prevent connector confused-deputy access to native ledger, other branches, catalog administration, clinical/identity/payment/AI systems.
- Audit configuration, probe, activation, sync, mapping, promotion, stale state, mode transition, denial, and secret rotation without raw records.
- Separate connector runtime/network credentials and use least-privilege PostgreSQL tables/commands where practical.

## Test plan

### Unit/property tests

- Canonical validation, absolute upsert, mapping lifecycle, freshness, full-generation completeness, cursor/checkpoint, tombstone rules, retry classification, and mode/branch policy.
- Property tests randomize page duplication/order/failure and assert one final mirror, no additive quantity, no partial visibility, deterministic promotion, and last-success monotonicity.
- Electron renderer status/mapping state and preload/main capability validators are unit-tested with credential/SQL/URL/scope fields denied by construction.

### Integration tests

- Real PostgreSQL proves staging isolation, atomic generation promotion, duplicate pages/runs, incremental checkpoint, concurrent runs, configuration/mode changes, mapping races, and stale queries.
- Docker vendor fixtures cover API and read-only DB adapters, schema drift, timeout, auth failure, malformed/oversized data, source restart, and cursor recovery.
- Redis/Horizon restart/dead-letter/replay does not duplicate or partially promote.
- Electron main/preload integration verifies authenticated status/realtime transport, branch/config revocation, subscription cleanup, bounded export, and that no connector credential or driver reaches the renderer.

### Contract tests

- Every ExternalPharmacyConnector implementation passes canonical schema, deadlines, cancellation, paging, typed errors, no-secret logging, and idempotency tests.
- `IntegrationAvailabilityService` matches the Phase 14 native public/freshness/redaction contract.
- Generated Dart patient and TypeScript Electron/admin clients, plus current/previous event schema replay, pass.
- Electron preload/IPC capability schemas reject credential/SQL/URL fields, unregistered channels, invalid sender frames, oversized payloads, and stale branch/config versions.

### End-to-end tests

- Configure an INTEGRATED branch, dry-run/map products, promote full sync, and surface fresh public availability.
- Replay same run/page and observe unchanged quantity.
- Partial/failed sync leaves prior generation; threshold expiry removes/confirms uncertain availability.
- Unmatched products remain in review and never appear to patient.
- Native POS/purchasing/adjustment and connector writes to a NATIVE/other branch are denied.
- Packaged pharmacy Electron E2E covers safe status/mapping/manual-sync/pause flows, reconnect/unknown outcomes, and direct IPC/URL/SQL/credential/scope injection denial.

### System, performance, and security tests

- Production-shaped full/incremental sync meets agreed window without violating medicine-search p95; enforce backpressure and source rate limits.
- Stress large pages/run overlap/schema changes and verify recovery/cursor/generation correctness.
- Test SSRF, SQL injection, path traversal, malicious external strings, credential leakage, relay replay/forgery, BOLA/BFLA, cross-tenant mapping/cache, decompression/resource exhaustion, stale-data deception, and supply-chain scans.
- Hostile connector text rendered in the signed Electron candidate cannot navigate, gain Node/Electron access, invoke unregistered preload capabilities, or expose secret/native adapter state.
- Backup/restore followed by re-sync/reconciliation produces the same promoted public mirror.

## Observability, migration, and rollout

Metrics: sync/run/page counts and duration, read/staged/matched/unmatched/failed counts, source/validation/promotion error classes, retry/dead-letter, freshness age/buckets, generation size, mapping backlog, and availability exclusions. Connector/branch/product/external IDs, hosts, credentials, names, quantities, and raw errors are not labels.

Rollout:

1. Expand connector/staging/mirror schemas and deploy all connectors disabled.
2. Build one adapter against synthetic/vendor-approved fixtures and complete security review.
3. Run dry-read/stage/reconciliation for an allowlisted branch without public promotion.
4. Pause native writes, reconcile source, atomically transition the branch to INTEGRATED, activate connector, and promote.
5. Enable Phase 14 public reads only after freshness/accuracy sign-off; monitor discrepancies and stale behavior.
6. Rollback pauses connector/public mirror use and marks availability uncertain; it never falls back to native ledger or switches source silently.

## Acceptance and exit gate

- Repeated, reordered, partial, concurrent, and recovered syncs never add quantity, cross tenant, or expose incomplete generations.
- Unmatched/invalid/stale data never becomes confidently public.
- Strict branch modes block every native write on INTEGRATED and every connector write on NATIVE/other branches.
- At least one real adapter passes canonical/security/substitution contracts using read-only credentials and controlled egress.
- Search p95 remains within target while full/incremental sync and failure recovery run.
- Reconciliation, restore/rebuild, migration/rollback, audit, observability/runbooks, client/accessibility/localization, dependency/SBOM, and security/privacy approvals are evidenced.
- No bidirectional write, supplier automation, branch transfer, arbitrary SQL/URL connector, or other future feature is enabled.
