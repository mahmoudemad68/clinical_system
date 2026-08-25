---
name: clinic-production-dr-release
description: Implement and execute this clinic project's backup, restore, failover, migration, signed release, canary/rolling deployment, rollback/forward-recovery, and production handover workflows. Use for Phase 23 operations; not for ordinary feature deployment, SLO instrumentation design, or self-approval of security/clinical readiness.
---

# Clinic Production, Disaster Recovery, and Release

Promote only the exact tested candidate, prove authoritative recovery with measured evidence, and keep destructive or externally mutating operations behind explicit scope, approval, and target confirmation.

## Read the required sources

Read completely before planning or executing production/DR work:

- [Roadmap, invariants, decisions, and evidence policy](../../../docs/phases/README.md)
- [Cross-cutting environments, CI, migrations, secrets, data ownership, and release contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- [Private S3/file truth](../../../docs/phases/07_labs_files_reports_and_referrals.md)
- [Qdrant rebuild and AI isolation](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md)
- [Safe admin health and BackupStatusProjection boundary](../../../docs/phases/20_admin_analytics_and_system_health.md)
- [Measured SLO/capacity/resilience evidence](../../../docs/phases/21_performance_scaling_observability_and_resilience.md)
- [Security/privacy/clinical/pharmacy release assurance](../../../docs/phases/22_security_privacy_and_compliance_validation.md)
- [Disaster recovery, release, and production](../../../docs/phases/23_disaster_recovery_release_and_production.md)

For reconciliation, read the owning phase for every affected authoritative domain, especially appointments/encounters/prescriptions, files, stock ledger/batches, invoices/payments/returns/refunds, outbox/idempotency, and AI knowledge metadata.

Inspect current infrastructure/configuration, backup catalogs, restore/deployment workflows, migration history, signed artifact/SBOM/provenance manifests, feature flags, secrets/KMS identities, dashboards/alerts, runbooks, prior drills, approvals, provider status, and local changes. Do not infer live state from documentation.

## Ownership

Own operational recovery and promotion mechanics:

- PostgreSQL continuous WAL/PITR, scheduled backup policy, isolated encrypted copies, integrity/catalog/retention verification, and restore orchestration;
- S3 version/original-file backup, inventory/checksum, isolated copy, object recovery, and cleanup;
- Qdrant snapshot restore and from-PostgreSQL/S3 re-ingestion; Redis/cache/queue/realtime loss recovery and outbox replay;
- primary failover/fencing/failback rehearsal, ambiguity reconciliation, and rebuilt redundancy;
- expand/backfill/switch/contract migration orchestration, signed candidate freeze, preflight, canary/rolling deployment, feature activation, rollback/forward fix, stabilization, and handover;
- Electron Forge candidate packaging, native-module ABI verification, reviewed fuse/ASAR integrity checks, Windows/macOS/Linux signing/notarization handoff, trusted update publication, cohort rollout, rollback compatibility, and encrypted desktop-data migration evidence;
- release/backup/restore metadata, restricted evidence manifests, approvals, and recurring drill schedules.

This skill consumes health/SLO/security/AI/clinical evidence. It does not redefine those controls or approve its own prerequisites.

## Hard boundaries

- PostgreSQL is operational/medical/financial truth and S3 is original-file truth. Qdrant, Redis, analytics, replicas, and client caches are rebuildable/temporary and never authoritative recovery sources.
- Core target is measured RPO ≤5 minutes and RTO ≤60 minutes unless accountable business owners approve a revised target before the gate. A successful backup job is not restore evidence.
- Maintain at least 3 copies, 2 storage types/locations, and 1 logically isolated offsite copy; encryption/key recovery and deletion/access separation are mandatory.
- Never restore a database merely to roll back application code when valid newer writes would be lost. Prefer compatible image rollback or forward recovery after new data shape is in use.
- No public or admin-browser backup, restore, failover, deployment, migration, secret-rotation, or feature-emergency control API.
- Destructive cleanup, primary promotion/failover, DNS/LB cutover, production migration, secret rotation, and production feature activation require exact-target resolution and the applicable user/change approval immediately before execution.
- Restored environments are private, outbound notifications/providers disabled, access-controlled, time-bounded, and cleaned according to evidence/data policy.
- Build once and promote by signed immutable digest. The artifact/config/schema/contract/flag/model/rule/evidence manifests must refer to the same candidate.
- Electron client owners implement package/update behavior, but this skill alone controls production signing identities, notarization submission, update-feed publication, promotion cohorts, emergency withdrawal, and release approval. Never expose those credentials or controls to renderer, preload, admin web, or the application API.
- AI availability is separate. Qdrant/model/provider loss cannot make Core unavailable or extend the Core RTO silently.
- Do not activate online payment, emergency chat, alternatives/reservation, branch transfer, supplier automation, adherence, image diagnosis, multi-country, complex admin roles, or another V1 exclusion.

## Recovery invariants

- PostgreSQL backup uses continuous WAL plus approved daily, weekly, and monthly retention; monitor recoverable point and archive gaps before RPO breach.
- S3 uses private access, encryption, versioning, lifecycle, access logging, and an isolated backup/copy with checksum/inventory reconciliation.
- Backup/KMS/deletion identities are separate from app/deployment identities, least-privilege, MFA/approval protected, rotated, audited, and tested for recovery.
- PITR/failover prevents split brain by fencing the old write path before one new writable primary is admitted.
- Restore verifies schema/tool/version compatibility, hashes/manifests, object references, encryption keys, domain invariants, audit chain, outbox/idempotency, and safe synthetic reads/writes.
- Qdrant returns to service only after collections/payload indexes/active versions/tenant filters/vector counts and approved evaluation pass.
- Redis recovery rehydrates cache/realtime, replays committed outbox by event ID, resynchronizes clients, and reconciles critical effects without duplicate business state.
- Software and schema changes follow expand, compatible deploy, bounded checkpointed backfill, switch, and later contract.

## Execution workflow

### Plan and authorize

1. Classify the task as backup verification, isolated restore drill, failover rehearsal, migration, candidate release, rollback/forward recovery, or incident response.
2. Resolve exact environment/account/cluster/bucket/database/recovery point/artifact/version, intended effect, authority, maintenance/error budget, abort conditions, communication, owner, and evidence location.
3. Perform read-only preflight: health, current topology, recent verified backup/recovery point, capacity, replication/WAL/object status, schema compatibility, locks/space, secrets/certificates, flags, dashboards/alerts, provider quotas, and approval/evidence freshness.
4. Present or obtain the required approval before any destructive/high-impact/live mutation. Do not broaden approved targets or actions.

### Restore or failover

1. Provision an isolated target from reviewed infrastructure/configuration with outbound side effects disabled.
2. Restore the selected base/object artifacts and replay WAL to the declared point; verify manifests/checksums/key access before service startup.
3. Start exact compatible images, run liveness/readiness, then domain reconciliation and safe synthetic smoke mutations.
4. Measure RPO/RTO using the approved definitions and record exact tools, versions, point, timings, artifacts, hashes, results, and cleanup.
5. For failover, fence before promote, resolve ambiguous writes through idempotency/audit/reconciliation, restore redundancy, and treat failback as a separate approved change.

### Release

1. Freeze and verify commit, signed images/apps, Electron packages/fuses/native ABI/update manifest, SBOM/provenance, APIs/events/tools, migrations, infrastructure/config, flags, AI versions, and Phase 21/22 evidence.
2. Confirm on-call, incident/status communication, vendor contacts, scale triggers, backup health, recent passing restore, runbooks, rollback/forward path, and go/no-go owners.
3. Create/verify a fresh recovery point; run backward-compatible expand migrations with least-privilege identity, lock/statement limits, monitoring, and abort criteria.
4. Deploy canary by immutable digest, wait for readiness, run security/contract/queue/outbox/reconciliation and production-safe synthetic smoke checks, then roll nodes with graceful drain.
5. Activate approved capabilities in dependency/cohort order. High-risk flag changes are authenticated, audited, reviewed, and independently reversible.
6. Monitor the exact Phase 21 signals and Phase 22 canaries. On breach, stop expansion and apply the rehearsed flag disable, compatible image rollback, or forward fix; never improvise a destructive data rollback.
7. Stabilize, reconcile, record outcome/deviations/follow-ups, hand over ownership, and schedule recurring backups/restores/access reviews/evaluations.

## Verification

Do not mark complete without reproducible evidence for the applicable scope:

- unit tests for backup/release states, retention selection, RPO/RTO calculation, manifest matching, flag exclusions, retry/cancellation, and release-gate aggregation;
- provider/managed-service contract tests for recovery point, integrity, encryption, retention, restore, error, cancellation, and safe event/admin projection;
- PostgreSQL base+WAL restore to a selected point; S3 version/delete-marker/checksum/access recovery; key recovery/rotation; Qdrant snapshot and from-source rebuild; Redis/outbox/realtime/analytics rebuild;
- domain reconciliation for identity uniqueness, appointments/access/encounters, prescription versions, files/labs, ledger/batches/balances, invoices/payments/returns/refunds, audit chain, outbox/idempotency, and AI metadata;
- failover fencing, ambiguous-write resolution, rebuilt standby, and separately rehearsed failback;
- clean-environment deployment, mixed-version compatibility, migration lock/backfill behavior, canary/rolling drain, client reconnect/local-data compatibility, Electron install/upgrade/downgrade/native ABI/encrypted-database migration/update-signature behavior, safe smoke, and application rollback/forward fix;
- restored environment isolation: no public/client traffic, real notification/provider egress, broad credentials, or leaked logs/evidence;
- final/equivalent topology still satisfies Phase 21 p95/load/WebSocket/AI/stress/recovery evidence and Phase 22 has no release blocker;
- every Phase 23 production definition-of-done item is linked to the exact candidate and signed by its accountable engineering, QA, operations, security/privacy, legal, clinical, pharmacy, product owner.

The observability/performance skill designs and verifies cross-system signals/SLO evidence; this skill consumes them to act. AI evaluation governance supplies promotion evidence; this skill cannot lower thresholds. Security/privacy/clinical/legal reviewers retain independent approval authority.
