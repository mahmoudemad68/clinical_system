# Clinic Roadmap Phase Routing

Read this reference whenever `clinic-phase-implementation` selects, starts, continues, or closes roadmap work. It assigns coordination ownership; the linked phase file remains authoritative for scope, invariants, dependencies, packages, workflows, tests, and exit gates.

## Routing rules

- Use exactly one lead for the active phase. The lead coordinates the phase but does not absorb companion ownership.
- Every phase requires the independent companions `clinic-test-engineering` and `clinic-security-privacy-assurance`, even when omitted from the phase-specific companion column for readability.
- Load `clinic-architecture-contracts` for any ADR, public/internal contract, module boundary, package baseline, cross-service decision, or deviation.
- Add `clinic-postgresql-consistency` for schemas, constraints, locks, isolation, indexes, RLS/tenant predicates, migrations, reconciliation, or database recovery.
- Add `clinic-realtime-jobs-delivery` for outbox/events, queues, schedules, notifications, WebSockets, retries, deduplication, or replay.
- Add a client skill only for the client it owns. Client skills never inherit backend policy ownership.
- `clinic-secure-files`, `clinic-ai-evaluation-governance`, `clinic-observability-performance`, and `clinic-production-dr-release` retain independent specialist gates when routed.
- If a phase-specific change crosses an unlisted boundary, add the relevant companion. Do not remove a listed companion merely because its first review finds no code change.

## Phase map

| Phase and authoritative source | Depends on | Lead | Phase-specific companions (test and security are also mandatory) |
| --- | --- | --- | --- |
| [00 — Cross-cutting architecture and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md) | None | `clinic-architecture-contracts` | `clinic-laravel-development`, `clinic-flutter-development`, `clinic-react-admin-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-ai-platform`, `clinic-observability-performance`, `clinic-production-dr-release` |
| [01 — Authentication, identity, and access](../../../docs/phases/01_auth_identity_and_access.md) | 00 | `clinic-laravel-development` | `clinic-architecture-contracts`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development` |
| [02 — Onboarding, verification, profiles, and locations](../../../docs/phases/02_onboarding_verification_profiles_and_locations.md) | 01 | `clinic-laravel-development` | `clinic-architecture-contracts`, `clinic-postgresql-consistency`, `clinic-secure-files`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development`, `clinic-react-admin-development` |
| [03 — Scheduling, availability, and booking](../../../docs/phases/03_scheduling_availability_and_booking.md) | 01-02 | `clinic-laravel-development` | `clinic-architecture-contracts`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development` |
| [04 — Realtime queue and consultation control](../../../docs/phases/04_realtime_queue_and_consultation_control.md) | 03 | `clinic-laravel-development` | `clinic-clinical-domain`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development`, `clinic-observability-performance` |
| [05 — Clinical records, encounters, and local resilience](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md) | 04 | `clinic-clinical-domain` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development`, `clinic-architecture-contracts` |
| [06 — Prescriptions, reminders, and printing](../../../docs/phases/06_prescriptions_reminders_and_printing.md) | 05; catalog adapter arrives in 10 | `clinic-clinical-domain` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development`, `clinic-architecture-contracts` |
| [07 — Labs, medical files, reports, and referrals](../../../docs/phases/07_labs_files_reports_and_referrals.md) | 05 | `clinic-clinical-domain` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-secure-files`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development` |
| [08 — Patient experience, discovery, reviews, and localization](../../../docs/phases/08_patient_experience_discovery_reviews_and_localization.md) | 02-07 | `clinic-flutter-development` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-clinical-domain`, `clinic-architecture-contracts` |
| [09 — Notifications and post-visit chat](../../../docs/phases/09_notifications_and_post_visit_chat.md) | 01, 03-07 | `clinic-realtime-jobs-delivery` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-clinical-domain`, `clinic-flutter-development`, `clinic-observability-performance` |
| [10 — Medication catalog and pharmacy tenancy](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md) | 02 | `clinic-pharmacy-domain` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-flutter-development`, `clinic-react-admin-development`, `clinic-clinical-domain`, `clinic-architecture-contracts` |
| [11 — Inventory, batches, FEFO, and alerts](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md) | 10 | `clinic-pharmacy-domain` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development`, `clinic-observability-performance` |
| [12 — Purchasing and goods receipt](../../../docs/phases/12_purchasing_and_goods_receipt.md) | 11 | `clinic-pharmacy-domain` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development` |
| [13 — POS, invoices, returns, and refunds](../../../docs/phases/13_pos_invoices_returns_and_refunds.md) | 11-12 | `clinic-pharmacy-domain` | `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-flutter-development`, `clinic-architecture-contracts` |
| [14 — Medicine search and prescription fulfillment](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md) | 06, 10-13 | `clinic-pharmacy-domain` | `clinic-clinical-domain`, `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-flutter-development`, `clinic-observability-performance` |
| [15 — External pharmacy integrations](../../../docs/phases/15_external_pharmacy_integrations.md) | 10-14 | `clinic-pharmacy-integrations` | `clinic-pharmacy-domain`, `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-observability-performance`, `clinic-production-dr-release` |
| [16 — AI platform, knowledge ingestion, and retrieval](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md) | 00, 07, 10 | `clinic-ai-platform` | `clinic-secure-files`, `clinic-ai-evaluation-governance`, `clinic-architecture-contracts`, `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-react-admin-development`, `clinic-observability-performance`, `clinic-production-dr-release` |
| [17 — Doctor AI](../../../docs/phases/17_doctor_ai.md) | 05-07, 16 | `clinic-ai-products` | `clinic-ai-platform`, `clinic-ai-evaluation-governance`, `clinic-clinical-domain`, `clinic-laravel-development`, `clinic-flutter-development`, `clinic-observability-performance` |
| [18 — Pharmacy AI](../../../docs/phases/18_pharmacy_ai.md) | 10-16 | `clinic-ai-products` | `clinic-ai-platform`, `clinic-ai-evaluation-governance`, `clinic-pharmacy-domain`, `clinic-laravel-development`, `clinic-flutter-development`, `clinic-observability-performance` |
| [19 — Patient AI triage and booking tools](../../../docs/phases/19_patient_ai_triage_and_booking_tools.md) | 03, 08, 16 | `clinic-ai-products` | `clinic-ai-platform`, `clinic-ai-evaluation-governance`, `clinic-clinical-domain`, `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-flutter-development`, `clinic-observability-performance` |
| [20 — Admin analytics and system health](../../../docs/phases/20_admin_analytics_and_system_health.md) | 02-19 as sources mature | `clinic-laravel-development` | `clinic-react-admin-development`, `clinic-postgresql-consistency`, `clinic-observability-performance`, `clinic-architecture-contracts` |
| [21 — Performance, scaling, observability, and resilience](../../../docs/phases/21_performance_scaling_observability_and_resilience.md) | 00-20 | `clinic-observability-performance` | `clinic-architecture-contracts`, `clinic-laravel-development`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-ai-platform`, `clinic-ai-products`, `clinic-flutter-development`, `clinic-react-admin-development`, `clinic-pharmacy-integrations`, `clinic-production-dr-release` |
| [22 — Security, privacy, and compliance validation](../../../docs/phases/22_security_privacy_and_compliance_validation.md) | 00-21 | `clinic-security-privacy-assurance` | `clinic-test-engineering`, `clinic-architecture-contracts`, `clinic-production-dr-release`, plus every domain/stack owner affected by a finding or evidence gap |
| [23 — Disaster recovery, release, and production](../../../docs/phases/23_disaster_recovery_release_and_production.md) | 00-22 | `clinic-production-dr-release` | `clinic-architecture-contracts`, `clinic-postgresql-consistency`, `clinic-realtime-jobs-delivery`, `clinic-secure-files`, `clinic-ai-platform`, `clinic-observability-performance`, `clinic-laravel-development`, `clinic-flutter-development`, `clinic-react-admin-development` |

## Cross-boundary decisions

The following decisions always retain their specialist owners:

- Consultation access semantics and clinical mutability: `clinic-clinical-domain`, with architecture, security/privacy, clinical, and product approval where the roadmap requires it.
- Medication/catalog, inventory, POS, or connector semantics: `clinic-pharmacy-domain` or `clinic-pharmacy-integrations` as appropriate.
- Database constraint versus application check: `clinic-postgresql-consistency` and the domain owner together.
- Eventual delivery, replay, or realtime freshness: `clinic-realtime-jobs-delivery`; the source-of-truth owner still defines the underlying state.
- File-byte trust and release from quarantine: `clinic-secure-files`; the clinical, verification, or AI owner defines purpose and eligibility.
- AI model/prompt/retrieval behavior: `clinic-ai-products` or `clinic-ai-platform`, with `clinic-ai-evaluation-governance` holding the promotion evidence.
- Performance thresholds and telemetry: `clinic-observability-performance`; production promotion and recovery remain `clinic-production-dr-release` decisions.
- Test mechanics and evidence: `clinic-test-engineering`; security/privacy findings and exceptions remain `clinic-security-privacy-assurance` decisions.

When two skills disagree, preserve the safer current behavior, document the disputed invariant and evidence, and route the decision to the owning ADR/reviewer. The phase orchestrator never resolves a specialist conflict by silently choosing an implementation.
