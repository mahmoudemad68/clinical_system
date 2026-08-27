# Clinic Platform Implementation Phases

## Purpose

This directory converts the 4,742-line product and architecture source in [`plan.md`](../../plan.md) into an implementation-ready delivery program. The source document is authoritative for product scope. The user-approved desktop-stack decision in [ADR 0010](../adr/0010-electron-react-typescript-desktop-clients.md) supersedes only its Flutter Desktop implementation choice. These phase files add execution detail: conventional Laravel module ownership, service boundaries, data and control flows, package choices, failure behavior, security controls, test layers, observability, migrations, and acceptance gates.

[`PLAN_COVERAGE.md`](PLAN_COVERAGE.md) maps all 176 numbered source sections to a primary implementation phase and records the source line ranges used during this review.

The target system has four clients and two server-side subsystems:

1. Patient mobile application: Flutter for Android and iOS.
2. Doctor desktop application: Electron with React and TypeScript.
3. Pharmacy desktop application: Electron with React and TypeScript.
4. Admin dashboard: React and TypeScript.
5. Core platform: Laravel modular monolith managed with `nwidart/laravel-modules`, backed by PostgreSQL/PostGIS, Redis, Reverb, and private S3-compatible storage.
6. AI subsystem: isolated Python/FastAPI service backed by Qdrant and one or more LLM/embedding providers.

## Laravel Core implementation convention

All numbered phases use a conventional Laravel structure. Install the Laravel-13-compatible package with `composer require nwidart/laravel-modules`; modules live at top-level `Modules/<Name>/` and own their controllers, Form Requests, API Resources, models, services, policies, jobs, events/listeners, service providers, routes, migrations, resources, tests, and optional `app/Enums` backed enums.

Controllers stay thin and call descriptive module services. Services own business workflows and database transactions. Eloquent is used directly inside the owning module. Do not implement the phase plans as `Domain/Application/Infrastructure` layers, command/query handler trees, aggregates, generic repository wrappers, DDD value-object folders, or `*Port` classes. A named “port” in an older phase paragraph means a small module service or, only for a genuinely replaceable external provider, an interface under `app/Contracts`; it does not authorize recreating the removed DDD architecture.

Legal or regulatory approval is not required to start, implement, verify, accept, or complete a phase. When policy is unresolved, use the safest reversible and configurable project default, document the assumption/risk, test it, and continue. Optional professional advice may refine production configuration later, but its absence is never a work blocker and engineering evidence must not be described as proof of statutory compliance.

## How to use these files

- Execute phases in numeric order unless an explicit dependency section allows parallel work.
- A phase starts only when its entry criteria are met and ends only when every mandatory acceptance gate is evidenced.
- Each phase must produce deployable, disabled-by-default increments behind authorization and, where noted, feature flags.
- Security, privacy, auditability, accessibility, localization, observability, and testing are work inside every phase. Phase 22 validates the assembled system; it is not the first time security work happens.
- Tests must prove both allowed and denied behavior. A happy-path demonstration is never sufficient for clinical, identity, inventory, financial, or AI controls.
- Exact dependency versions are selected and locked during Phase 00. Package names in later phases express the intended capability; they do not authorize floating versions.
- Any deviation from `plan.md` or a phase invariant requires a short Architecture Decision Record (ADR), risk assessment, and migration impact. Legal approval is never required for the ADR or phase work.

## Delivery sequence

| Phase | File | Primary outcome | Depends on |
| ---: | --- | --- | --- |
| 00 | [Cross-cutting architecture and delivery contract](00_cross_cutting_architecture_and_delivery_contract.md) | Repository, boundaries, contracts, CI, environments, threat model, and shared test harness | None |
| 01 | [Authentication, identity, and access](01_auth_identity_and_access.md) | Accounts, devices, OTP, MFA, patient matching, and deny-by-default authorization | 00 |
| 02 | [Onboarding, verification, profiles, and locations](02_onboarding_verification_profiles_and_locations.md) | Patient/doctor/pharmacy onboarding and admin verification without clinical-data exposure | 01 |
| 03 | [Scheduling, availability, and booking](03_scheduling_availability_and_booking.md) | Location-specific schedules, atomic booking, walk-ins, and reviews eligibility | 01-02 |
| 04 | [Realtime queue and consultation control](04_realtime_queue_and_consultation_control.md) | Check-in, queue ordering, delay projection, and server-authoritative consultation transitions | 03 |
| 05 | [Clinical records, encounters, and local resilience](05_clinical_records_encounters_and_local_resilience.md) | Contextual medical-record access, encounters, secure drafts, and conflict-safe sync | 04 |
| 06 | [Prescriptions, reminders, and printing](06_prescriptions_reminders_and_printing.md) | Structured prescriptions, immutable finalized versions, exposure/amendment audit, reminders, and print/export | 05; consumes a `MedicationReferenceService` whose production catalog implementation arrives in 10 |
| 07 | [Labs, medical files, reports, and referrals](07_labs_files_reports_and_referrals.md) | Lab lifecycle, quarantined uploads, private delivery, reports, sick leave, and referrals | 05 |
| 08 | [Patient experience, discovery, reviews, and localization](08_patient_experience_discovery_reviews_and_localization.md) | Patient home, doctor discovery, maps, reviews, Arabic/English, and Egypt configuration | 02-07 |
| 09 | [Notifications and post-visit chat](09_notifications_and_post_visit_chat.md) | Durable push/SMS delivery and 48-hour encounter-scoped text chat | 01, 03-07 |
| 10 | [Medication catalog and pharmacy tenancy](10_medication_catalog_and_pharmacy_tenancy.md) | Admin-owned Egyptian medication master and organization/branch boundaries | 02 |
| 11 | [Inventory, batches, FEFO, and alerts](11_inventory_batches_fefo_and_alerts.md) | Immutable stock ledger, batch balances, FEFO, low-stock and expiry alerts | 10 |
| 12 | [Purchasing and goods receipt](12_purchasing_and_goods_receipt.md) | Suppliers, purchase orders, partial receipts, batches, and idempotent ledger posting | 11 |
| 13 | [POS, invoices, returns, and refunds](13_pos_invoices_returns_and_refunds.md) | Atomic sale, payment recording, cancellation reversal, return/refund audit | 11-12 |
| 14 | [Medicine search and prescription fulfillment discovery](14_medicine_search_and_prescription_fulfillment.md) | Text/geo medicine discovery and full/partial prescription coverage ranking | 06, 10-13 |
| 15 | [External pharmacy integrations](15_external_pharmacy_integrations.md) | Adapter-based mirrors, product mapping, stale-data handling, and replay-safe sync | 10-14 |
| 16 | [AI platform, knowledge ingestion, and retrieval](16_ai_platform_knowledge_ingestion_and_retrieval.md) | Isolated AI service, versioned ingestion, hybrid retrieval, provider interfaces, and evaluation harness | 00, 07, 10 |
| 17 | [Doctor AI](17_doctor_ai.md) | Specialty-scoped, visit-scoped, read/recommend clinical assistance | 05-07, 16 |
| 18 | [Pharmacy AI](18_pharmacy_ai.md) | Knowledge answers plus least-privilege inventory tools | 10-16 |
| 19 | [Patient AI triage and booking tools](19_patient_ai_triage_and_booking_tools.md) | Clinically reviewed intake, deterministic red flags, guarded output, and confirmed booking | 03, 08, 16 |
| 20 | [Admin analytics and system health](20_admin_analytics_and_system_health.md) | De-identified operational analytics and safe health visibility | 02-19 as data sources mature |
| 21 | [Performance, scaling, observability, and resilience](21_performance_scaling_observability_and_resilience.md) | Measured SLOs, load/stress evidence, degradation behavior, and capacity model | 00-20 |
| 22 | [Security, privacy, and compliance validation](22_security_privacy_and_compliance_validation.md) | Cross-system threat closure, privacy controls, penetration tests, and release security evidence | 00-21 |
| 23 | [Disaster recovery, release, and production](23_disaster_recovery_release_and_production.md) | Restore/failover proof, migration/rollback rehearsal, production deployment, and handover | 00-22 |

## Critical system invariants

These constraints apply to every phase and cannot be weakened by client behavior, caches, AI output, or administrative privilege:

1. PostgreSQL is the operational and medical source of truth. S3 is the original-file source of truth. Qdrant is a rebuildable retrieval index. Redis is temporary infrastructure. Analytics is derived data.
2. The AI subsystem is never required for account, appointment, queue, clinical, prescription, lab, pharmacy, inventory, POS, search, chat, or notification core behavior.
3. A user account is not a patient, doctor, staff member, or pharmacy organization. Profiles and organization memberships model those identities explicitly.
4. Patient profiles may exist without accounts. Registration may attach a verified account to an existing profile but must never create a second medical record for the same normalized national ID.
5. National IDs are encrypted for recovery and stored as keyed HMACs for exact matching. Raw values never appear in logs, analytics, caches, URLs, events, test fixtures, or AI prompts.
6. Authentication proves identity; authorization evaluates actor, role/membership, resource, action, encounter/appointment context, location, state, and time. Every server operation is deny-by-default.
7. Admin and secretary roles do not receive clinical-record access. Check-in establishes queue eligibility only; a doctor receives cross-doctor history only when `Start Consultation` atomically creates the active encounter/access grant, and keeps access only to the doctor's own historical contributions after completion or abort.
8. Clinical and financial state machines reject skipped, backward, stale, duplicate, and unauthorized transitions.
9. Every finalized prescription version, invoice, stock movement, audit event, and historical version is never deleted or overwritten. Exposure is only an additional release/audit milestone; corrections use linked compensating/amendment records.
10. Booking, consultation completion, prescription finalization, purchase receipt, POS sale, cancellation, return/refund, and integration sync are transactionally safe and idempotent.
11. Queue state, stock, payments, access grants, and final clinical state are server-authoritative. Local state is a transparent draft/cache with explicit synchronization status.
12. Every side effect that must survive a committed transaction is emitted through a transactional outbox and processed by idempotent consumers.
13. All realtime channels are private and authorized per subscription and per event scope. Identifiers in channel names are not authorization.
14. Uploaded files remain quarantined until type/size/signature validation, malware scanning, hashing, and metadata persistence succeed.
15. AI inputs, retrieved content, tool arguments, and model outputs are untrusted. Deterministic code owns permissions, red flags, tool allowlists, state transitions, budgets, and final writes.
16. Money uses integer minor units and a currency code. Quantities use an explicit smallest tracked unit; no floating-point stock or money arithmetic is allowed.
17. All persisted instants use UTC; business schedules retain the `Africa/Cairo` time-zone identifier and handle daylight-saving changes. Display conversion happens at the edge.
18. Logs and traces contain identifiers and safe metadata, never raw medical content, credentials, national IDs, prescription text, lab contents, or unrestricted prompts/responses.
19. No production release is accepted without authorization, reconciliation, restore, failure-isolation, security/privacy, load, observability, and clinical-AI evidence where applicable. Legal sign-off is not part of this gate.
20. An Electron renderer is always untrusted presentation code: it never receives Node/Electron primitives, device or refresh tokens, database keys, arbitrary filesystem paths, SQL, shell access, or a generic IPC/network capability. Main/preload permissions are purpose-specific, schema-validated, sender-validated, deny-by-default, and independently security-tested.

## Required evidence for every phase

Each phase must leave the following artifacts in the repository or approved evidence store:

- ADRs for decisions and deviations.
- Updated C4/container/component diagrams and module dependency map.
- Database migrations plus forward-recovery/rollback notes.
- OpenAPI changes, generated-client compatibility result, and consumer contract tests.
- Event schemas, idempotency semantics, and replay tests for new asynchronous behavior.
- Data classification and retention entries for every new field, event, cache, file, metric, trace, and AI payload.
- Threat-model delta with mitigations and abuse tests.
- Unit, integration, contract, end-to-end, system, and security test reports described in the phase.
- Accessibility and Arabic/English checks for user-visible work.
- Metrics, dashboards, alerts, and runbook changes.
- Dependency/SBOM, secret scan, SAST, and container scan results for changed components.
- Automated acceptance evidence plus engineering, QA, product, security/privacy, and clinical review notes where applicable. Missing legal sign-off never blocks phase completion.

## Decisions implemented through conservative defaults

The source plan leaves several safety-significant choices open or internally ambiguous. Implement the safest reversible, configurable default shown below, record it in an ADR, and continue. These items do not create legal-approval or legal-review blockers:

| Decision | Conservative planning default | Must close by |
| --- | --- | --- |
| Full-history access at check-in versus consultation start | Check-in establishes eligibility only; `Start Consultation` atomically creates the encounter and grants full cross-doctor history. Completion/abort revokes it. | Phase 04 design gate |
| Claiming a manually created patient profile | A national-ID match plus control of an arbitrary phone is insufficient. Use non-enumerating identity proofing, mismatch review/dispute, step-up verification, linkage notification, audit, and race-safe attachment. | Phase 01 design gate |
| Minors, guardians, caregivers, deceased/incapacitated patients, and emergency break-glass | Keep proxy and break-glass access disabled until a product/clinical policy defines scope, expiry, alerts, and audit. | Record the default in Phase 01; implement only when the feature is explicitly scoped |
| Privileged capability separation | “Admin” never implies PHI access. Verification, medication-catalog approval, support, security, and operations capabilities remain separate internally even if V1 presents one admin persona. | Phase 02 design gate |
| Pharmacy employee roles | Deny all actions not explicitly assigned; define owner, pharmacist, cashier, inventory, purchasing, and connector capabilities before branch workflows ship. | Phase 10 design gate |
| Card acceptance at pharmacy POS | Integrate an approved external terminal/provider; never store/process PAN or CVV in this platform. If no approved provider is selected, V1 records only an external terminal reference/status. | Phase 13 design gate |
| Cryptographic key ownership and rotation | Use envelope encryption/KMS, per-environment keys, HMAC key versions, audited decrypt access, rotation/backfill, and tested recovery; never place keys beside ciphertext. | Phase 01 design gate and Phase 23 restore gate |
| Retention, correction, export, deletion, legal holds, and cross-border AI processing | Minimize and quarantine by default; keep retention configurable and do not send regulated data to an external model until the project privacy/security configuration explicitly enables it. | Record assumptions in Phase 16 and verify controls in Phase 22; no legal approval required |
| Clinical medication governance and patient-AI thresholds | Source/provenance, versioning, approvers, rollback, controlled-medication rules, interaction warnings, red-flag thresholds, and evaluation limits require qualified clinical owners. | Phases 10, 17, and 19 gates |
| Production topology and managed services | Treat the sizes in `plan.md` as load-test hypotheses; select topology from measured workload, RPO/RTO, residency, support, and budget. | Phase 21 capacity gate |

## Source-plan coverage policy

Every numbered section in `plan.md` is mapped in at least one phase's **Plan traceability** section. Overlap is intentional where a cross-cutting requirement is implemented in one phase and validated in another. The coverage audit for this directory must fail if any source section 1-176 is absent or if a future-only feature is accidentally promoted into V1.

## External references to revalidate at phase start

These primary references were checked on 2026-08-25. They support the architecture/tooling direction but do not replace version compatibility spikes, threat modeling, clinical review, or Egyptian legal/regulatory advice.

- [Laravel 13 Sanctum](https://laravel.com/framework/docs/13.x/sanctum), [Horizon](https://laravel.com/framework/docs/13.x/horizon), [Reverb](https://laravel.com/framework/docs/13.x/reverb), and [Octane](https://laravel.com/framework/docs/13.x/octane).
- Patient-mobile [Flutter app architecture](https://docs.flutter.dev/app-architecture/guide) and [Flutter integration testing](https://docs.flutter.dev/cookbook/testing/integration/introduction).
- Electron's [security checklist](https://www.electronjs.org/docs/latest/tutorial/security), [context isolation](https://www.electronjs.org/docs/latest/tutorial/context-isolation), [process sandboxing](https://www.electronjs.org/docs/latest/tutorial/sandbox), [IPC guidance](https://www.electronjs.org/docs/latest/tutorial/ipc), [safe storage](https://www.electronjs.org/docs/latest/api/safe-storage), [fuses](https://www.electronjs.org/docs/latest/tutorial/fuses), [automated testing](https://www.electronjs.org/docs/latest/tutorial/automated-testing), [distribution](https://www.electronjs.org/docs/latest/tutorial/distribution-overview), [code signing](https://www.electronjs.org/docs/latest/tutorial/code-signing), and [updater](https://www.electronjs.org/docs/latest/api/auto-updater/) guidance for doctor/pharmacy desktops.
- Qdrant [hybrid queries](https://qdrant.tech/documentation/search/hybrid-queries/), [multitenancy](https://qdrant.tech/documentation/tutorials/multiple-partitions/), [security](https://qdrant.tech/documentation/security/), and [snapshots](https://qdrant.tech/documentation/operations/snapshots/).
- OWASP [ASVS](https://owasp.org/www-project-application-security-verification-standard/), [API Security](https://owasp.org/www-project-api-security/), and [MASVS/MASTG](https://mas.owasp.org/MASVS/).
- NIST [AI Risk Management Framework](https://www.nist.gov/itl/ai-risk-management-framework) and the [Generative AI Profile](https://nvlpubs.nist.gov/nistpubs/ai/NIST.AI.600-1.pdf).
- Egypt's [Personal Data Protection Center](https://pdpc.gov.eg/) and the Egyptian Drug Authority's [regulatory reference](https://edaegypt.gov.eg/ar/%D8%A7%D9%84%D9%85%D8%B1%D8%AC%D8%B9-%D8%A7%D9%84%D8%AA%D9%86%D8%B8%D9%8A%D9%85%D9%8A-%D9%84%D9%87%D9%8A%D8%A6%D8%A9-%D8%A7%D9%84%D8%AF%D9%88%D8%A7%D8%A1-%D8%A7%D9%84%D9%85%D8%B5%D8%B1%D9%8A%D8%A9/%D8%A7%D9%84%D9%82%D9%88%D8%A7%D9%86%D9%8A%D9%86-%D9%88%D8%A7%D9%84%D9%84%D9%88%D8%A7%D8%A6%D8%AD-%D8%A7%D9%84%D8%AA%D9%86%D9%81%D9%8A%D8%B0%D9%8A%D8%A9/).
