---
name: clinic-ai-evaluation-governance
description: Design, implement, run, and review this clinic project's versioned AI evaluation and governance gates. Use for retrieval/model/prompt/rule/tool changes, clinical-quality metrics, adversarial regression, promotion evidence, and NIST AI RMF mapping; not for implementing persona behavior or self-approving a release.
---

# Clinic AI Evaluation and Governance

Produce independent, reproducible evidence for Doctor AI, Pharmacy AI, Patient AI, and the shared retrieval platform. Separate measurement from implementation and reserve clinical/pharmacy/legal approval for qualified owners.

## Read the required sources

Read completely:

- [Roadmap, required evidence, open decisions, and external references](../../docs/phases/README.md)
- [Cross-cutting contracts and test/evidence policy](../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- [AI platform and evaluation harness](../../docs/phases/16_ai_platform_knowledge_ingestion_and_retrieval.md)
- The evaluated persona: [Doctor AI](../../docs/phases/17_doctor_ai.md), [Pharmacy AI](../../docs/phases/18_pharmacy_ai.md), or [Patient AI](../../docs/phases/19_patient_ai_triage_and_booking_tools.md)
- [AI performance/capacity targets](../../docs/phases/21_performance_scaling_observability_and_resilience.md)
- [AI assurance and NIST mapping](../../docs/phases/22_security_privacy_and_compliance_validation.md)
- [Production promotion and recovery gates](../../docs/phases/23_disaster_recovery_release_and_production.md)

Inspect active dataset manifests, critical-case policy, metric definitions, previous baselines, model/prompt/retrieval/reranker/embedding/rule/tool/provider versions, evaluation code, human-review guidance, findings, and candidate artifact hashes.

## Ownership

Own evaluation and governance artifacts:

- versioned, provenance-controlled, appropriately licensed synthetic/de-identified datasets and case taxonomy;
- deterministic assertions for schema, authorization, scope, tools, state, citations/provenance, safety floors, and prohibited effects;
- retrieval, answer, triage, tool, latency, cost, refusal, privacy, and adversarial metrics;
- reproducible runner configuration, raw restricted results, aggregate reports, regression comparison, failure analysis, and evidence hashes;
- human-review sampling/rubrics, calibration against model judges, threshold proposals, critical-case rules, and promotion recommendation;
- NIST AI RMF/Generative AI Profile Govern/Map/Measure/Manage evidence and change/incident/retirement records.

This skill may implement evaluation harnesses and fixtures. It does not implement production prompts, persona workflows, tools, red-flag rules, retrieval adapters, or release activation.

## Separation of duties and boundaries

- The same change bundle can be evaluated, but the product/platform implementer cannot silently change datasets, thresholds, exclusions, graders, or critical-case labels to obtain a pass.
- Qualified medical reviewers own clinical thresholds, critical red-flag cases, Doctor AI limits, patient urgency/routing safety, and patient-facing wording acceptance.
- Qualified pharmacy/clinical reviewers own medication/pharmacy knowledge and tool-answer acceptance.
- Privacy/legal reviewers own provider-processing, retention/residency, consent, and Egyptian-law decisions. A NIST/metric mapping is not legal or regulatory compliance.
- A model judge is a noisy measurement adapter, not ground truth. Calibrate it against blinded human judgments and retain disagreement/error analysis.
- Security assurance owns the final independent prompt-injection, tenant leakage, excessive agency, credential/data exfiltration, and penetration gate. This skill includes those cases as AI regressions but does not close security findings.
- Observability/performance owns production-like SLO/load methodology. This skill consumes signed latency/cost artifacts and runs bounded AI workloads; it does not redefine Core load acceptance.
- Production/DR owns cohort flags and promotion. This skill issues `PASS`, `FAIL`, or `CONDITIONAL` evidence/recommendation only.

## Required evaluation dimensions

### Shared platform/retrieval

- Recall@K, MRR, relevant-chunk rate, active-version correctness, provenance integrity, duplicate/poisoned-chunk behavior, multilingual retrieval, groundedness, hallucination, and latency.
- Mandatory scope isolation across doctor/private/specialty/patient/pharmacy/clinical documents and rebuild equivalence after Qdrant loss.
- Provider/model invalid output, refusal, timeout, cancellation, rate limit, saturation, and no-silent-fallback semantics.

### Doctor AI

- Specialty and private-KB scope, active-consultation context minimization, Start/End/abort access behavior, clinical relevance, unsupported-specialty refusal, no autonomous prescription/write, and explicit copy provenance.

### Pharmacy AI

- Knowledge grounding, canonical medication resolution, live-tool selection/arguments, exact structured quantity preservation, source/freshness wording, branch isolation, ambiguity, and denial of writable/future tools.

### Patient AI

- Deterministic red-flag sensitivity, false-emergency rate, urgency floor, specialty-routing accuracy, clarification quality, cautious language, out-of-scope refusal, manual fallback, ranking correctness, and human-confirmed booking-tool behavior.

### Cross-cutting

- Arabic/English, ambiguous/contradictory/missing facts, edge/rare/critical cases, prompt and indirect injection, unsafe markup/links, denial-of-wallet, tool/filter/grant forgery, cross-tenant leakage, privacy minimization, latency, token use, and cost.

## Dataset and metric rules

- Give every dataset, case, source/license, rubric, rule, grader, configuration, and expected outcome an immutable version/hash.
- Use synthetic or approved de-identified cases. Never copy raw production conversations, medical records, prescriptions, labs, national IDs, phones, credentials, or private documents into fixtures or reports.
- Partition development/tuning, regression, and locked holdout sets. Prevent candidate-specific tuning on the holdout.
- Represent each safety-critical case with deterministic must-pass assertions where possible. Aggregate score cannot hide a designated critical miss, unauthorized disclosure, autonomous write, red-flag downgrade, or unconfirmed booking.
- Define metric direction, unit, calculation, confidence/uncertainty, sample size, cohort/language slices, exclusions, and promotion threshold before running the candidate.
- Record expected refusals separately from provider errors and invalid output. Do not improve a score by treating unavailable/empty responses as safe success.
- Inspect per-case failures and slice regressions; never approve on one aggregate average.

## Workflow

1. Freeze the candidate bundle: code/image, model/provider, prompts, retrieval/chunk/embedding/reranker configuration, KB/rule/tool schemas, feature flags, dataset, graders, and environment.
2. Classify the change and select shared plus persona suites. Add cases only from an approved requirement, incident, failure analysis, or reviewer decision, not to favor the candidate.
3. Write deterministic contract/safety assertions first, then task-quality metrics and calibrated human/model grading.
4. Run low-cost focused tests, full offline/fixture suites, then approved provider/Qdrant tests with explicit time/concurrency/token/cost budgets and synthetic data.
5. Compare to the last approved baseline by overall, language, specialty, risk, ambiguity, provider, and tool slices. Inspect every critical failure and material regression.
6. Have qualified reviewers assess the required blinded sample and adjudicate model-judge disagreement.
7. Publish signed machine-readable results, safe report, limitations, failures, threshold verdict, reviewers, and exact artifact hashes. Restricted raw content stays in the approved evidence store.
8. Recommend pass/fail/conditional with explicit conditions and expiry. Hand remediation to the platform/product owner and rerun the unchanged gate after a new versioned candidate.

Never weaken a test simply because implementation is difficult. A legitimate requirement/threshold change needs an owner, rationale, version, impact analysis, and approval independent from the candidate run.

## Verification of the evaluation system

Verify at minimum:

- runner determinism under controlled seed/time/provider fixtures and explicit handling of inherently nondeterministic model output;
- schema/manifest/hash validation, dataset leakage checks, source/license/provenance, holdout separation, and no sensitive fixture/report leakage;
- metric unit tests with hand-calculated fixtures, boundary cases, missing data, ties, refusal/error distinctions, and confidence calculations;
- provider/retrieval/tool adapter contracts, deadlines/cancellation, token/cost accounting, and bounded concurrency;
- grader calibration, inter-rater agreement/disagreement analysis, blinded sampling, and reviewer identity/approval evidence;
- adversarial and critical cases cannot be excluded, averaged away, relabeled, or converted to pass by invalid output;
- reproduction from a clean environment using locked artifacts produces equivalent verdicts within declared tolerance;
- NIST AI RMF/GenAI Profile mappings link to actual owners, tests, monitoring, incidents, and decisions rather than generic claims;
- reports make no certification, legal-compliance, diagnostic-accuracy, or clinical-safety claim beyond the approved evidence.

An evaluation gate is complete only when the exact candidate and evaluation artifacts match, all designated critical cases pass, approved numeric thresholds pass, qualified reviewers sign their scopes, open conditions are explicit, and production/DR receives a reproducible evidence manifest.
