# Phase 22 — Security, Privacy, and Compliance Validation

## Objective

Independently validate that the assembled V1 enforces its identity, tenant, clinical, pharmacy, financial, file, realtime, AI, infrastructure, privacy, audit, and supply-chain boundaries under normal, malicious, concurrent, degraded, and recovery conditions. Close findings with reproducible engineering evidence, obtain clinical/pharmacy review where required, and record conservative configurable compliance assumptions. Legal sign-off is not a phase or release gate.

Security and privacy were implementation work in every prior phase. This phase is the cross-system assurance gate, not the first security pass and not a substitute for secure design or code review.

The assurance catalogs are verification mappings:

- OWASP Application Security Verification Standard (ASVS) 5.0.0 for applicable web/API/server controls.
- OWASP API Security guidance for API-specific authorization, resource, workflow, consumption, and configuration risks.
- OWASP MASVS and MASTG for the Flutter patient mobile release; Electron security guidance and applicable ASVS controls for the doctor/pharmacy desktop main, preload, renderer, utility, IPC, native-storage, navigation, packaging, signing, and update boundaries.
- NIST AI Risk Management Framework and its Generative AI Profile for governance, mapping, measurement, and management of AI risks.

Mapping or passing selected controls is **not** a claim of certification, statutory compliance, regulatory approval, or legal sufficiency.

## Plan traceability

- Sections 6-14, lines 271-598: account/profile separation, encrypted/HMAC National ID, verified registration/matching, roles, contextual doctor access, and secretary denial.
- Sections 30-36 and 40-42, lines 1078-1299 and 1392-1481: prescription state/amendment/audit and private scanned-file/OCR pipeline.
- Sections 46-47, lines 1551-1606: completed-appointment review eligibility and 48-hour encounter chat boundary.
- Sections 51-66, lines 1697-2112: batch/ledger/FEFO, irreversible financial history, pharmacy tenancy, connector mapping/idempotency/freshness.
- Sections 71-98, lines 2214-2878: Qdrant tenant scopes, separate knowledge domains, tool boundary, retrieval/versioning, AI write denial, deterministic patient red flags, provider abstraction, traces, prompt injection, and conversation storage.
- Sections 99-107, lines 2879-3108: private channels, delivery/queues/outbox, API rules, and idempotency.
- Sections 110 and 116-124, lines 3214-3228 and 3322-3514: non-enumerable IDs, authentication, security, MFA, rate limiting, audit/tamper evidence, redacted logs, privacy, and private Qdrant.
- Sections 126-131, lines 3536-3639: encryption/isolation of backups and rebuildable AI data; full restore validation is Phase 23.
- Sections 141-143, lines 3857-3915: core/AI availability separation, health endpoints, and monitoring.
- Sections 152-159, lines 4085-4267: safe client retries/offline behavior, test pyramid, and critical authorization/prescription/pharmacy tests.
- Sections 163-170, lines 4331-4502: AI/clinical validation, environment separation, CI, safe migrations, secrets, and flags.
- Sections 171-176, lines 4503-4717: V1 exclusions, sources of truth, consistency, asynchronous architecture, execution order, and release definition of done.

## Entry criteria and dependencies

- Phases 00-21 are deployed to an isolated, production-like staging environment with locked images/dependencies, synthetic test data, test accounts/tenants, observability, kill switches, and restoreable snapshots.
- Architecture diagrams, data-flow inventory, module/table ownership, OpenAPI/events/tool schemas, threat models, SBOMs, data classification, provider inventory, and prior test evidence are current.
- An accountable scope owner approves written rules of engagement for manual/automated testing, including targets, identities, time, techniques, rate/concurrency, prohibited actions, evidence handling, stop conditions, and contacts.
- Privacy/security, clinical, and pharmacy project contacts are identified. Optional legal advice may refine recorded assumptions, but its absence does not block implementation, validation, or completion. Engineering does not claim statutory or regulatory approval.
- Incident response and emergency credential/feature revocation contacts are available during testing.

## Non-goals

- No claim that OWASP or NIST mapping equals certification or Egyptian legal/regulatory compliance.
- No testing of public third-party targets, real clinics/pharmacies, real patients, production data, real SMS/push recipients, payment networks, or model-provider systems beyond contract-authorized use.
- No destructive, persistence, privilege-changing, availability-impacting, social-engineering, phishing, credential-stuffing, or data-exfiltration exercise without separate explicit authorization and safeguards.
- No weakening/disablement of validation, encryption, audit, authorization, malware scanning, or clinical controls to make a scanner pass.
- No complex admin roles, online payments, emergency specialist chat, medication alternatives/reservations, branch transfer, supplier API, adherence, image diagnosis, or multi-country feature validation because they remain out of V1.
- No storing raw exploit payloads, credentials, tokens, medical content, or sensitive evidence in tickets, chat, source control, CI logs, or generated reports.

## Security assurance ownership and service boundaries

### Control ownership remains distributed

```text
Identity/Auth           credentials, OTP, MFA, sessions, device revocation
Authorization           policy decisions using server-owned context
Clinical                encounter grants, medical writes, prescription invariants
Pharmacy/Financial      tenancy, ledger, invoice/refund invariants
Files                   upload quarantine, scan, signed delivery, access log
AI/KnowledgeBase        scope filters, tool policy, deterministic safety, traces
Platform                network, secrets, images, telemetry, backups, incident controls
Privacy/Compliance      processing inventory, retention, rights, processors, assumptions
Security Assurance      mapping, independent tests, findings, evidence, release recommendation
```

The assurance function verifies owners; it does not become a runtime god service and cannot grant access or bypass module business rules and backed-enum transitions.

### Security/privacy services

```text
AuthenticationService / DeviceSessionService
AuthorizationService
NationalIdProtector
FieldEncryption / KeyResolver
AuditEventWriter / AuditChainVerifier
SecurityEventSink
RateLimitPolicy / AbuseProtectionService
FileTypeValidator / MalwareScanner / SignedObjectAccess
SecretResolver / WorkloadIdentityVerifier
DataRetentionPolicy / DataSubjectRequestService
AiCapabilityPolicy / AiOutputPolicy / ProviderPrivacyPolicy
```

- **Single responsibility:** authentication, authorization, encryption, audit, abuse control, file scanning, secrets, retention, and AI policy are separately owned/testable.
- **Open/closed:** providers/scanners/key managers add reviewed adapters behind stable contracts.
- **Liskov substitution:** adapters preserve deny/fail-safe behavior, typed errors, deadlines, audit, and no-secret leakage.
- **Interface segregation:** workloads receive only required operations/credentials; no shared administrator/provider “god client.”
- **Conventional services:** owning Laravel modules implement policy, crypto, audit, and retention services; genuinely replaceable frameworks/vendors may sit behind small provider interfaces.

## Assurance packages and tools

All tool versions/configuration images are pinned in the evidence manifest. Findings require manual confirmation where scanners can be wrong.

### Source, dependency, and architecture assurance

- Current `deptrac/deptrac` for PHP module-boundary enforcement, plus Larastan/PHPStan and Laravel Pint.
- Dart analyzer/lint for patient mobile, TypeScript type-aware ESLint for Electron desktop and browser admin, Python type/static checks selected in Phase 00, and Semgrep rules covering all languages and Electron privileged-process boundaries.
- Composer, Dart/Flutter, Electron/JavaScript, and Python dependency audits; Gitleaks for secrets; Syft-compatible SBOM; Trivy for image/IaC/dependency findings. Electron SBOM/provenance includes bundled Chromium/Node, native modules, packager plugins, and per-platform artifacts.
- Provenance/signature verification for release artifacts and lockfile/digest enforcement.

### Dynamic/API/web/mobile assurance

- OWASP ZAP for authorized staged DAST and OpenAPI-aware scanning.
- Schemathesis or an ADR-approved schema/property fuzzer for negative OpenAPI tests with explicit rate/target controls.
- k6 security-abuse/load scenarios for resource limits and retry amplification.
- Flutter patient release verification maps to OWASP MASVS/MASTG; MobSF or equivalent may support static/dynamic inspection, but manual verification decides findings.
- Patient Drift persistence uses `sqlite3` v3 native hooks with SQLCipher or SQLite3MultipleCiphers as approved and compatibility-tested. Do not introduce the EOL `sqlcipher_flutter_libs` package.
- Electron desktop assurance uses WebdriverIO `@wdio/electron-service` as the default packaged-app harness, plus direct main/preload unit and integration tests. Playwright Electron is permitted only after the approved experimental-launcher compatibility spike. Verify the Electron Forge Webpack/TypeScript pipeline, target makers, auto-unpack/rebuild of native modules, code signing/notarization, ASAR integrity, and Electron fuses on the exact candidate.
- Electron desktop persistence uses an approved Node SQLite native binding built against SQLCipher outside the renderer. Main owns and authorizes the store, preferring utility-process execution where the target-OS/ABI spike supports it; main wraps the random database key with Electron `safeStorage`. Linux `basic_text` or any unavailable OS-backed protection fails closed for PHI and long-lived secrets.
- Browser tooling for CSP/headers/CSRF/XSS/session/accessibility interaction tests; proxy inspection uses synthetic accounts/data only.

### AI assurance

- Existing Pytest/Hypothesis/provider-contract/evaluation harnesses and adversarial datasets.
- NIST AI RMF/Generative AI Profile mapping artifacts plus versioned model/prompt/retrieval/tool/rule inventory.
- No scanner/model judge is allowed to authorize a tool, approve a clinical threshold, close a finding, or make the release decision alone.

## Assurance artifact schemas and invariants

Security findings and sensitive evidence belong in a restricted assurance/evidence system, not the product database or public repository.

```text
assurance-control-mapping.yaml
  control_catalog / catalog_version
  control_id
  applicability APPLICABLE | NOT_APPLICABLE | PARTIAL
  rationale
  system_component / owner
  implementation_reference
  verification_procedure
  evidence_ids
  result PASS | FAIL | NOT_TESTED
  reviewer / reviewed_at

security-finding.json
  finding_id
  title / category
  affected_component / environment / version
  observation
  hypothesis
  validation_method
  evidence_ids
  impact / likelihood / severity / confidence
  data_classification
  owner / due_at
  status NEW | TRIAGED | REMEDIATING | READY_TO_RETEST |
         CLOSED | RISK_EXCEPTION_REQUESTED | RISK_ACCEPTED
  remediation_reference / retest_evidence_ids

assurance-evidence.json
  evidence_id
  test_case_id / tool / tool_version / configuration_hash
  scope_id / target_identity
  started_at / completed_at
  artifact_hash / encrypted_object_reference
  redaction_status / retention_policy
  result / safe_summary
  collector_identity / reviewer

processing-activity.yaml
  data_category / subjects / source / purpose
  system_locations / recipients / processor
  legal-basis-review_reference
  retention / deletion_or_anonymization
  encryption / access_roles / cross_border_review
  owner / reviewer / approval_status
```

### Assurance invariants

1. Scope and authorization are machine-readable and rechecked before active tests; ambiguous scope means do not execute.
2. Evidence separates observation from inference, preserves tool/config/version/time, is encrypted/access-controlled, and is redacted before reporting.
3. A scanner result is not a confirmed vulnerability until reproducible validation supports it.
4. Finding closure requires a fix or approved exception plus independent retest evidence; changing severity/title does not close risk.
5. Production gate has no unresolved Critical finding and no unresolved High finding in an exposed, identity, authorization, clinical, prescription, pharmacy-financial, file, AI-tool, secret, or recovery boundary.
6. Any exceptional acceptance is time-bounded, owner-approved, compensated/monitored, linked to risk, and cannot waive the explicit §176 “no critical security findings” rule.
7. Test payloads/evidence never use or expose real personal/clinical/credential data.
8. Control mapping records `NOT_APPLICABLE` with rationale; it never converts partial evidence into a pass.
9. Legal/PDPC/EDA/clinical/pharmacy approvals are referenced as qualified-review decisions, never inferred from engineering mappings.
10. Security events/audit are operational evidence but never a substitute for independent testing.

### Runtime schema and index validation

This phase adds no new public product schema. It independently reviews the deployed migrations, constraints, query plans, and authorization predicates for at least:

- unique keyed National-ID lookup and encrypted value separation;
- user/device/session/OTP/MFA lookup, expiry, revocation, and rate-limit keys;
- appointment overlap/state-event, encounter/access-grant, and doctor/patient contextual indexes;
- prescription version/exposure/amendment, file-access, chat-window, and notification-delivery constraints;
- branch/medication/batch-expiry, immutable stock-movement/balance, invoice/return/refund, connector/idempotency, and freshness indexes;
- knowledge active-version/scope and Qdrant tenant payload indexes, AI run/tool invocation, audit actor/entity/time, and outbox/event-deduplication indexes.

An index is not an authorization control: representative query plans must retain actor/tenant/branch/patient/encounter/state predicates and return no broader rows through cache, replica, search, analytics, or vector adapters. Missing constraints/indexes, plaintext duplicate fields, unbounded JSON/free-text indexes, or queries that filter protected scope only in the client/application after fetching are release findings.

## Verification mapping

### OWASP ASVS 5.0.0

Create applicability and evidence mappings covering at least architecture/threat modeling, authentication, sessions/tokens, authorization, input/output handling, cryptography, errors/logging, data protection/privacy, communications, malicious code/dependencies, business logic, files/resources, API/web service, configuration, and safe realtime behavior as applicable. Map requirements to concrete tests and implementation references; do not merely state “ASVS compliant.”

### OWASP API Security

Explicitly test object-level and property-level authorization, broken authentication, unrestricted resource consumption, function-level authorization, sensitive business-flow abuse, SSRF, security misconfiguration, API inventory/version drift, and unsafe consumption of provider/integration data. Include REST, internal FastAPI, AI tools, signed upload/download, Reverb authorization, webhooks/connectors, cursor/idempotency, and generated-client contracts.

### OWASP MASVS/MASTG

For the Flutter patient mobile artifact verify storage, cryptography, authentication/session, network, platform interaction, code quality/update/integrity, privacy, and resilience requirements selected by the threat model. Doctor/pharmacy Electron desktop receives the explicit platform assessment below rather than being counted as a Flutter/MASVS artifact. Root/jailbreak or certificate-pinning decisions require an ADR/threat/cost/operability review; absence is not silently marked pass.

### NIST AI RMF and Generative AI Profile

- **Govern:** accountable owners, acceptable use, model/provider inventory, third-party risk, incident/change policy, human/clinical oversight, documentation, and retirement.
- **Map:** patient/doctor/pharmacy contexts, affected people, data flows, failure/misuse scenarios, privacy/residency, core independence, and risk tolerance.
- **Measure:** retrieval/grounding/hallucination/refusal, red flags/routing, tool authorization/correctness, prompt injection, tenant leakage, invalid output, latency/cost, drift and human review.
- **Manage:** deterministic controls, least capability, feature flags/kill switches, staged promotion, monitoring, incident response, rollback, provider/model change gates, and residual-risk decisions.

This is an AI-governance verification map, not a claim that NIST certifies the product or determines clinical/legal acceptability.

## Detailed assurance flows

### 1. Scope and rules of engagement

1. Resolve exact staging hosts, APIs, app builds, internal test routes, tenants/accounts, network ranges, provider stubs, dates, rates, techniques, and prohibited actions.
2. Confirm ownership/authorization and route all scanners through allowlisted egress and test accounts.
3. Define stop conditions for data exposure, unexpected production routing, uncontrolled spend, service instability, or scope ambiguity.
4. Configure kill switch, test-rate/concurrency/payload limits, evidence encryption/redaction, cleanup, and on-call contacts.
5. Hash/sign the scope/config and attach them to every test run.

### 2. Architecture and data-flow review

1. Walk each trust boundary from the Flutter patient app, Electron doctor app, Electron pharmacy app, and browser React admin through gateway/Laravel/PostgreSQL/Redis/Reverb/S3/FastAPI/Qdrant/providers/connectors/telemetry/backups.
2. Reconcile C4/module diagrams, deployed inventory, firewall/service identities, API/events/tools, data inventory, and SBOM. Unknown/shadow routes/services fail the gate.
3. Update STRIDE/abuse/privacy threats for account recovery, tenant access, clinical context, financial movements, files/OCR, realtime, queues/retries, AI retrieval/tools, connectors, admin analytics, observability, CI/CD, secrets, backups, and support access.
4. Verify each mitigation has an owner and executable evidence; document residual risk rather than relying on prose.

### 3. Automated pipeline assurance

1. On pull request: architecture/static/type/lint, unit/authorization/security tests, secret scan, SAST, dependency/license, IaC/container scan, OpenAPI/event/tool compatibility, and SBOM.
2. On signed candidate artifact: verify provenance/digest, deploy isolated staging, migrate safely, run smoke/DAST/schema fuzz/mobile release inspection, and compare against the previous evidence baseline.
3. Block on policy severity, incompatible contract, secret, unsigned artifact, missing SBOM, or unapproved dependency/provider/model/prompt/rule change.
4. Findings enter the same lifecycle and cannot be suppressed only in CI configuration without reviewed rationale/expiry.

### 4. Manual/API/business-logic penetration validation

1. Use synthetic accounts for patient, Doctor A/B, secretary, admin, pharmacy owner, Branch A/B, approved/pending/revoked states.
2. Verify authentication recovery, OTP/MFA throttling/replay, session/device/CSRF/cookie/token behavior, logout/revocation, and privilege changes.
3. Exercise object/function/property authorization across patients, encounters, files, prescriptions, labs, chat, appointments, reviews, branches, inventory, invoices/refunds, KB, AI runs/tools, analytics, health, and internal endpoints.
4. Test state/concurrency abuse: skipped/backward/stale transitions, idempotency-key mismatch, double booking/sale/refund/receive, prescription mutation, ledger deletion, signed-URL replay, queue/event duplicate, and race/time-of-check-time-of-use.
5. Test parsers/input/output: injection, mass assignment, traversal, MIME/signature mismatch/polyglot, archive/decompression limits, malicious PDF/image metadata, SSRF, unsafe deserialization, XSS/CSV/formula, request smuggling assumptions, and error leakage.
6. Confirm attempts have no unauthorized effect, safe response, appropriate audit/security signal, and no secret/PHI leakage.

### 5. AI adversarial validation

1. Test direct and indirect prompt injection in questions, KB documents, OCR text, catalog/integration content, and tool results.
2. Attempt cross-doctor/cross-specialty/cross-patient/cross-pharmacy scope access, forged filters/grants/tools, autonomous write/prescribe/book/sale/refund, and conversation-memory leakage.
3. Attempt deterministic red-flag suppression, urgency downgrade, fake confirmation, tool loops, excessive output/tokens, denial-of-wallet, unsafe markup/links, provider fallback, and source fabrication.
4. Verify policy before retrieval, before tool execution, before sensitive delivery, and after revocation/state changes.
5. Run versioned clinical/pharmacy/patient evaluation and compare model/prompt/retrieval/rule/provider changes. Critical safety cases use a zero-miss promotion rule where clinical governance designates them critical.

### 6. Privacy and regulatory review

1. Reconcile every field/event/cache/file/vector/prompt/message/log/trace/metric/backup with processing purpose, data category, subject, owner, retention, encryption, role access, recipient/processor, and deletion/anonymization behavior.
2. Verify minimization, purpose limitation, separation of patient AI conversations from medical records, transient location handling, no raw staging clone, safe support access, and no sensitive analytics dimensions.
3. Exercise approved access/correction/deletion/retention workflows and legal holds where defined, recording effects on PostgreSQL, S3 versions, Qdrant rebuildable indexes, caches, analytics, providers, and backups.
4. Review external LLM/SMS/FCM/S3/monitoring/processors for contracts, security, training use, retention, residency/cross-border transfer, subprocessors, deletion, incident notice, and continuity.
5. Privacy/security owners document conservative configurable assumptions for PDPC, provider, retention, rights, and cross-border handling. Qualified clinical/pharmacy reviewers validate medication content, patient wording, and operational safety where required. Optional legal advice may refine these decisions without blocking work.
6. Record explicit decisions, conditions, gaps, owners, and expiry. Do not translate an engineering control into a legal conclusion.

### 7. Finding remediation and retest

1. Triage against reproducibility/evidence, affected boundary, impact, exploit preconditions, likelihood, confidence, and data/clinical/financial consequences.
2. Assign owner/deadline and contain immediately when needed through feature flag, credential rotation, network block, provider disablement, or cohort restriction.
3. Fix the root cause without weakening the invariant/test; add regression at the lowest proving layer plus broader negative coverage.
4. Rebuild the immutable candidate, rerun focused then relevant full suites, and link independent retest evidence.
5. Review adjacent variants and systemic occurrences before closure.

## Runtime API, event, and job contracts

### No public assurance control plane

Phase 22 adds no public scanner, finding, secret, log, or penetration-testing API. Assurance tools run from controlled CI/security infrastructure against explicit targets. The product exposes only the already specified authenticated business/health APIs.

### Security event contract

Existing internal `SecurityEventSink` accepts a versioned allowlist:

```text
event_id / type / schema_version / occurred_at
actor_or_workload_pseudonymous_id nullable
tenant_scope_hash nullable
resource_type / resource_id_hash nullable
decision ALLOWED | DENIED | BLOCKED | REVOKED
policy_version / safe_reason_code
request_id / correlation_id / source_service
```

It never accepts passwords, tokens, National ID, phone, raw request/response, medical content, prompt/answer, file body/object key, exploit payload, or stack trace. High-severity signals route to the approved alert/incident sink; the event itself cannot grant/revoke access except through a separately authorized deterministic command.

### Required security/privacy jobs

- Audit-chain verification, expired session/device/token cleanup, OTP abuse cleanup, idempotency/outbox retention, stale signed-upload cleanup, file quarantine reconciliation, access review, secret/certificate/key-age checks, dependency/SBOM monitoring, data-retention/clear workflows, Qdrant deletion/rebuild reconciliation, and AI evaluation regression.
- Jobs are least-privilege, idempotent, bounded, cancellable, audited, and fail visibly. Retention jobs do not bypass configured legal-hold/retention policy and never directly delete immutable clinical/financial history contrary to module business rules.

## Client and release-hardening work

### Flutter patient mobile

- Verify secure token/key storage, approved encrypted local drafts, logout/revocation/expiry cleanup, backup exclusion, file permissions, clipboard/screenshot/background-preview policy where supported, and no secrets in binaries/logs/crash reports.
- Use Drift over `sqlite3` v3 native hooks with the approved SQLCipher/SQLite3MultipleCiphers integration and compatibility tests across supported mobile OS/architectures; do not use EOL `sqlcipher_flutter_libs`.
- Verify TLS/hostname/certificate handling, no cleartext fallback, safe deep links/intents/file opening, store update provenance, notification privacy, WebSocket auth/reconnect, and safe error rendering.
- Certificate pinning, root/jailbreak detection, anti-tamper, and obfuscation are threat-model decisions with operational/recovery tests, not checkbox claims.

### Electron doctor/pharmacy desktop

- Package only local renderer content behind the approved application protocol and a restrictive CSP. Every production `BrowserWindow` sets `contextIsolation: true`, `sandbox: true`, `nodeIntegration: false`, `nodeIntegrationInWorker: false`, `webviewTag: false`, and `webSecurity: true`; devtools and debug endpoints are disabled in release builds.
- Treat the React renderer as untrusted. It has no Electron/Node imports, raw `ipcRenderer`, token/key, API/realtime credential, database, arbitrary filesystem/path, shell, printer, provider SDK, or updater access. It may call only one-purpose typed methods exposed through `contextBridge` by the context-isolated preload.
- Main validates `event.senderFrame`/owning window, active session/device/branch/encounter, channel allowlist, Zod-or-equivalent schema, unknown fields, byte/count/depth limits, deadline, cancellation, and output shape for every request. Never expose generic `send`, `invoke`, `on`, channel names, callback event objects, renderer-provided URLs/SQL/commands, or authorization scope.
- Main owns device credentials, OpenAPI TypeScript transport, private Reverb authorization/subscriptions, external navigation decisions, files/printing, secret access, updater control, and authorization of native storage. Native/blocking SQLite or parser work prefers a least-capability utility process where the target-OS/ABI spike supports it; renderer crashes/reloads do not transfer capability.
- Deny unexpected navigation, `window.open`, webviews, permissions, downloads, and new windows by default. Allowlisted external HTTPS links open only after canonical URL validation and explicit user action in the OS browser; no clinic auth headers/cookies accompany them.
- Store approved offline data only in SQLCipher-backed SQLite outside the renderer; never in renderer localStorage, IndexedDB, browser cache, or plaintext files. Main wraps a random database key with `safeStorage`, excludes the database from backups where required, and fails closed if OS-backed secret protection is unavailable. Wrong-key handling must not create or replace a blank database.
- Verify signed/notarized packages, ASAR integrity, production Electron fuses, update-signature/provenance validation, safe update points for pending drafts/outbox items, migration/rollback compatibility, and removal of test certificates/endpoints. The renderer cannot choose an update URL or trigger installation outside a narrow policy-controlled capability.
- Verify Arabic/English `lang`/`dir`, MUI RTL behavior, keyboard/focus order, screen-reader announcements, zoom/contrast, scanner/printer interaction, and safe bidi/error rendering in packaged Windows/macOS/Linux targets selected for release.

### React admin

- Verify HttpOnly/Secure/SameSite cookie session, CSRF, MFA, CSP, HSTS/headers, exact CORS, XSS/DOM sanitization, clickjacking defense, dependency integrity, route authorization, cache/history/logout behavior, and no token in local storage.
- Admin UI hiding is never authorization. Direct requests for clinical/raw health/log/analytics data must be denied by Laravel.

### All clients

- Generated API contracts, bounded input/output, safe URL handling, Arabic/English/bidi rendering, accessibility, update/deprecation behavior, local data inventory, and privacy disclosures are release-tested.
- Debug menus, dev endpoints, verbose logs, test certificates, mock providers, feature overrides, and staging credentials are absent from production artifacts.

## Test plan

### Unit tests

- Policies for every role/resource/action/state/time/location context, including denial defaults and revocation.
- National-ID normalization/HMAC uniqueness/encryption/redaction; session/MFA/OTP/rate/idempotency states; audit hash chain; signed URL; tool/proposal grant; retention decisions.
- State machines reject skipped/stale/duplicate/backward transitions for appointment/encounter/prescription/lab/invoice/stock/return/refund/AI.
- Parsers/validators/redactors/sanitizers/security-event schemas and metric-label allowlists use property/fuzz tests with Arabic/Unicode/encoded payloads.
- Electron renderer tests prove UI/business logic cannot import privileged modules; preload tests expose only named capability methods; main tests enforce sender/session/scope/schema/size/deadline/cancellation policy and safe error mapping for every handler.

### Integration tests

- Real PostgreSQL/Redis/S3/Reverb/Qdrant verify transaction/race/idempotency, tenant filters, cache loss, queue replay, private channels, object quarantine/access expiry, encryption/keys, and audit chain.
- Service identities/network policies deny direct public/internal cross-service access and broad database credentials.
- Secret rotation/revocation, session/device revocation, migration-role isolation, provider timeout/error/redaction, and data-retention propagation.
- Patient Drift encrypted-database creation/open/migration/key lifecycle across supported mobile targets.
- Electron SQLCipher creation/open/wrong-key/no-blank-replacement/migration/rekey/key-wrap/logout/revocation lifecycle, native ABI rebuild, and main-owned store authorization pass in Forge Webpack/TypeScript packaged artifacts on every supported OS/architecture. Prefer utility-process execution where the OS/ABI spike proves support; otherwise isolate synchronous access behind the main-owned adapter and prove renderer/event-loop safety.
- Real Electron main/preload/renderer integration verifies context isolation/sandbox settings, private Reverb authorization/reconnect, window lifecycle, capability revocation, and utility crash/restart behavior where used.

### Contract tests

- OpenAPI/event/tool/internal-service/provider/connector schemas reject unknown privilege/scope/code/URL/SQL fields, incompatible versions, oversized output, and unsafe errors.
- Every adapter preserves typed denial/timeout/cancellation/freshness and least-privilege semantics.
- Assurance mapping/evidence/finding schemas validate, require reviewer/provenance, and prevent false pass/closure states.
- Generated TypeScript OpenAPI clients and versioned preload/IPC schemas cover every Electron capability. Generic channels, unknown fields, stale scope, invalid sender frames, oversized payloads, arbitrary URL/path/SQL/command fields, and privilege-bearing renderer arguments are rejected.

### End-to-end tests

- Critical §157/ADR tests: Doctor A cannot access Doctor B private KB; full cross-doctor history is denied while an appointment is merely booked, checked in, or waiting; `Start Consultation` grants the contextual access; `End Consultation` or abort revokes it while preserving access only to the doctor's own contributions; secretary/admin cannot access clinical content; patient cannot edit diagnosis or review an uncompleted appointment.
- Critical §158 plus Phase 06 safety tests: every finalized/patient-readable version is immutable before and after exposure; exposure is only an audit/release milestone; amendment preserves the original; correction notifies; original remains retrievable; concurrent update cannot overwrite.
- Critical §159 pharmacy tests: FEFO, no expired sale, cancellation reversal, partial receive, receive/refund/sync idempotency.
- File, chat, AI tool, patient triage/confirmation, admin analytics/health, session revocation, and client offline/retry journeys include denied paths.
- Packaged Electron doctor/pharmacy journeys run with WebdriverIO `@wdio/electron-service` by default. Playwright Electron is allowed only after the approved experimental-launcher compatibility spike; tests cover install/first launch, deep-link denial, external-link allowlist, barcode/print/file capabilities, encrypted draft recovery, offline/reconnect, logout/device revocation, and update-required states.
- XSS/hostile AI or API content in a renderer cannot obtain Node/Electron, invoke an unregistered capability, navigate to a privileged origin, read tokens/keys/SQLCipher data, or trigger shell/file/print/update behavior.

### System, security-penetration, and resilience tests

- Authenticated manual/API/web/mobile assessment under approved scope plus automated SAST/DAST/schema fuzz/dependency/container/IaC/secret/SBOM evidence.
- Cross-tenant/object/function/property, business-flow abuse, concurrency/replay, parser/file, SSRF, XSS/CSRF, secret exposure, network reachability, telemetry leakage, and resource-exhaustion scenarios.
- AI/Qdrant/Redis/provider/worker/node failure and prompt/tool adversarial tests preserve core/invariants and generate safe signals.
- Backup confidentiality/integrity/access/key-separation is tested here; complete restore/RPO/RTO/failover is Phase 23.
- Exact signed Electron candidates are inspected for `contextIsolation`, sandbox, Node/webview disablement, CSP, permission/navigation/window denial, ASAR integrity, production fuses, signatures/notarization, native ABI linkage, secret canaries, and update provenance. Install/uninstall/update/rollback and wrong-key/locked-keychain/Linux secret-store failure are tested on each supported target.

### Clinical, pharmacy, privacy, and AI validation

- Versioned datasets and qualified review for doctor/pharmacy/patient AI, red flags, routing, grounding, hallucination, refusal, tool correctness, multilingual wording, and critical case failures.
- Qualified local reviews of privacy notices/consent, processing inventory, provider/cross-border terms, retention/rights/breach processes, pharmacy/medication policies, clinical wording, and release constraints.
- Results include gaps/conditions/expiry; “not reviewed” cannot be recorded as pass.

## Observability, incident readiness, migration, and rollout

### Security/privacy observability

- Monitor auth/session/MFA/OTP anomalies, authorization denials, idempotency mismatch, signed URL/file scan failures, audit-chain breaks, ledger/reconciliation breaks, admin access, cross-tenant/AI-tool denials, prompt injection, cost abuse, secret/dependency findings, backup access, and retention failures.
- Signals use bounded safe labels and pseudonymous IDs; security monitoring does not justify copying sensitive bodies.
- Alert rules have severity, owner, sustain, correlation, runbook, containment, escalation, and evidence-preservation steps.

### Incident exercises

Rehearse at least stolen device/session, leaked provider secret, unauthorized clinical access suspicion, malicious upload, cross-tenant AI retrieval attempt, runaway AI cost, dependency compromise, audit-chain failure, and backup exposure. Each exercise covers detection, containment/feature kill, credential rotation, evidence preservation/redaction, qualified notification decision, recovery, and post-incident fixes.

### Migration and release process

1. Freeze candidate code/contracts/config/model/prompt/rules/dependencies and build signed immutable artifacts.
2. Run mapping/applicability review, automated pipeline, targeted manual tests, privacy/data inventory, AI/clinical/pharmacy evaluation, and incident exercises.
3. Remediate/rebuild/retest; do not patch a different artifact than the release candidate.
4. Validate production configuration/network/secrets/feature flags using safe checks, not production data extraction.
5. Security, privacy, clinical, pharmacy, operations, and product owners record their scoped evidence and conditions. Legal sign-off is not required.
6. Phase 23 performs restore/failover/deployment/rollback rehearsal before final go-live.

## Acceptance and exit gate

- Every applicable OWASP ASVS 5.0.0, OWASP API Security, and OWASP MASVS/MASTG selection has applicability rationale, implementation reference, repeatable verification, evidence, owner, and reviewed result.
- NIST AI RMF/Generative AI Profile governance/map/measure/manage evidence covers all three AI personas, providers, data/retrieval, tools, deterministic controls, monitoring, incidents, and change/retirement.
- Critical authorization, prescription, pharmacy, file, realtime, admin, AI-scope/tool, patient-red-flag/confirmation, and audit tests pass under normal/concurrent/degraded conditions.
- No unresolved Critical finding exists; no unresolved High remains in exposed or identity/authorization/clinical/financial/file/AI-tool/secret/recovery boundaries.
- SAST, DAST, dependency/license, secret, SBOM/provenance, container/IaC, API/schema fuzz, mobile/desktop/web, manual penetration, and evidence-integrity gates pass against the exact signed candidate.
- Canary national IDs, phones, credentials, clinical/prescription/lab text, prompts, and object keys are absent from logs/traces/errors/metrics/reports.
- Privacy/security assumptions for PDPC/cross-border/provider/rights/retention handling are explicit, configurable, and tested; qualified clinical/pharmacy review covers applicable medication and healthcare safety constraints. Optional legal advice may refine the assumptions, but no missing legal review is release-blocking.
- Incident exercises, kill switches, rotations, dashboards, alerts, runbooks, data-retention/rights workflows, and finding lifecycle are operational.
- Assurance mappings are presented as verification evidence only, with no unsupported certification or legal-compliance claim.
- All V1 exclusions remain disabled/absent, and Phase 23 receives a signed security/privacy/clinical/pharmacy release recommendation plus complete evidence manifest.
