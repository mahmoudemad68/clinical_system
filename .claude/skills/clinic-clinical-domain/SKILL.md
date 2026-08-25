---
name: clinic-clinical-domain
description: Implement or review this clinic project's clinical-care domain rules for consultation access, encounters, medical records, prescriptions, labs, reports, referrals, and clinician draft synchronization. Use for Phases 04-07 or clinical constraints consumed by later AI/pharmacy work; not for generic Laravel/client tasks, pharmacy inventory/POS, file-scanner mechanics, or independent test/security assurance.
---

# Clinic Clinical Domain

Preserve clinical meaning and patient-safety invariants across framework, database, client, file, and AI implementations. Express behavior as domain state machines and narrow ports so delivery mechanisms cannot redefine it.

## Read the required sources

Read completely before changing clinical behavior:

- [Roadmap invariants and open decisions](../../../docs/phases/README.md)
- [Cross-cutting architecture and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- [Phase 04 — queue and consultation control](../../../docs/phases/04_realtime_queue_and_consultation_control.md) for check-in, start/end, or access-grant work
- [Phase 05 — records, encounters, and local resilience](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md) for records, encounters, drafts, or sync
- [Phase 06 — prescriptions, reminders, and printing](../../../docs/phases/06_prescriptions_reminders_and_printing.md) for medication orders, versions, exposure, amendment, reminders, or print/export
- [Phase 07 — labs, files, reports, and referrals](../../../docs/phases/07_labs_files_reports_and_referrals.md) for those clinical artifacts and workflows

Read only the additional consumer phase that applies: [Phase 10](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md) for the production medication-reference adapter, [Phase 14](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md) for fulfillment discovery, [Phase 17](../../../docs/phases/17_doctor_ai.md) for doctor AI, or [Phase 19](../../../docs/phases/19_patient_ai_triage_and_booking_tools.md) for patient AI. Inspect current ADRs, policy matrices, aggregate/state code, migrations, API/events, encrypted-draft design, tests, and local changes.

## Ownership

Own the clinical ubiquitous language and invariants for:

- appointment eligibility versus active consultation, encounter lifecycle, and time-bounded contextual record access;
- diagnoses, allergies, history entries, notes, observations, and finalized clinical-version provenance;
- structured prescriptions, medication-reference semantics, patient visibility, exposure audit, amendments, printing, and reminders;
- lab orders/results, medical reports, sick leave, referrals, and their permitted state transitions;
- server/local draft conflict semantics and the boundary between transient clinician work and committed medical truth;
- clinical ports, typed commands/results, authorization context requirements, and domain event meaning.

The Laravel skill owns framework wiring and HTTP/application adapters. PostgreSQL consistency owns migrations, constraints, locks, indexes, and transaction proofs. Electron owns doctor local encryption/UI/sync presentation; Flutter owns patient mobile read surfaces. Secure files owns byte quarantine and signed delivery. AI skills own recommendation/retrieval mechanics. Test and security/privacy skills independently verify the implementation. Do not absorb those responsibilities into a “clinical service” god module.

## Non-negotiable clinical invariants

1. Check-in establishes queue eligibility only. It does not grant a doctor cross-doctor medical history.
2. Only an atomic, server-authorized `Start Consultation` transition may create the active encounter/access grant and unlock cross-doctor history. Completion, abort, cancellation, expiry, or revocation ends that grant immediately.
3. After the grant ends, a doctor may access only the doctor's own historical contributions where the phase permits it. Patient access is read-only; admin, secretary, pharmacy, support, and unrelated doctors receive no clinical content.
4. Any change to this access model requires an ADR plus explicit clinical, product, security, and privacy approval before enablement. Client visibility, a guessed role, an appointment ID, or a cache is never authorization.
5. PostgreSQL is medical truth. Encrypted local data is a transient draft/outbox with explicit local, pending, acknowledged, conflicted, failed, and purged states. It never silently overwrites newer server truth.
6. Finalized clinical artifacts are append-only/versioned. Corrections create linked versions or amendments with actor, reason, timestamps, provenance, and audit; they never edit or delete history in place.
7. A prescription becomes immutable and patient-readable at `FINALIZED`. `EXPOSED` records an external release/audit milestone only; it is never the point at which mutability ends. Corrections both before and after exposure append new versions.
8. Prescription logic consumes a narrow `MedicationReferencePort`. Phase 06 must work with the approved interim adapter; it must not depend directly on the full Phase 10 catalog schema or a vendor API.
9. State transitions reject skipped, backward, stale, duplicate-with-different-payload, unauthorized, or context-expired commands. Critical commands are transactionally safe and idempotent.
10. Clinical file metadata may enter domain workflows only through a `CLEAN` file reference released by the secure-file boundary. A signed URL or object key never substitutes for file authorization.
11. AI may read the minimum authorized, visit/scope-filtered context and return recommendations. It cannot grant access, write records, prescribe, finalize, order labs, sign reports, issue referrals, or change state autonomously.
12. Proxy/guardian, emergency break-glass, image diagnosis, medication alternatives, and other V1 exclusions remain disabled until their named policy, legal, clinical, security, and test gates exist.

## SOLID model

- Keep each aggregate responsible for one consistency boundary; use application services to coordinate aggregates through ports.
- Put transition and authorization preconditions in deterministic domain/application policy objects, not controllers, widgets, jobs, prompts, or database callbacks alone.
- Depend on narrow ports such as `EncounterRepository`, `ClinicalAccessPolicy`, `MedicalRecordReader`, `ClinicalVersionWriter`, `MedicationReferencePort`, `FileReferencePolicy`, `AuditEventWriter`, `Clock`, and `Outbox`.
- Provider/framework adapters must preserve typed denial, not-found, conflict, timeout, cancellation, idempotency, and redaction behavior. A substitute that fails open violates the contract.
- Emit domain events only after the owning transaction commits through the transactional outbox. Consumers may notify or project state; they may not recreate clinical truth.

## Workflow

### 1. Specify the behavior before implementation

For every command or query, record:

```text
actor + clinical context | aggregate/current version | command/query
preconditions + allowed transition | transaction/lock/idempotency
result + emitted event | denial/failure codes | audit + data exposure
```

Build an allowed/denied matrix across patient, treating doctor, unrelated doctor, secretary, admin, pharmacy, support, and workload identities. Include booked, checked-in, waiting, active, ended, aborted, expired, revoked, draft, finalized, exposed, and amended states as applicable.

### 2. Design the consistency boundary

Choose the aggregate and database invariant together with the PostgreSQL owner. Define expected-version or lock behavior, unique/partial constraints, idempotency-key fingerprint, invariant recheck inside the transaction, audit row, and outbox event. Reads must carry actor, patient/encounter, purpose, state, and time context; an unscoped repository method is not acceptable for protected clinical data.

### 3. Define failure and concurrency behavior

Cover double start/end/finalize, simultaneous clinicians, stale local drafts, reconnect after ambiguous timeout, revoked access during a request, file scan reversal, duplicate events/jobs, and clock-boundary transitions. The client may retry the same intent with the same key; a changed payload under that key is a conflict. Never resolve clinical text conflicts by last-write-wins.

### 4. Implement through owning adapters

Keep domain code framework-independent. Route HTTP/jobs/events through Laravel application commands, persistence through PostgreSQL-owned repositories, doctor local drafts through the Electron encryption/sync boundary, patient reads through Flutter, and bytes through secure-file references. Return stable machine error codes with safe localized presentation; do not leak whether another patient's record exists.

### 5. Record clinical governance

For medication rules, red flags, controlled items, patient wording, report/referral semantics, retention, and AI clinical thresholds, record the qualified reviewer, source/provenance/version, approval status, rollback, and effective dates. Engineering may implement an approved rule but cannot infer or self-approve its clinical or legal validity.

## Verification

- **Unit:** transition tables, contextual access policy, version/amendment rules, medication-port behavior, conflict classification, time boundaries, redaction, and every forbidden transition.
- **Integration:** real PostgreSQL constraints/transactions/locks, grant create/revoke atomicity, append-only history, idempotency mismatch, outbox commit, file-reference eligibility, and encrypted local-database migration/key lifecycle where applicable.
- **Contract:** OpenAPI/events/generated clients preserve version, context, denial, idempotency, and safe error semantics; adapters cannot add raw clinical or privilege-bearing fields.
- **End to end:** check-in still denies history; start grants it; end/abort revokes it; unrelated doctor/admin/secretary/pharmacy remain denied; finalized prescription remains readable and immutable before and after exposure; amendments preserve every original.
- **System/resilience:** duplicate/reordered events, Redis/Reverb/worker loss, reconnect, offline drafts, process crash, and provider/file delays never corrupt or broaden clinical truth.
- **Security/privacy:** object/function/property authorization, enumeration, stale/replayed grants, cache/search/analytics leakage, mass assignment, signed-file replay, telemetry redaction, and minimum-purpose data flows. Security assurance owns the independent result.

Use synthetic records only. Make each high-risk test prove both allowed and denied behavior and preserve evidence against the exact artifact/version.

## Scope and authorization limits

- Do not provide medical advice or invent clinical thresholds, medication safety rules, diagnoses, red flags, or prescribing policy.
- Do not access production, real patient data, external provider accounts, or live clinical systems without explicit authorization and safeguards.
- Do not run migrations or destructive correction/backfill jobs against a live environment by inference from an implementation request.
- Do not claim clinical validation, legal sufficiency, regulatory approval, or compliance from code/tests alone.
- If an ambiguity changes who may see or mutate medical data, preserve denial/current behavior and require the named ADR and accountable reviewers.

## Completion evidence

Return the implemented invariant and state transition first. Link the owning phase/ADR, code and migration, API/event changes, allowed/denied matrix, focused and full test results, threat/privacy delta, observability/runbook changes, and any review still pending. Do not call clinical work complete while an approval or negative-path proof required by the phase is missing.
