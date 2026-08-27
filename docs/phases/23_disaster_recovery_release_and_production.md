# Phase 23 — Disaster Recovery, Release, and Production

## Objective

Prove that authoritative medical/operational data and original files can be restored within the agreed objectives; that rebuildable/temporary subsystems can be safely reconstructed; and that the exact signed, validated release can be deployed, observed, rolled back or forward-recovered, and handed over to operations without enabling excluded V1 features.

For core medical data, the initial target is **RPO ≤5 minutes and RTO ≤60 minutes**, measured by a production-like restore/failover drill rather than inferred from backup configuration. Core monthly availability target is 99.9%; AI availability is measured separately and AI outage is not a core outage.

## Plan traceability

- Sections 40-41, lines 1392-1451: private S3 medical originals, metadata ownership, upload validation, and short-lived access.
- Sections 94 and 102-104, lines 2765-2798 and 2949-3028: AI isolation, durable queue lanes, Horizon, and transactional outbox replay.
- Sections 108-115, lines 3109-3321: PostgreSQL truth, important tables including `backup_runs`, IDs/indexes/partitioning, and Redis as temporary separated infrastructure.
- Sections 117 and 120-125, lines 3346-3535: private/encrypted infrastructure, audit/tamper evidence, log/privacy controls, and Qdrant isolation/scaling.
- Sections 126-131, lines 3536-3639: S3/database/3-2-1/Qdrant backups, mandatory restore tests, and AI/Qdrant rebuild.
- Sections 132-143, lines 3640-3915: SLO/capacity/scale/topology, AI worker separation, 99.9% core availability, health, and monitoring.
- Section 144, lines 3916-3935: safe admin visibility of last backup and component health.
- Sections 152-155, lines 4085-4181: retry/local-outbox/offline/degraded behavior during disruptions.
- Sections 160-162, lines 4268-4330: load/stress/breaking-point/recovery evidence.
- Sections 165-170, lines 4367-4502: environment isolation, containers, CI deployment, backward-compatible migrations, secrets, and feature flags.
- Sections 171-176, lines 4503-4717: explicit V1 exclusions, source ownership, consistency, background work, execution sequence, and complete production definition of done.
- Final architecture decision, lines 4718-4742: approved stack, capacity/latency/availability targets, AI independence, and critical access/prescription invariants.

## Entry criteria and dependencies

- Phases 00-21 have passed functional, authorization, reconciliation, performance, failure-isolation, observability, and production-like staging gates.
- Phase 22 supplies the signed-candidate assurance manifest, security/privacy/clinical/pharmacy evidence, and documented non-blocking compliance assumptions with no technical or safety release blocker. Legal sign-off is not required.
- Business/operations approve final RPO/RTO, maintenance, availability/error-budget, data-retention, backup-location, and incident escalation requirements. If they change the initial targets, an ADR and plan/product approval are required.
- Production accounts, domains, network, KMS/secret manager, registry, database, Redis, S3, Qdrant, monitoring, providers, app signing, DNS/LB, and on-call ownership are provisioned through reviewed infrastructure/configuration.
- Restore/failover drills target isolated owned environments and synthetic or approved encrypted backup data handling; no destructive production drill occurs without separate change authorization.

## Non-goals

- No assumption that “backup succeeded” proves restorability, integrity, completeness, RPO, or RTO.
- No use of Qdrant, Redis, analytics, read replicas, local drafts, or client caches as authoritative recovery sources.
- No database restore merely to roll back application code when that would discard valid post-deployment writes.
- No active-active multi-region, zero-RPO, zero-downtime schema contraction, or automatic disaster failover promise unless separately designed/tested.
- No permanent production debug access, shared credentials, public data stores, public backup endpoints, or admin-browser restore controls.
- No online payment, emergency specialist chat, medication alternatives/reservation, branch transfer, supplier automation, adherence, image diagnosis, multi-country, or complex admin roles.
- No production launch based on verbal/manual assurances without reproducible evidence and accountable sign-off.

## Recovery tiers and service ownership

### Source and recovery ownership

```text
PostgreSQL
  authoritative operational/medical/financial state and outbox/audit metadata

Private S3-compatible storage
  authoritative original medical/knowledge files and immutable backup artifacts

Qdrant
  rebuildable retrieval index; snapshots accelerate recovery but are not truth

Redis
  cache, locks, queues and realtime coordination; temporary/replayable

Analytics
  derived/rebuildable aggregates

Encrypted client local data
  temporary draft/cache; never a platform disaster-recovery source
```

### Recovery tiers

| Tier | Components/data | Target |
| --- | --- | --- |
| Core authoritative | PostgreSQL medical/operational state plus S3 originals and required keys/metadata | RPO ≤5 minutes; RTO ≤60 minutes initial target |
| Core delivery/realtime | outbox/queue dispatch, Reverb, notifications, chat delivery | restore/replay after core; no lost committed critical effect |
| Pharmacy/financial | stock ledger/batches/balances, invoices/payments/returns/refunds | included in authoritative PostgreSQL recovery and mandatory reconciliation |
| AI retrieval | Qdrant collections, embeddings/reranker workers | separate AI recovery objective; rebuild from S3/PostgreSQL if snapshot unusable |
| Cache/analytics | Redis caches and analytics aggregates | recreate/rebuild; no authoritative data-loss claim |

Exact non-core recovery objectives are documented per runbook and approved before release; they cannot silently expand the core RTO.

### Services and ownership

```text
BackupCatalog
BackupArtifactStore
DatabaseBackupProvider
ObjectVersionBackupProvider
VectorSnapshotProvider
KeyRecoveryVerifier
RestoreOrchestrator
RecoveryReconciler
DeploymentOrchestrator
MigrationRunner
ReleaseEvidenceStore
FeatureFlagController
```

- **Single responsibility:** backup creation, catalog, restore, reconciliation, deployment, migration, and release decision are separate.
- **Open/closed:** managed backup or object-store providers implement stable backup/restore contracts.
- **Liskov substitution:** adapters report the same typed point, integrity, encryption, retention, restore, cancellation, and failure semantics.
- **Interface segregation:** backup credentials cannot mutate live application data; app credentials cannot delete isolated backups; deployment cannot decrypt evidence unnecessarily.
- **Focused integrations:** recovery/release services own their workflow; cloud/managed-service/CLI/API providers use small purpose-specific interfaces where substitution is required.

## Packages, infrastructure, and operational tools

Versions/images are pinned and included in release/restore evidence.

- Managed PostgreSQL PITR where it meets requirements, or one ADR-selected tool such as `pgBackRest`/WAL-G with continuous WAL archiving, full/differential policy, encryption, verification, and restore support. Do not operate redundant tools without a clear ownership/runbook decision.
- Native PostgreSQL integrity/reconciliation utilities and application-level invariant queries; PgBouncer is bypassed where administrative restore tooling requires direct controlled access.
- S3-compatible versioning, encryption/KMS, object lock/immutability where approved, lifecycle, replication/copy to logically isolated storage, inventory/checksum, and private access logs.
- Qdrant snapshot/restore APIs plus Phase 16 deterministic re-ingestion tooling from original documents/metadata.
- Redis HA/persistence configuration where operationally useful, while PostgreSQL outbox/business data remains the recovery guarantee.
- GitHub Actions/approved deployment orchestrator, signed container registry, SBOM/provenance verification, infrastructure-as-code, secret manager/KMS, Prometheus/Grafana/Loki/Sentry. Laravel Telescope is local-only.
- k6 smoke/load checks, scripted reconciliation, controlled DNS/LB/failover tooling, and an encrypted access-controlled release evidence store.

## Persistent schemas, catalogs, and invariants

### Operational metadata

```text
backup_runs
  id UUIDv7 PK
  backup_type enum POSTGRES_BASE | POSTGRES_WAL | S3_COPY |
                   QDRANT_SNAPSHOT | CONFIG_EXPORT
  source_environment string
  source_component string
  recovery_point_at UTC nullable
  started_at / completed_at UTC nullable
  status enum STARTED | SUCCEEDED | FAILED | VERIFIED | EXPIRED
  artifact_set_id string nullable
  manifest_hash bytea nullable
  encryption_key_reference string nullable     # opaque reference, never key
  bytes bigint nullable
  safe_error_code string nullable
  retention_class string
  correlation_id UUID

restore_drills
  id UUIDv7 PK
  scope enum CORE_FULL | POSTGRES_PITR | S3_OBJECT | QDRANT_SNAPSHOT |
             QDRANT_REBUILD | REDIS_LOSS | RELEASE_ROLLBACK
  source_backup_run_id UUID nullable
  target_environment string
  requested_recovery_point_at UTC nullable
  actual_recovery_point_at UTC nullable
  declared_at / restore_started_at / service_ready_at / completed_at UTC
  rpo_seconds / rto_seconds bigint nullable
  status enum PLANNED | RUNNING | VERIFYING | PASSED | FAILED | ABORTED
  reconciliation_manifest_hash bytea nullable
  evidence_manifest_id string
  safe_failure_code string nullable

deployment_releases
  id UUIDv7 PK
  release_version string unique
  source_commit string
  artifact_manifest_hash bytea
  schema_expand_version string
  contract_versions jsonb
  feature_flag_manifest_hash bytea
  assurance_manifest_id string
  status enum CANDIDATE | STAGING_VERIFIED | APPROVED |
              DEPLOYING | ACTIVE | ROLLED_BACK | FAILED
  approved_by / approved_at nullable
  deployed_at / completed_at nullable
```

Production tables contain only safe operational metadata and opaque artifact/key/evidence references. Detailed backup locations, topology, credentials, raw manifests, restore dumps, security findings, and commands remain in restricted operations/evidence systems.

Indexes:

- `backup_runs(source_component, completed_at desc)` and `(status, started_at)`.
- `backup_runs(recovery_point_at desc)` where successful/verified.
- `restore_drills(scope, completed_at desc)` and `(status, declared_at)`.
- `deployment_releases(status, deployed_at desc)`.
- Retention jobs preserve required audit/evidence references even after artifact expiry.

### Recovery and release invariants

1. At least three copies, across two storage types/locations, with one logically isolated offsite copy; every copy is encrypted and access-separated.
2. PostgreSQL uses continuous WAL/PITR plus daily, weekly, and monthly retained copies according to approved schedule.
3. S3 originals have versioning, encryption, lifecycle, no public access, and a backup isolated from the live bucket/account boundary as approved.
4. Backup deletion/retention credentials are separate from live application and routine deployment credentials; high-impact deletion requires MFA/approval/audit.
5. Backup encryption keys and recovery procedure are protected separately and tested. A ciphertext without recoverable authorized keys is not a valid backup.
6. A backup is `VERIFIED` only after manifest/checksum/inventory validation; system readiness claims require an actual restore/reconciliation drill.
7. Restore targets are isolated, private, access-controlled, and cannot send SMS/FCM/email/provider calls or connect to production clients.
8. PITR recovery point is selected before incident-corrupting transactions where possible and measured against the last valid committed source event.
9. Qdrant can be restored from snapshot or rebuilt from PostgreSQL metadata plus S3 originals; loss never implies medical-record loss.
10. Redis/cache/analytics loss triggers reinitialization/replay/rebuild and never overwrites PostgreSQL/S3 truth.
11. Deployment uses the exact signed artifact already tested; build once, promote by digest.
12. Database changes follow expand -> deploy compatible code -> bounded backfill -> switch -> later contract. Software rollback never requires destructive data rollback.
13. Production feature flags are server-owned/audited/fail-closed. Every §171 future feature is explicitly disabled.
14. Release cannot become `ACTIVE` until automated checks and required human approvals refer to the same artifact/config/schema/flag manifests.

## Detailed backup and recovery flows

### 1. PostgreSQL continuous backup

1. Managed service/tool takes a transactionally consistent base backup without unbounded application pause.
2. WAL is continuously archived to encrypted isolated storage; monitors track archive age/gaps and last recoverable point.
3. Backup manifest records cluster/system identity, PostgreSQL/tool version, start/stop LSN/time, file/checksum inventory, encryption/key reference, retention, and artifact hashes.
4. Automated verification checks completeness, checksum, decrypt authorization, WAL continuity, expiration, and storage/account isolation.
5. Safe result metadata is recorded in `backup_runs`; detailed manifest stays restricted.
6. A failed/gapped archive alerts immediately and cannot be represented as healthy/verified.

### 2. S3 original-file backup

1. Live buckets enforce block-public-access, encryption, versioning, access logging, and lifecycle.
2. Approved replication/copy writes versions and delete-marker history to an isolated destination with separate credentials/retention/immutability controls.
3. Inventory/checksum jobs compare database object metadata/hash against source and backup manifests without logging object names containing sensitive data.
4. Missing/mismatched objects are quarantined for investigation and alert; they are not silently replaced.
5. Restore tests retrieve a selected synthetic sample and a full bounded dataset into an isolated bucket, verify hash/metadata/access policy, and deny anonymous access.

### 3. Core PITR disaster drill

1. Incident controller declares drill, target recovery point, scope, time source, owners, communication, and abort criteria.
2. Automation provisions an isolated network, database, object bucket/access, Redis, and exact compatible application images/config with outbound notifications/providers disabled.
3. Restore base backup, replay WAL to target, verify database starts, migrations/schema versions match, and application uses least-privilege restore credentials.
4. Restore/attach required S3 originals and validate object metadata/hash references.
5. Bring up core services, run `/live` then `/ready`, authentication canaries, and read-only smoke tests.
6. Run business-data reconciliation: patients/national-ID uniqueness, encounter/access state, appointment exclusivity/status, prescription version/amendment chain, lab/file references, invoice/payment/refund chain, stock ledger/batch/balance, audit hash chain, outbox/idempotency, notification/job states.
7. Exercise selected safe mutations with synthetic accounts and verify outbox/realtime/queue replay exactly once in effect.
8. Measure actual RPO from requested/latest valid source commit to recovered point and RTO from declaration/restore start according to the approved SLO definition.
9. Store signed evidence, destroy/trash isolated restored sensitive artifacts per policy, and close findings only after retest.

### 4. PostgreSQL failover and split-brain prevention

1. Detect primary unavailability through quorum/managed control plane, not a single app timeout.
2. Fence old primary/write path before promoting standby; one writable primary invariant is mandatory.
3. Update service discovery/pools, verify replication/recovery state, then admit bounded write traffic.
4. Ambiguous in-flight writes resolve through idempotency, audit, and business-data reconciliation; clients never blind-retry non-idempotent operations.
5. Rebuild standby/redundancy and investigate data gap. A failback is a separately rehearsed change, not an automatic oscillation.

### 5. Qdrant loss or corruption

1. Mark AI retrieval degraded and stop affected AI runs; core remains ready and functional.
2. Preserve evidence/snapshot metadata and decide snapshot restore versus full rebuild.
3. Snapshot path validates collection/schema/vector/payload/config versions and tenant indexes before traffic.
4. Rebuild path reads active knowledge documents/versions and clinical-document metadata from PostgreSQL, originals/extracted artifacts from S3, then re-parses/chunks/embeds/upserts through idempotent ingestion.
5. Verify vector counts, tenant indexes/filters, active-version behavior, retrieval evaluation, and zero cross-scope leakage before enabling AI cohorts.
6. AI RTO is recorded separately and does not alter core RPO/RTO/availability.

### 6. Redis/queue/realtime/analytics loss

1. Stop retry storms and protect PostgreSQL with load shedding/pool limits.
2. Recreate empty cache/realtime state; clients reconnect with jitter and fetch authoritative current state.
3. Restore queue service and replay PostgreSQL outbox by stable event ID. Consumers deduplicate; reconcile critical notification/integration/file work.
4. Rebuild analytics from approved events/source projections and compare totals.
5. Verify no medical/financial truth was expected only in Redis and no duplicate side effect occurred.

### 7. Local-draft recovery boundary

- Doctor encrypted drafts may help a single doctor resume transient work after server recovery but are not accepted as disaster truth automatically. The user reviews/syncs through normal authorization, record version, idempotency, and conflict UI.
- Pharmacy offline cache cannot recreate sales or stock; no unacknowledged offline sale exists in V1.
- Patient/client caches refresh from server and never overwrite recovered state.

## Detailed production release flow

### 1. Freeze and approve the candidate

1. Pin source commit, signed image/app artifacts, SBOM/provenance, OpenAPI/events/tools, migrations, infrastructure/config, feature flags, model/prompt/rule/KB versions, and evidence manifest.
2. Verify Phase 22 security/privacy/clinical/pharmacy evidence, non-blocking compliance assumptions, and Phase 21 SLO/load/recovery-under-stress evidence refer to that candidate.
3. Confirm support/on-call, incident/status communication, vendor contacts, dashboards/alerts/runbooks, backup health, recent passing restore drill, rollback/forward-fix path, and change window.
4. Produce explicit go/no-go with accountable approvers; missing evidence is no-go.

### 2. Pre-deployment checks

1. Verify production target identity/environment, network/private endpoints, DNS/LB certificates, KMS/secrets, database/Redis/S3/Qdrant/provider separation, quotas, time sync, storage/capacity, and monitoring export.
2. Verify no staging/test secrets/data/routes/providers/debug config and all future flags off.
3. Create/verify fresh recovery point and migration lock/query/space estimates.
4. Run backward compatibility against currently deployed clients/services and enforce minimum supported versions only through an approved deprecation plan.

### 3. Expand migration and application rollout

1. Run backward-compatible expand migrations using the least-privilege migration identity, statement/lock timeout, progress telemetry, and abort criteria.
2. Deploy immutable Laravel/worker/Reverb/FastAPI/admin artifacts to canary/small cohort; keep new risky features disabled.
3. Wait for `/live`/`/ready`, migration compatibility, telemetry, security canaries, queue/outbox, connection pools, and smoke tests.
4. Roll through nodes with graceful HTTP/WebSocket/job drain and client reconnect/state resync.
5. Run production-safe synthetic smoke flows for authentication, doctor search/availability, booking, queue, authorized clinical read, prescription read, medicine search, POS synthetic/test facility where explicitly safe, AI degraded/optional checks, and admin safe health.
6. Expand traffic/cohorts while monitoring p95/p99/errors/error-budget/backlog/DB/Redis/Reverb/Qdrant/AI/S3/provider and security signals.

### 4. Feature activation

1. Core V1 capabilities activate in dependency order after their smoke/reconciliation evidence.
2. AI features remain separate cohort flags and activate only after provider/Qdrant/evaluation/privacy/clinical gates.
3. A flag change is authenticated, authorized, audited, two-person reviewed for high-risk capability, and monitored.
4. If a feature harms SLO, safety, security, cost, or correctness, disable its flag without disabling unrelated core paths.

### 5. Rollback and forward recovery

- Application/config regression with compatible schema: stop expansion, disable feature/canary, drain, redeploy prior signed image/config, smoke/reconcile.
- Expand migration problem before use: stop and follow its tested safe rollback where no new dependency exists.
- Data already written in new shape: prefer compatible forward fix/dual-read/backfill; never restore whole database and lose valid writes merely to undo code.
- Security/secret incident: contain feature/network, revoke/rotate credentials/artifacts, preserve evidence, deploy corrected candidate, and execute notification decisions through the documented incident/privacy process. Optional legal advice may inform the decision without blocking technical containment or recovery.
- Ambiguous clinical/financial writes: resolve by idempotency, audit, and business-data reconciliation before retrying.

### 6. Handover and stabilization

1. Maintain elevated observation for the approved period with explicit owners/shift handover.
2. Compare real safe aggregate traffic/latency/error/capacity/cost to benchmark assumptions and set scale triggers.
3. Review failed jobs, outbox age, unresolved appointments, access/audit anomalies, stock/financial reconciliation, file scans, backup/WAL/object replication, and AI evaluation/drift.
4. Record release outcome, incidents, deviations, follow-ups, and final evidence; do not silently accept a missed gate after launch.

## Internal contracts, events, jobs, and admin/client work

### No public recovery API

Backup, restore, failover, secret rotation, deployment, migration, and feature emergency controls are not public/admin-dashboard APIs. They run through authenticated, MFA/approval-protected operations tooling with least privilege, target confirmation, immutable audit, and kill/abort controls.

Admin `/api/v1/admin/system-health` may expose only reviewed safe fields such as component state, last successful backup time, last approved restore-drill status/time if authorized, and freshness. It never exposes backup locations, LSNs, keys, topology, commands, errors with secrets, or a restore trigger.

### Events

- `backup.run_succeeded.v1`, `backup.run_failed.v1`, `restore.drill_completed.v1`, `deployment.release_activated.v1`, and `deployment.release_failed.v1` contain safe IDs/type/status/time/RPO/RTO/error class/evidence reference only.
- Events do not contain storage paths, credentials, manifest contents, medical data, WAL/object names, provider responses, or security findings.
- Consumers update safe health/release metadata idempotently and cannot start a restore/deployment from an event alone.

### Jobs/workflows

- Base backup/WAL continuity verification, object inventory/copy verification, Qdrant snapshot, restore drill orchestration, reconciliation, retention expiry, release smoke, and evidence finalization.
- Backup scheduling may be managed externally; Laravel records safe result metadata through authenticated internal integration rather than holding infrastructure administrator credentials.
- Workflows are durable state machines with explicit approval, target, deadline, cancellation, retry eligibility, checkpoint, cleanup, and terminal evidence. Destructive cleanup never uses broad/unresolved paths or shared app credentials.

### Client release work

- Patient mobile artifacts are signed, store-ready, privacy metadata/permissions reviewed, version compatibility tested, and use production endpoints/certificates only.
- Doctor/pharmacy Electron artifacts use the approved Forge Webpack/TypeScript pipeline, target-specific makers, native-module rebuild/auto-unpack, production fuses, ASAR integrity, SBOM/provenance checks, and signing/notarization required by the approved OS/architecture matrix. Built-in Electron updating is used only on supported platforms; Linux uses the approved signed distribution/package-manager channel. Renderer code cannot select an update URL or trigger installation.
- Update orchestration is main-owned and verifies authenticated metadata/artifacts, version/channel policy, rollback compatibility, and a safe point: pending encrypted drafts/outbox operations are durably checkpointed or the update is deferred. Database migration, wrong-key, corrupt-store, rollback, and recovery never create a blank replacement or lose acknowledged work.
- React admin assets use immutable hashes/CSP, safe cache invalidation, and server/API compatibility.
- All clients show maintenance/degraded/offline/pending/update-required states honestly; AI-specific outage never masquerades as core outage.
- Release notes and support scripts contain no sensitive architecture, credentials, patient examples, or unsafe workaround.

## Security, privacy, and operational controls

- Separate production, backup, restore, CI/CD, migration, read-only verification, and break-glass identities; least privilege, MFA, short-lived credentials, approval, audit, rotation, and periodic access review.
- Encrypt backups/evidence in transit and at rest. KMS/key backups, separation, rotation, revocation, recovery authorization, and loss scenarios are documented/tested.
- Isolated/offsite backup account/location has denial controls against compromised live/deployment credentials; deletion/retention changes alert.
- Restored environments deny public/client traffic, outbound SMS/FCM/email/LLM/integration calls, and use private DNS/network plus access expiry/cleanup.
- Break-glass access is time-bound, reason/ticket/approval-linked, monitored, and reviewed afterward; it does not grant routine admin clinical browsing.
- Deployment verifies artifact signature/provenance/SBOM, exact digest, vulnerability policy, secret/config source, and environment identity.
- Production logs/traces/backups/release evidence preserve existing redaction and retention. Restore does not reactivate expired sessions/tokens/URLs without policy reconciliation.
- Incident communication and data-subject notification decisions are made by accountable privacy, operations, clinical, and pharmacy owners using documented configurable policy; engineering runbooks supply facts and evidence, not legal conclusions. Optional legal advice is advisory and non-blocking.

## Test and drill plan

### Unit tests

- Backup/restore/deployment state transitions, retention selection, RPO/RTO calculation, freshness, key-reference validation, feature-manifest exclusions, and release gate aggregation.
- Recovery reconciliation rules for appointments, grants, prescriptions, lab/files, ledger/balances, invoices/refunds, audit chain, outbox/idempotency, and AI indexes.
- Retry/cancellation/cleanup classifiers distinguish safe idempotent verification from destructive/non-repeatable operations.
- Safe event/admin projection redaction and no-secret/path/topology exposure.

### Integration tests

- Create/verify/restore PostgreSQL base+WAL to chosen point with exact tool/version and encrypted isolated storage.
- S3 version/delete-marker/replica/checksum/access-policy restore; anonymous and live-app delete access denied.
- Qdrant snapshot restore and full re-ingestion rebuild with collection/payload tenant index/evaluation verification.
- Redis flush/loss plus outbox/queue replay, Reverb reconnect, analytics rebuild, and no duplicate critical effect.
- Secret/KMS recovery, rotation, revoked credential, signed artifact, migration-role, and deployment identity tests.
- Forge-packaged Electron integration verifies main/preload/renderer/optional-utility startup, native SQLCipher ABI/linkage, OS keystore/`safeStorage`, production fuses, ASAR integrity, signature/notarization, updater feed validation, pending-draft deferral, and crash-safe database migration on each supported OS/architecture.

### Contract tests

- Managed/self-hosted backup providers return the same recovery-point/integrity/encryption/retention/restore/error contract.
- Backup/restore/deployment safe event schemas and admin health projection compatibility.
- Old/new application/API/event/client versions operate during rolling expand migration.
- Old/new Electron OpenAPI, preload/IPC, realtime event, encrypted-store, and update-metadata contracts remain backward compatible across the supported client window; renderer-supplied update URLs/channels and privileged scope are rejected.
- Release evidence schema rejects mismatched artifact/config/schema/flag/assurance manifests and missing approvals.

### End-to-end release tests

- Fresh isolated environment deploys from IaC/config/secret references, migrates, seeds synthetic controls, and passes patient Flutter, doctor Electron, pharmacy Electron, browser admin, core, and AI smoke paths.
- Rolling/canary deployment under representative traffic preserves sessions, private realtime resync, idempotent mutations, jobs/outbox, and local draft compatibility.
- Disable AI/Qdrant and all three AI client entries degrade while manual/core workflows pass.
- Roll back compatible application version and separately rehearse forward fix after data-shape adoption.
- WebdriverIO `@wdio/electron-service` exercises each packaged Electron candidate by default; Playwright Electron is permitted only after the approved experimental-launcher compatibility spike. Installed-package tests cover first launch, update-required, defer/resume update, offline/reconnect, logout/revocation, printer/scanner/file boundaries, and preserved encrypted drafts.

### System disaster-recovery tests and drills

- Full core PITR plus S3 recovery, business-data reconciliation, safe synthetic mutations, RPO/RTO measurement, cleanup, and evidence.
- Primary failover/fencing/ambiguous-write reconciliation and rebuilt redundancy.
- Qdrant total loss followed by snapshot and from-source rebuild paths.
- Redis cache/queue/realtime loss, worker death, outbox replay, reconnect storm, and analytics rebuild.
- Backup credential compromise simulation: revoke/rotate, verify live app unaffected, isolated copies protected, and monitoring/incident flow works.
- Restore-tool/version upgrade rehearsal before the old version becomes unavailable.
- Windows/macOS/Linux targets in the approved matrix install, verify publisher/notarization, update through their supported signed channel, reject tampered/downgrade/wrong-channel artifacts, recover from interrupted update, preserve or explicitly roll forward the encrypted store, and uninstall according to retention policy without orphaning readable PHI.

### Security, privacy, load, and operational acceptance tests

- Phase 22 assurance reruns production-config canaries against the exact candidate without extracting production data.
- Restored environment has correct isolation/redaction/retention and cannot notify or expose real subjects.
- Post-restore/load smoke meets critical p95/error behavior; 500 RPS/connection recovery evidence remains valid for the deployed topology or is rerun after material change.
- On-call game day tests detection, declaration, ownership, communications, restore/failover, feature kill, evidence, and handover.

## Observability and runbooks

### Backup/recovery metrics

- Last successful/verified recovery point, WAL archive age/gap, backup duration/bytes/error, object replication/inventory lag/mismatch, Qdrant snapshot age, restore-drill age/result/RPO/RTO, key/certificate age, isolated-copy access/change, and retention/deletion anomaly.
- Alerts fire before the RPO window is missed, on any archive gap/verification failure/public-access drift/key issue, on overdue restore drill, and on reconciliation failure.

### Release metrics

- Deployment state/duration, ready/unready nodes, migration lock/duration/error, canary p95/p99/error/error-budget, DB/Redis/Reverb/queue/outbox/Qdrant/AI saturation, client version, security denials, failed jobs, reconciliation, and feature-flag changes.
- Every alert/runbook names owner, authority, preconditions, exact safe checks, rollback/forward path, evidence location, and escalation. Commands use explicit validated targets and least privilege; secrets are never embedded.

Required runbooks include PostgreSQL PITR, primary failover/failback, S3 object/version recovery, Qdrant snapshot/rebuild, Redis/outbox recovery, queue poison/replay, migration failure, application rollback/forward fix, credential/provider compromise, AI kill switch, malicious file incident, privacy/access incident, and full-region/service outage escalation as applicable.

## Migration and production rollout plan

1. Deploy `backup_runs`, `restore_drills`, and `deployment_releases` as backward-compatible metadata tables with restricted policies.
2. Configure backup/object/WAL/Qdrant jobs and safe result integration; verify in non-production before any production credential exists.
3. Complete at least one full production-like core restore and each component rebuild/failure drill using the candidate toolchain.
4. Freeze/sign candidate and evidence; perform go/no-go.
5. Run production preflight and fresh recovery-point verification.
6. Expand migration, canary, smoke/reconcile, rolling deployment, then staged feature activation.
7. Observe stabilization and scale triggers; rollback/forward-recover on gate breach.
8. Complete handover and schedule recurring backup verification, restore drills, access reviews, dependency/provider/model evaluation, and release rehearsals.

## Production definition-of-done gate

The release is accepted only when all of the following have evidence against the same candidate/configuration:

- Functional requirements and all unit/integration/contract/E2E/system suites pass.
- Critical authorization and medical-record access/grant/revocation tests pass.
- Prescription exposure/amendment/audit/notification/concurrency tests pass.
- Pharmacy ledger/FEFO/expiry/receive/POS/cancel/return/refund/sync reconciliation passes.
- PostgreSQL/S3 restore drill meets measured core RPO ≤5 minutes and RTO ≤60 minutes, or an explicitly approved revised business target is documented before release.
- 3-2-1, encryption/key recovery, continuous WAL, daily/weekly/monthly retention, isolated copy, integrity checks, and scheduled recurring restore drills operate.
- Qdrant loss/rebuild and Redis loss/replay do not break or lose core truth; AI failure does not make core unavailable.
- Phase 21 p95/load/WebSocket/AI-concurrency/stress/recovery targets pass on the final or demonstrably equivalent topology.
- No critical security finding and no Phase 22 release-blocking high/privacy/security/clinical/pharmacy condition remains; missing legal review is never such a condition.
- AI safety/evaluation/tenant/tool/clinical review passes for every enabled AI cohort; AI remains optional/read-recommend with deterministic patient/tool controls.
- Monitoring, alerts, on-call, incident/rollback/failover/rebuild runbooks, audit, secret rotation, and backup/restore ownership are operational.
- Staging is production-like and contains no raw production medical data; production artifacts are signed/locked/SBOM-verified and secrets come only from the approved manager.
- All future feature flags are off: `online_payments`, `emergency_chat`, `drug_alternatives`, `branch_transfers`, `patient_adherence`, `medical_imaging_ai`, `supplier_api_integration`, and `multi_country`; reservation and complex admin roles are absent.
- Product, engineering, QA, operations, security/privacy, clinical, and pharmacy owners issue the final evidence-based go/no-go and accept only their scoped decisions. Legal sign-off is not required.

## Acceptance and exit gate

- A full isolated core restore has reconciled authoritative PostgreSQL/S3 state and met the measured RPO/RTO target; evidence includes exact point, tool/artifact/config versions, timings, hashes, test results, and cleanup.
- PostgreSQL failover/fencing, S3 object recovery, Qdrant snapshot/from-source rebuild, Redis/outbox replay, analytics rebuild, and signed application rollback/forward-fix drills pass without data corruption or duplicate critical effects.
- Backup copies satisfy 3-2-1, encryption, key recoverability, access/deletion separation, continuous/daily/weekly/monthly policy, monitoring, and recurring restore schedule.
- The exact signed candidate deploys through expand/canary/rolling activation, passes safe production smoke/reconciliation/SLO/security checks, and can be disabled/rolled back or forward-recovered using rehearsed runbooks.
- 99.9% core availability/error-budget ownership, on-call, incident communication, dashboards/alerts, scale triggers, vendor contacts, and handover are active.
- Every production definition-of-done item above is signed and linked to reproducible evidence; missing, stale, mismatched, or verbal evidence is a no-go.
- No public recovery/control endpoint, unsupported compliance claim, real-data test leak, AI-to-core dependency, or V1-excluded feature is introduced.
