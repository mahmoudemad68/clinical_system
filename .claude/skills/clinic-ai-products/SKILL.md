---
name: clinic-ai-products
description: Implement the clinic project's Doctor AI, Pharmacy AI, or Patient AI triage/booking product workflows and deterministic tool policies. Use for persona APIs, context minimization, conversations/runs, guarded outputs, and explicit human actions; not for Phase 16 platform mechanics or AI evaluation approval.
---

# Clinic AI Products

Implement persona-specific AI as an optional, bounded product capability. Deterministic Laravel code owns authorization, safety floors, tools, state changes, and irreversible effects; model output is untrusted advice.

## Read the required sources

Always read completely:

- [Roadmap, invariants, and open decisions](../../../docs/phases/README.md)
- [Cross-cutting architecture and delivery contract](../../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- [AI platform contract](../../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md)
- The one persona phase being implemented: [Doctor AI](../../../docs/phases/17_doctor_ai.md), [Pharmacy AI](../../../docs/phases/18_pharmacy_ai.md), or [Patient AI](../../../docs/phases/19_patient_ai_triage_and_booking_tools.md).

Read the upstream domain sources for that persona:

- Doctor: [consultation/access](../../../docs/phases/05_clinical_records_encounters_and_local_resilience.md), [prescriptions](../../../docs/phases/06_prescriptions_reminders_and_printing.md), and [labs/files](../../../docs/phases/07_labs_files_reports_and_referrals.md).
- Pharmacy: [catalog/tenancy](../../../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md), [inventory](../../../docs/phases/11_inventory_batches_fefo_and_alerts.md), [purchasing](../../../docs/phases/12_purchasing_and_goods_receipt.md), [POS/refunds](../../../docs/phases/13_pos_invoices_returns_and_refunds.md), [search](../../../docs/phases/14_medicine_search_and_prescription_fulfillment.md), and [integrations](../../../docs/phases/15_external_pharmacy_integrations.md).
- Patient: [booking](../../../docs/phases/03_scheduling_availability_and_booking.md) and [patient discovery/localization](../../../docs/phases/08_patient_experience_discovery_reviews_and_localization.md).

Also read the AI sections of [performance](../../../docs/phases/21_performance_scaling_observability_and_resilience.md), [security/privacy](../../../docs/phases/22_security_privacy_and_compliance_validation.md), and [release/recovery](../../../docs/phases/23_disaster_recovery_release_and_production.md).

Inspect current persona ports, public/internal OpenAPI schemas, domain policies/commands, conversation/run/tool tables, prompt/rule versions, client contract, tests, and local changes.

## Ownership

Own persona application behavior and its typed interfaces:

- Laravel public AI endpoints, persona authorization/context readers, conversation/run state, rate/cost/budget policy, cancellation, audit, and safe events;
- FastAPI persona workflow, structured prompt/input/output schemas, bounded retrieval/generation orchestration, and answer policy adapters;
- deterministic capability registry and typed read-tool proposals/execution handoff;
- explicit human-confirmed handoff into existing domain commands;
- persona-specific unit/integration/contract/E2E/system/security tests and evaluation fixtures supplied to the independent evaluation skill.

Client ownership follows persona: `clinic-electron-desktop-development` owns Doctor AI and Pharmacy AI desktop surfaces, while `clinic-flutter-development` owns Patient AI mobile. Coordinate contract changes; do not put client UI code in the AI service or AI policy in any client.

## Persona boundaries

### Doctor AI

- General mode uses verified specialty-shared plus the same doctor's private knowledge.
- Patient context is allowed only after `Start Consultation`: the encounter exists, appointment is `IN_CONSULTATION`, doctor/location match, and current access grant is valid. `BOOKED`, `CHECKED_IN`, and `WAITING` are insufficient.
- Revalidate access before sensitive delivery and cancel on completion/abort/revocation.
- AI reads/recommends only. It cannot diagnose autonomously, prescribe/finalize, request labs, write notes, finish encounters, or interpret radiology pixels.
- `Copy to notes` is a separate, editable, authenticated Clinical command authored by the doctor with AI provenance.

### Pharmacy AI

- Knowledge answers use only active `pharmacy_knowledge` scope.
- Catalog, quantity, batch, expiry, source, and freshness facts come from typed Laravel/PostgreSQL readers, never Qdrant or model arithmetic.
- Branch/organization scope is server-owned and re-authorized immediately before every tool call.
- V1 tools are read-only and allowlisted. AI cannot sell, adjust, receive, order, cancel, return/refund, transfer, reserve, substitute, or inspect patient data.
- Stale integrated data is explicitly uncertain; unavailable live data is not replaced with a generated quantity.

### Patient AI

- Fixed intake and clinician-versioned deterministic red-flag evaluation run before and after model interaction.
- A deterministic emergency stop is terminal for normal questioning and cannot be downgraded by the model. Approved fixed copy, not free-form model text, communicates it.
- Output states possible causes, urgency, and canonical specialty without definitive diagnosis or treatment.
- Doctor ranking is earliest availability then rating; distance is display-only.
- Booking uses the existing atomic booking command and a short-lived, actor/exact-proposal-bound human confirmation proof. FastAPI/model cannot obtain or manufacture it.
- Triage conversation is separate from the medical record and is not imported automatically.

## Shared hard boundaries

- Laravel derives actor, patient/encounter, specialty, branch, collection/scope, tool, and feature authority from server state. Client/model fields cannot widen them.
- FastAPI has no Core database credentials and no generic Core tool endpoint.
- Every tool name/version/input/output/side effect/deadline/row limit is a constant registry entry. Unknown or extra capability fields fail closed.
- Re-authorize at tool execution and sensitive delivery, not only at run creation.
- Bound turns, questions, calls, tokens, output, rows, time, concurrency, cost, retries, and semantic duplicate calls. Propagate cancellation.
- Retrieved/tool content is untrusted data. Sanitize restricted Markdown and reject unsafe URLs/HTML, fabricated sources, schema drift, and tool-like output.
- Do not silently answer without required grounding/live data or switch model/provider/policy semantics.
- Core remains fully usable when FastAPI, Qdrant, workers, or providers fail.
- This skill does not set evaluation thresholds, approve clinical content, or activate a release cohort. Hand evidence to `clinic-ai-evaluation-governance`.

## Implementation workflow

1. Select exactly one persona/use case and trace its state change/read path through the persona phase and upstream domain command/query.
2. Write allowed, denied, ambiguous, stale, concurrent, cancelled, dependency-failure, and abuse cases before changing the contract.
3. Define strict public and internal schemas, server-derived context, authorization snapshot, idempotency identity, budgets, terminal states, stable safe errors, and content retention/classification.
4. Implement deterministic policy and domain handoff first; the model may propose only within the capability/output schema.
5. Implement bounded retrieval/generation and validate output against current authorization/state before storage or delivery.
6. Persist encrypted conversation content and safe trace/source/tool metadata. Keep sensitive bodies out of events/logs/traces/metrics.
7. Add a contract-compatible surface and coordinate with the persona's Electron or Flutter owner without transferring authority to the client.
8. Run focused behavioral/security tests and the evaluation suite; submit the unchanged versioned bundle/results for independent promotion review.

## Verification

Verify at minimum:

- pure policy/state/output/budget/cancellation/idempotency tests, including model inability to lower deterministic safety or expand scope;
- real PostgreSQL/Redis/Qdrant integration for conversation/run/tool races, authorization revocation, active-version filters, stale data, and duplicate delivery;
- public OpenAPI, internal Pydantic, tool registry, domain port, event, and provider contract tests with compatible error semantics;
- E2E allowed and denied journeys for the selected persona, including explicit human action and exactly-once domain effect where applicable;
- system tests for provider/Qdrant/worker outage, saturation, network interruption, reconnect/cancel, and Core independence;
- adversarial prompt/indirect injection, cross-tenant/scope, forged tool/grant/filter, data exfiltration, unsafe markup, denial-of-wallet, and output-substitution tests;
- Phase 21 retrieval/first-token/capacity targets without Core pool starvation;
- versioned Arabic/English normal, edge, ambiguous, critical, adversarial, and failure cases handed to evaluation governance with model/prompt/retrieval/rule/tool/provider versions;
- zero unauthorized disclosure, autonomous Core write, unconfirmed booking, generated inventory truth, enabled future feature, or unsupported clinical/legal claim.

`clinic-ai-platform` owns ingestion/retrieval/provider mechanics, `clinic-ai-evaluation-governance` owns thresholds and promotion evidence, observability owns cross-system SLOs, security assurance independently validates, and production/DR owns activation and recovery.
