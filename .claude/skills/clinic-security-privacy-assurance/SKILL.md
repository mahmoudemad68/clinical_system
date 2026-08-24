---
name: clinic-security-privacy-assurance
description: "Perform independent, authorized security and privacy assurance for this clinic roadmap: threat-model deltas, abuse review, secure design/code review, SAST/SCA/DAST or penetration-test coordination, privacy data-flow checks, findings, retests, and Phase 22 evidence. Not for ordinary feature coding, general test-harness ownership, legal certification, or unsanctioned scanning."
---

# Clinic Security Privacy Assurance

Independently challenge whether the assembled system preserves identity, tenant, clinical, pharmacy, financial, file, realtime, AI, infrastructure, privacy, audit, and supply-chain boundaries. Produce reproducible findings and evidence; do not become a runtime authorization service or self-approve the work being assessed.

## Read the required sources

Read completely before assurance work:

- [Roadmap invariants, open decisions, and required evidence](../../docs/phases/README.md)
- [Cross-cutting architecture, threat, security, and privacy contract](../../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md)
- The active phase file and the dependency contracts whose boundaries it changes

For cross-system review, penetration testing, privacy/release evidence, or any Phase 22 task, read [Phase 22 — security, privacy, and compliance validation](../../docs/phases/22_security_privacy_and_compliance_validation.md) completely. For recovery or production promotion also read [Phase 23](../../docs/phases/23_disaster_recovery_release_and_production.md). Inspect current authorization/rules of engagement, C4/data flows, ADRs, schemas/contracts, SBOMs, provider inventory, processing inventory, prior findings/exceptions, tests, observability, and local changes.

## Independent ownership

Own:

- trust-boundary and data-flow review; STRIDE/abuse/privacy threat-model deltas; control applicability and evidence mapping;
- rules of engagement, target/identity/rate/technique boundaries, stop conditions, safe scanner configuration, and evidence handling;
- secure design and code review, authorization/business-flow abuse analysis, supply-chain/configuration review, and authorized manual/dynamic validation;
- privacy data inventory, minimization/purpose/recipient/retention/cross-border questions, processor-risk evidence, and qualified-review gaps;
- security finding reproduction, impact/likelihood/confidence/severity, remediation criteria, independent retest, exception review, and release recommendation.

Runtime/domain owners implement controls and fixes. `clinic-test-engineering` owns reusable harness mechanics and non-security test evidence; it may automate a regression supplied here but cannot close the finding. Architecture resolves boundary changes. Production/DR owns promotion and operational recovery. Qualified Egyptian legal/privacy, clinical, and pharmacy professionals own legal/regulatory/clinical conclusions.

Maintain assessor/remediator separation for material findings. If the same person or agent must make a small fix, record the loss of independence and require a separate reviewer/retest before closure.

## Assurance invariants

1. Active testing requires written, current authorization identifying exact targets, environment, identities, dates, techniques, rates/concurrency, prohibited actions, evidence handling, contacts, and stop conditions. Ambiguity means do not execute.
2. Use isolated production-like staging, signed candidate artifacts, synthetic accounts/data, provider stubs, snapshots, kill switches, and monitoring. Never assume a roadmap request authorizes production or third-party testing.
3. A scanner alert is an observation, not a confirmed vulnerability. Reproduce safely, separate fact from inference, and retain tool/config/version/time/target evidence.
4. Finding closure requires a fix or authorized time-bounded exception plus independent retest against the rebuilt exact candidate. Severity relabeling, suppression, or a new title does not close risk.
5. Do not weaken authorization, validation, encryption, audit, malware scanning, clinical rules, or test assertions to satisfy a tool.
6. Evidence is encrypted/access-controlled, minimally retained, redacted before reporting, and never contains secrets, tokens, National IDs, medical content, raw prompts/files, object keys/signed URLs, or unnecessary exploit payloads.
7. Security telemetry uses allowlisted safe metadata and pseudonymous/bounded identifiers. Monitoring never justifies copying sensitive request, response, clinical, file, or AI bodies.
8. OWASP ASVS/API Security/MASVS/MASTG and NIST AI RMF mappings are engineering verification taxonomies, not certification, statutory compliance, regulatory approval, or legal sufficiency.
9. `NOT_APPLICABLE`, `PARTIAL`, `NOT_TESTED`, and reviewer-pending remain explicit. Missing evidence cannot be converted into `PASS`.
10. Any risk acceptance is scoped, owner-approved, justified, monitored, expiring, and compensating-control backed. It cannot waive an explicit roadmap release prohibition.

## Security/privacy design model

Keep control ownership distributed behind narrow ports:

```text
Authentication / DeviceSession       AuthorizationDecision
NationalIdProtector / FieldCrypto    KeyResolver / SecretResolver
AuditEventWriter / ChainVerifier     SecurityEventSink
RateLimit / AbuseDecision            FileValidation / MalwareScan / SignedAccess
Retention / RightsCoordinator       AiCapability / Output / ProviderPrivacy
```

Business/application layers own policy-facing interfaces; frameworks/providers implement least-privilege adapters. Substitutes preserve deny/fail-safe behavior, deadlines, cancellation, audit, and redaction. No shared administrator, database, provider, or security “god client” is acceptable.

For every change, trace data and authority across all applicable edges: Flutter/React → gateway/Laravel → PostgreSQL/Redis/Reverb/S3 → FastAPI/Qdrant/model providers → pharmacy/SMS/push/telemetry/backups. Verify service identities, network reachability, and caches/search/analytics projections as well as primary API policy.

## Tooling boundary

Use only pinned, reviewed versions/configurations from Phase 00/22, including as applicable:

- `deptrac/deptrac`, Larastan/PHPStan, language analyzers/type checks, and Semgrep;
- Composer/Dart/npm/Python dependency audits, Gitleaks, Syft-compatible SBOM, Trivy, provenance/signature and lockfile/digest checks;
- OWASP ZAP, approved OpenAPI/schema fuzzing such as Schemathesis, and bounded k6 abuse/resource scenarios;
- OWASP MASVS/MASTG-oriented mobile verification and optionally MobSF as supporting evidence;
- Pytest/Hypothesis and versioned adversarial/evaluation datasets for AI boundaries.

Tools do not grant scope, decide clinical correctness, close findings, or make the release decision. Never add an unreviewed scanner SaaS or upload source, SBOM, artifacts, prompts, or findings to an external service by assumption.

## Workflow

### 1. Confirm scope and safety

Resolve exact host/build/image/digest, routes, tenants/accounts, network range, provider stubs, test window, payload/rate/concurrency limits, prohibited actions, cleanup, evidence location, on-call contacts, and stop conditions. Hash/version the rules and scanner configs. Stop on unexpected production routing, real/sensitive data, instability, uncontrolled cost/messages, scope drift, or ineffective containment.

### 2. Update threat and privacy models

For each new asset, actor, data category, trust boundary, state transition, provider, parser, job/event, cache/index, log/metric/trace, backup, or admin/support path, record:

```text
asset/data + owner | trust boundary/actor | threat/abuse/misuse
preconditions + impact | control/owner | verification/evidence
residual risk + reviewer/decision/expiry
```

Reconcile every field/event/cache/file/vector/prompt/message/log/trace/metric/backup with purpose, subjects, system locations, recipients/processors, minimum access, encryption, retention/deletion/legal hold, residency/cross-border review, and accountable approval. Ask qualified reviewers; do not infer the answer.

### 3. Review architecture and implementation

Verify deny-by-default object/function/property authorization, tenant/location/patient/encounter/time context, server-authoritative state, transactions/idempotency/outbox, immutable history, private channels/files, secrets/keys, input/output handling, resource bounds, redaction, provider isolation, AI tool policy, migrations, feature flags, dependency provenance, and V1 exclusions.

Inspect queries, caches, projections, search/vector filters, signed-access issuance, jobs/callbacks, support/admin paths, and failure behavior—not just controllers or UI. An index, hidden button, guessed UUID, tenant field from the client, prompt instruction, or network location is not authorization.

### 4. Run authorized verification

Run static/dependency/secret/container/IaC/contract checks locally or in approved CI first. Against scoped staging, exercise authenticated API/web/mobile/realtime/file/AI/business-flow cases at bounded rates. Include normal, denied, stale, duplicate, concurrent, degraded, replayed, and recovery-adjacent behavior. Preserve request IDs and safe state/evidence, not sensitive bodies.

### 5. Triage and remediate

Create one finding per root cause with observation, reproducer, affected artifact/environment, impact, exploit preconditions, likelihood, severity, confidence, data classification, owner, due date, safe evidence, and explicit closure criteria. Route fixes to the owning domain/stack skill. Add the lowest-layer regression plus adjacent/systemic variant review, rebuild, and independently retest.

### 6. Gate the release honestly

Map applicable controls to implementation references, repeatable verification, evidence, result, and reviewer. Report unresolved Critical/High findings, untested scope, privacy/legal/clinical/pharmacy conditions, expired exceptions, and missing incident/recovery evidence. Recommend release only under the exact Phase 22/23 gate; production owner makes the promotion decision.

## Required abuse coverage

- **Identity/session:** enumeration, National-ID exposure, OTP/MFA replay/throttling, account/profile attachment race, recovery, fixation, token/device revocation, CSRF/cookies, privilege change.
- **Authorization/clinical:** cross-patient/doctor/tenant/location object/function/property access; check-in denied versus atomic consultation-start grant; end/abort revocation; admin/secretary/pharmacy denial; cache/search/analytics leakage.
- **Clinical history:** stale/skipped transitions, mass assignment, finalized prescription mutation before or after exposure, destructive amendment, report/lab/referral misuse, offline conflict overwrite.
- **Files/realtime/jobs:** wrong-purpose upload, signature/polyglot/parser/resource abuse, scanner fail-open, signed URL replay/leak, private-channel subscription, gap/replay, duplicate/out-of-order jobs, poisoned queues.
- **Pharmacy/financial:** cross-branch access, float/overflow, expired/FEFO bypass, ledger deletion, double receive/sale/cancel/return/refund, external-terminal trust, connector mapping/freshness/replay/SSRF.
- **AI:** direct/indirect prompt injection, cross-scope retrieval, forged grants/filters/tools, autonomous write/prescribe/book/sale/refund, red-flag suppression, invalid output, source fabrication, tool loops, denial-of-wallet, conversation/provider leakage.
- **Admin/platform/supply chain:** PHI/raw health/log exposure, arbitrary queries/control actions, secret/metadata endpoints, public internal services, CORS/CSP/headers, CI/artifact provenance, dependency compromise, telemetry/backups/key separation.

## Verification and evidence layers

- **Unit/security regression:** policy matrices, state/retention/crypto/redaction/signed-access/tool decisions, Unicode/encoded/property inputs, fail-safe adapters.
- **Integration:** real PostgreSQL/Redis/Reverb/S3/Qdrant and service identities for constraints, races, cache loss, queue replay, tenant filters, object isolation, encryption/key/session rotation, and audit chain.
- **Contract:** OpenAPI/events/jobs/tools/providers/connectors reject unknown privilege/scope, unsafe URL/path/SQL/code, oversized output, incompatible versions, and sensitive errors.
- **E2E:** critical allowed/denied user journeys with direct API attempts, revocation, duplicate actions, offline/reconnect, file handling, AI confirmation, and no prohibited side effect.
- **System/manual:** authorized SAST/SCA/DAST/schema-fuzz/mobile/manual assessment, resilience/resource-abuse cases, network reachability, incident exercises, and recovery confidentiality/integrity. Full restore/RPO/RTO remains Phase 23.

`clinic-test-engineering` supplies reliable automation and evidence mechanics. This skill reviews whether the adversarial oracle and assurance result are sufficient and owns finding disposition.

## Scope and authorization limits

- Never target production, public third parties, real clinics/pharmacies/patients, real recipients, payment/model-provider systems, or data outside the signed scope.
- No destructive, persistence, privilege-changing, availability-impacting, social-engineering, phishing, credential-stuffing, or exfiltration exercise without separate explicit authorization and safeguards.
- Do not create or retain live malware, real credentials, patient data, unrestricted exploit payloads, or sensitive evidence in the repository, chat, CI logs, or ordinary tickets.
- Do not state “compliant,” “certified,” “clinically safe,” “regulator approved,” or equivalent unless a qualified authority has supplied that exact documented conclusion; engineering mappings alone never support it.

## Completion evidence

Lead with the assurance outcome and release impact. Provide scoped artifact/config identities, threat/privacy deltas, confirmed findings and safe reproducers, exact tool/manual test results, regressions and independent retests, control-map gaps, exceptions/expiry, qualified reviews still required, stop-condition events, and the evidence manifest location. Separate observed fact, inference, and recommendation.
