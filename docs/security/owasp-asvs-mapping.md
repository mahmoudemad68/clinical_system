# OWASP verification mapping — Phase 00

Engineering assurance taxonomy only. This file does **not** claim statutory
compliance, certification, regulatory approval, or legal sufficiency
(Phase 00 security work; Phase 22 remains the independent assurance phase).

Versions mapped:

- OWASP ASVS 5.0.0 (web/API platform)
- OWASP API Security Top 10 2023
- OWASP MASVS / MASTG (Flutter patient mobile; Electron is out of MASVS scope
  and is covered by the desktop trust-boundary tests instead)

Status values: `APPLIED`, `PARTIAL`, `NOT_APPLICABLE`, `NOT_TESTED`. Missing
evidence stays `NOT_TESTED`. A later phase cannot convert that into `APPLIED`
without a new row.

## OWASP ASVS 5.0.0 (selected)

| ASVS area | Phase 00 control | Status | Evidence |
| --- | --- | --- | --- |
| V1 architecture | Modular monolith, AI isolation, CODEOWNERS, deptrac | `PARTIAL` | ADRs 0001–0010; G-01-01…G-01-05. GitHub teams not live. |
| V2 authentication | Not implemented. Deny-by-default; diagnostics uses a synthetic token | `NOT_APPLICABLE` | Phase 01 |
| V3 session | Admin cookie session is Phase 01. API is not stateful. | `NOT_APPLICABLE` | `statefulApi(false)` |
| V4 access control | Deny-by-default; no object IDs yet | `PARTIAL` | Channel callbacks return false; public routes are health only |
| V5 validation | Request size, JSON depth, closed OpenAPI additionalProperties | `APPLIED` | `EnforceRequestBounds`; DiagnosticsSliceTest |
| V6 cryptography | TLS for non-local hops planned; local Compose binds localhost | `PARTIAL` | G-05-04. Staging TLS not configured. Local encryption spike OPEN (G-06-01) |
| V7 error handling | Stable machine codes; no stack/SQL/object keys | `APPLIED` | ErrorCode; DiagnosticsSliceTest leak assertions |
| V8 data protection | Classification policy, redaction processor, canary suite | `PARTIAL` | G-05-01…G-05-03, G-07-05. Privacy owner approval OPEN (G-08-04) |
| V9 communication | Private DB/Redis/S3; no wildcard CORS | `PARTIAL` | cors.php; Compose bind 127.0.0.1 |
| V10 malicious code | Dependency policy, Semgrep, SBOM job written | `PARTIAL` | ADR 0008; SF-001 High open; CI never executed on GitHub |
| V11 business logic | Idempotency, outbox, fail-closed flags | `APPLIED` | DiagnosticsSliceTest; FeatureFlagAndAuditTest |
| V12 files | StoreObject private + anonymous deny; no product files yet | `PARTIAL` | ProviderPortContractTest; MinIO live test skips if down |
| V13 API | Envelope, OpenAPI source of truth, generated clients | `APPLIED` | G-03-01…G-03-03, Dart + TypeScript generation |
| V14 configuration | Startup validation, audited flags, rotation runbook | `PARTIAL` | ConfigChangeAuditor; docs/runbooks/emergency-credential-rotation.md |

## OWASP API Security Top 10 2023

| Item | Status | Notes |
| --- | --- | --- |
| API1 Broken object level authorization | `PARTIAL` | No patient objects yet; 404-not-403 pattern encoded in ErrorCode |
| API2 Broken authentication | `NOT_APPLICABLE` | Phase 01 |
| API3 Broken object property authorization | `APPLIED` | `additionalProperties: false`; unknown fields 422 |
| API4 Unrestricted resource consumption | `PARTIAL` | Body/depth limits; k6 is Phase 21 |
| API5 Broken function level authorization | `PARTIAL` | Diagnostics gated by flag + env + token |
| API6 Unrestricted access to sensitive business flows | `NOT_APPLICABLE` | No booking/POS yet |
| API7 Server side request forgery | `PARTIAL` | AI probe is an allowlisted URL; FastAPI forbids core DB env |
| API8 Security misconfiguration | `PARTIAL` | Secure headers; no wildcard CORS; Horizon UI not on public gateway |
| API9 Improper inventory management | `PARTIAL` | OpenAPI + event + AI internal registries |
| API10 Unsafe consumption of APIs | `PARTIAL` | Fail-closed provider ports; Python never consumes PHP jobs |

## OWASP MASVS / MASTG (patient mobile)

| MASVS group | Status | Notes |
| --- | --- | --- |
| MASVS-STORAGE | `NOT_TESTED` | Local encryption spike is G-06-01 / Phase 05 |
| MASVS-CRYPTO | `NOT_TESTED` | Same spike |
| MASVS-AUTH | `NOT_APPLICABLE` | Phase 01 |
| MASVS-NETWORK | `PARTIAL` | Clients talk only to Core HTTPS; no direct DB/Redis/S3 |
| MASVS-PLATFORM | `PARTIAL` | Flutter patient app only in Melos workspace |
| MASVS-CODE | `PARTIAL` | very_good_analysis / melos analyze |
| MASVS-RESILIENCE | `NOT_TESTED` | Offline/local outbox is later |

Electron doctor/pharmacy is not MASVS. Packaged-window evidence is G-02-10 and remains OPEN.

## Residual

Independent security/privacy approval of the threat model and this mapping is
G-08-04 and cannot be self-approved here.
