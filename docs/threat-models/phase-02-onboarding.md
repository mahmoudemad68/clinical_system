# Threat model — Phase 02 onboarding, verification, profiles, and locations

Additive to [phase-00-foundation.md](phase-00-foundation.md) and
[phase-01-identity.md](phase-01-identity.md). Route-level, IPC, job, storage,
and scanner catalogs live in
[phase-02-entry-points.md](phase-02-entry-points.md).

This is a register: assets, actors, preconditions, entry points, attack
paths, impact, implemented mitigation, verification/evidence, residual,
owner, engineering status, independent-acceptance state, and an explicit
**Status** of `MITIGATED`, `PARTIAL`, `OPEN`, or `NOT_APPLICABLE`.

**ENGINEERING STATUS:** engineering draft for P02-AUDIT-004 against starting
main `d16fcde5b07844f69547a7ed7be52187a44800d8`. This is **not** independent
human approval.

**INDEPENDENT/HUMAN ACCEPTANCE:** `PENDING_INDEPENDENT_REVIEW`. Assessor and
remediator remain concentrated. Independent workshop/sign-off remains Phase 00
**G-08-04 / EXTERNAL_HUMAN** and Phase 22. **Not a legal, privacy-officer, or
statutory position.**

Do not use `APPROVED` or `ACCEPTED` in this file. Do not convert plans into
mitigations. Do not claim P02-AUDIT-003 closed: profile-correction policy is
still missing (`EXTERNAL_POLICY_INPUT_REQUIRED`, P02-T45). Document-requirement
catalogue v1.0.1 is installed and tested; that is not this threat-model gate
and is not G-08-04.

**SF-001:** `OPEN / UNCHANGED` (`extract-zip@2.0.1`, merge-only,
`promotion_allowed: false`). Not remediated here.

**G-08-04:** `OPEN` / `EXTERNAL_HUMAN`. AI/agent threat-model work is
engineering evidence, not independent human approval.

**P02-AUDIT-005** (profile-claim enablement) is **out of scope**. Claim remains
flag-off; see P02-T46.

---

## Methodology

STRIDE plus privacy analysis across the Phase 02 trust boundaries that are
actually implemented. For each threat: asset/flow, attacker, STRIDE/privacy
category, abuse scenario, existing control, evidence, residual, status,
owner.

Method matches Phase 00/01: what crosses the boundary, what an attacker would
try, what stops them **today**, and what does not. `MITIGATED` requires a
named source file **and** a named test/contract/CI artifact. “Code appears
safe” is not evidence.

ISR-016 repository completeness for this phase is the pair of this file +
the entry-point catalog +
`apps/core-api/tests/Feature/Platform/ThreatModelDocumentationTest.php`.
Independent workshop remains G-08-04.

---

## Independent-acceptance vocabulary

Same register as Phase 00/01:

| Value | Meaning |
| --- | --- |
| `NOT_REQUIRED_FOR_TECHNICAL_CONTROL` | Engineering control with no separate human acceptance gate |
| `PENDING_INDEPENDENT_REVIEW` | Technical model exists; independent workshop/sign-off has not occurred |
| `PENDING_INDEPENDENT_ACCEPTANCE` | Exception or High finding still needs an independent acceptor |
| `EXTERNAL_HUMAN` | Legal, privacy-officer, CODEOWNER-team, or G-08-04 decision |
| `OPERATIONAL_FOLLOW_THROUGH` | Live ceremony, staging, promotion, or provider operations not executed |

---

## Current vs future (do not convert plans into mitigations)

**Implemented now:** patient/doctor/pharmacy onboarding HTTP; verification
cases, uploads, scan, reviewer HMAC download; admin queue/claim/decide;
clinic locations + staff; pharmacy branches + memberships; Electron opaque
file handles; Flutter patient onboarding against generated Dart; source-built
MinIO CI from merged PR #30.

**NOT implemented / NOT live / NOT claimed:**

- `FEATURE_IDENTITY_PROFILE_CLAIM` production/local enablement (P02-AUDIT-005)
- approved **profile-correction policy** (P02-T45)
- dual-approval for high-risk exceptions
- appeal HTTP
- public directory / public PostGIS search
- Inertia admin verification pages (admin is `apps/admin-web`)
- production KMS; successful staging deploy; production promotion
- independent legal/privacy approval (`EXTERNAL_HUMAN`)
- automated government/syndicate/license verification
- clinical records (Phase 04+)

---

## Data-flow diagram — Phase 02

KMS is **FUTURE / Phase 23**, not a live processor. Reviewer download is an
application HMAC, not an S3 GET.

```mermaid
flowchart TB
  subgraph F1["F1 Flutter patient UI"]
    Flutter["Flutter patient onboarding"]
  end

  subgraph F2["F2 Doctor Electron"]
    DocR["Doctor renderer<br/>no bearer, no Node"]
    DocP["Preload typed clinic.doctor"]
    DocM["Doctor main<br/>vault + handles + net.fetch"]
  end

  subgraph F3["F3 Pharmacy Electron"]
    PhR["Pharmacy renderer"]
    PhP["Preload typed clinic.pharmacy"]
    PhM["Pharmacy main"]
  end

  subgraph F4["F4 Admin browser"]
    Admin["admin-web cookie + CSRF<br/>verification queue"]
  end

  subgraph F5["F5 Core HTTP / API"]
    API["/api/v1 patients doctors pharmacies<br/>clinics verification admin"]
    RevDL["GET verification-review-files<br/>HMAC query"]
  end

  subgraph F6["F6 Authorization / profiles"]
    AuthZ["DefaultDenyAuthorizer + capabilities"]
    Patients["Patients services"]
    Doctors["Doctors / Clinics"]
    Pharmacies["Pharmacies"]
    Verif["VerificationService"]
  end

  subgraph F7["F7 Stores"]
    PG["PostgreSQL + PostGIS"]
    Redis["Redis cache / queue / ratelimit"]
    S3["Object store /q/ ingress /c/ canonical"]
  end

  subgraph F8["F8 Worker / scanner"]
    Outbox["outbox_events"]
    Worker["clinic_worker outbox:work"]
    Clam["clamd INSTREAM"]
  end

  subgraph F9["F9 Claim boundary"]
    Claim["LinkVerifiedPatientAccount<br/>FEATURE_IDENTITY_PROFILE_CLAIM off"]
  end

  Flutter --> API
  DocR -->|typed IPC| DocP --> DocM --> API
  PhR -->|typed IPC| PhP --> PhM --> API
  Admin --> API
  Admin -->|issued HMAC URL| RevDL
  API --> AuthZ
  API --> Patients
  API --> Doctors
  API --> Pharmacies
  API --> Verif
  Patients --> PG
  Doctors --> PG
  Pharmacies --> PG
  Verif --> PG
  API --> Redis
  API --> S3
  API --> Outbox
  Outbox --> Worker
  Worker --> Clam
  Worker --> S3
  Worker --> PG
  Patients -.->|flag off| Claim
```

Trust-boundary / data-flow inventory (13):

1. Flutter patient → API
2. Doctor renderer → preload → main → API
3. Pharmacy renderer → preload → main → API
4. Admin browser → API
5. API → PostgreSQL/PostGIS
6. API/worker → Redis/queue
7. API/worker → object storage
8. Worker → malware scanner
9. Applicant → upload pipeline (grant → PUT → complete → outbox → scan)
10. Verifier → case projection + HMAC document access
11. Profile/membership authorization (`DefaultDenyAuthorizer`)
12. Location/PostGIS (own-doctor / own-org only; no public search)
13. Account → profile claim (`LinkVerifiedPatientAccount`, flag off)

---

## Actors (13)

| Actor | Notes |
| --- | --- |
| patient | Authenticated Flutter; own profile only |
| doctor applicant | Draft/pending/changes_requested/rejected; no clinical capability |
| approved doctor | Location/staff after approval |
| pharmacy owner/applicant | Org onboarding + verification |
| pharmacy member/operator | Branch-scoped membership |
| clinic secretary/staff | Location-scoped membership |
| admin verifier | AAL2 + `verification.case.review`; no clinical module |
| malicious authenticated user | Cross-object IDOR/BFLA |
| compromised renderer/client | XSS in Electron renderer or stolen SPA |
| malicious uploaded document | MIME/polyglot/malware/bomb |
| insider/reviewer | Need-to-know browsing, notes, bulk access |
| background worker/service | `clinic_worker`; confused deputy |
| external object-storage/scanner boundary | MinIO/S3 + clamd |

---

## Assets (18)

| Asset | Classification | Store | Trust boundary |
| --- | --- | --- | --- |
| Patient demographic profile | personal | `patient_profiles` + revisions | API ↔ PostgreSQL |
| National ID ciphertext | sensitive | patient/doctor envelopes | API ↔ PostgreSQL |
| National ID lookup HMAC | internal lookup | `national_id_lookup_hmac` | API ↔ PostgreSQL |
| Doctor professional identity | personal/professional | `doctor_profiles` | API ↔ PostgreSQL |
| Pharmacy legal identity | personal/org | org ciphertext + registration HMAC | API ↔ PostgreSQL |
| Verification cases | internal workflow | `verification_cases` | API ↔ PostgreSQL |
| Verification documents | sensitive file metadata | `verification_documents` + object bytes | API/worker ↔ S3 |
| Reviewer decisions/notes | sensitive (notes encrypted) | `verification_decisions.notes_ciphertext` | API ↔ PostgreSQL |
| Specialties | internal reference | `specialties` seed | API ↔ PostgreSQL |
| Clinic locations | location | `clinic_locations` address ciphertext + geography | API ↔ PostGIS |
| Pharmacy organizations/branches | org/location | pharmacy tables + geography | API ↔ PostGIS |
| Memberships | authorization | clinic/pharmacy memberships | API ↔ PostgreSQL |
| Account/profile links | identity | `patient_profiles.user_id` (claim flag off) | API ↔ PostgreSQL |
| Audit records | internal | `audit_events` DEFINER append | API ↔ PostgreSQL |
| Object-store data | sensitive bytes | `/q/` quarantine, `/c/` canonical | worker ↔ S3 |
| Upload handles/grants | credential | intent row + signed PUT; Electron opaque handle | client ↔ API ↔ S3 |
| Location/address data | personal/location | address ciphertext; public_name | API ↔ PostgreSQL |
| Worker DB identity | credential | `clinic_worker` role | worker ↔ PostgreSQL |

---

## Preconditions (attacker)

Stolen bearer; CSRF against admin cookie; stolen reviewer HMAC URL; guessed
UUIDv7; mass-assigned `verification_status`; MIME/extension lie; renderer XSS;
forged IPC sender; stale evidence handle; replayed complete; scanner down;
worker confused deputy; concurrent same-NID onboarding; stale membership after
revoke; insider with AAL2; claim flag mistakenly enabled.

---

## Threat register

### P02-T01 — Duplicate patient/profile creation

| Field | Value |
| --- | --- |
| Asset / flow | Patient/doctor/pharmacy identity; F1/F5/F7 |
| Attacker | Two concurrent users or racing walk-in vs onboarding |
| STRIDE / privacy | Tampering / Information disclosure |
| Abuse scenario | Two authoritative profiles for one National ID or registration HMAC |
| Existing control | Unique `national_id_lookup_hmac` / registration HMAC; row lock; loser → `manual_review_required` without disclosure |
| Evidence | `PatientProfileRaceTest`; `PatientProfileFlowsTest` uniqueness; `DoctorProfileFlowsTest`; `PharmacyOrganizationFlowsTest`; `AdminCreatedDoctorHttpTest` duplicate identity |
| Residual | Claim attach still off (P02-T46) |
| Status | **MITIGATED** |
| Owner | engineering |
| Engineering status | implemented |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T02 — Account/profile takeover

| Field | Value |
| --- | --- |
| Asset / flow | Account → profile link; F13 |
| Attacker | Authenticated user submitting another subject’s National ID |
| STRIDE / privacy | Elevation / Information disclosure |
| Abuse scenario | Steal or switch an owned profile by repeating the identifier |
| Existing control | Collision does not attach or disclose; owned row unchanged; claim service unavailable while flag off |
| Evidence | `PatientProfileFlowsTest` owned-NID collision; `DoctorProfileFlowsTest`; `PharmacyOrganizationFlowsTest`; `IdentityAccessPortsTest` `FeatureUnavailable` |
| Residual | Enablement of claim is P02-AUDIT-005 |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T03 — National ID enumeration

| Field | Value |
| --- | --- |
| Asset / flow | National ID; F1/F5 |
| Attacker | Authenticated or unauthenticated probe |
| STRIDE / privacy | Information disclosure |
| Abuse scenario | Existence oracle via distinct error codes or leaked HMAC/plaintext |
| Existing control | Generic `manual_review_required`; no `patient_id` on collision; no public resolve HTTP; events/logs omit NID |
| Evidence | `PatientProfileFlowsTest` non-disclosure; `RedactionCanaryTest`; chunk-01 canary sinks |
| Residual | Timing side-channel not load-tested here |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T04 — Blind-index abuse

| Field | Value |
| --- | --- |
| Asset / flow | National ID / registration HMAC |
| Attacker | DB reader or caller stuffing HMAC bytes |
| STRIDE / privacy | Information disclosure / Tampering |
| Abuse scenario | Client-supplied HMAC; offline dictionary against lookup column |
| Existing control | Server computes HMAC; mass-assignment of HMAC rejected; purpose-bound keys; dual-read lifecycle is Phase 01 P01-T13 |
| Evidence | `DoctorProfileFlowsTest` / `AdminCreatedDoctorHttpTest` reject HMAC fields; `IdentityKeyLifecycleTest` |
| Residual | HMAC is still a keyed index, not anonymity |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T05 — Mass assignment

| Field | Value |
| --- | --- |
| Asset / flow | verification_status, capabilities, ciphertext, reviewer_id |
| Attacker | Applicant or admin posting extra JSON |
| STRIDE / privacy | Elevation |
| Abuse scenario | Set `verification_status=approved` or inject object keys |
| Existing control | Strict Form Requests / unknown-field 422; server-owned columns not fillable |
| Evidence | `VerificationFlowsTest`; `PharmacyVerificationFlowsTest`; `DoctorProfileFlowsTest`; `AdminCreatedDoctorHttpTest`; `ClinicLocationFlowsTest`; `VerificationUploadFlowsTest` complete extras |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `NOT_REQUIRED_FOR_TECHNICAL_CONTROL` |

### P02-T06 — BOLA / IDOR

| Field | Value |
| --- | --- |
| Asset / flow | Cases, uploads, profiles, locations, memberships |
| Attacker | Malicious authenticated user |
| STRIDE / privacy | Information disclosure / Elevation |
| Abuse scenario | Guess UUID of another actor’s case/upload/location |
| Existing control | Own-status only; invented lookup routes 404; upload owner check |
| Evidence | `VerificationFlowsTest` other-doctor; `VerificationUploadFlowsTest`; `PharmacyVerificationFlowsTest`; `PatientProfileFlowsTest`; `BearerIdentitySessionHttpTest` |
| Residual | UUIDv7 not a secret; 404 vs 403 may still be a coarse oracle |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T07 — BFLA

| Field | Value |
| --- | --- |
| Asset / flow | `verification.case.review`; admin create |
| Attacker | Doctor/pharmacy with extra caps or AAL1 admin |
| STRIDE / privacy | Elevation |
| Abuse scenario | Call admin queue/decide without privileged AAL2 |
| Existing control | `DefaultDenyAuthorizer`; AAL2 required; applicant capabilities cannot review |
| Evidence | `IdentityRulesTest`; `VerificationPolicyV1HttpTest` AAL1 claim 404; `VerificationFlowsTest` unauthorized reviewer |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T08 — Cross-profile access

| Field | Value |
| --- | --- |
| Asset / flow | Patient/doctor own projections |
| Attacker | Other authenticated user |
| STRIDE / privacy | Information disclosure |
| Abuse scenario | `GET /patients/{id}` or other user’s `/me` |
| Existing control | `/me` only; other-id routes 404 |
| Evidence | `PatientProfileFlowsTest`; `DoctorProfileFlowsTest` |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T09 — Cross-clinic access

| Field | Value |
| --- | --- |
| Asset / flow | Clinic locations / memberships |
| Attacker | Doctor B |
| STRIDE / privacy | Information disclosure / Tampering |
| Abuse scenario | Read/patch/invite on doctor A location |
| Existing control | Owner scope; 404 on foreign ids |
| Evidence | `ClinicLocationFlowsTest`; `ClinicStaffInvitationFlowsTest`; desktop `PracticeLocations.test.tsx` |
| Residual | No public clinic search (reduces exposure) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T10 — Cross-pharmacy organization/branch access

| Field | Value |
| --- | --- |
| Asset / flow | Org/branch/membership |
| Attacker | Owner/operator of org B |
| STRIDE / privacy | Information disclosure / Tampering |
| Abuse scenario | Invite/list/revoke on org A; mix org A + branch B FK |
| Existing control | Org-scoped services; composite FK 23503 |
| Evidence | `PharmacyStaffInvitationFlowsTest` BOLA + FK; `PharmacyAdditionalBranchFlowsTest` |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T11 — Membership escalation

| Field | Value |
| --- | --- |
| Asset / flow | clinic/pharmacy memberships |
| Attacker | Invitee or revoked member |
| STRIDE / privacy | Elevation |
| Abuse scenario | Self-invite as owner; accept foreign invitation; keep owner after revoke |
| Existing control | Invite capability on owner; accept own invitation; unique active membership; revoke status |
| Evidence | `PharmacyStaffInvitationFlowsTest`; `ClinicStaffInvitationFlowsTest`; race tests |
| Residual | See P02-T12 for token-lifetime after revoke |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T12 — Stale membership/capability caches

| Field | Value |
| --- | --- |
| Asset / flow | Effective capabilities after revoke; F6/F11 |
| Attacker | Just-revoked operator with a live bearer |
| STRIDE / privacy | Elevation |
| Abuse scenario | Cached capability still authorizes branch write |
| Existing control | Server re-checks membership on command; contextual grant revoke is tested; HTTP deny is authoritative |
| Evidence | `GrantValidityWindowTest`; membership revoke flows. **No** dedicated test that a revoked pharmacy/clinic member’s **same bearer** is denied on a privileged Phase 02 write via a stale cache |
| Residual | Session token reuse after membership revoke is thinner than grant-revoke coverage |
| Status | **PARTIAL** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T13 — Self-review

| Field | Value |
| --- | --- |
| Asset / flow | verification decisions |
| Attacker | Applicant who also holds admin caps |
| STRIDE / privacy | Elevation / Repudiation |
| Abuse scenario | Approve own doctor/pharmacy case |
| Existing control | Applicant id ≠ reviewer; denied even with privileged capabilities |
| Evidence | `VerificationFlowsTest`; `PharmacyVerificationFlowsTest`; `AdminVerificationReviewHttpTest`; `AdminPharmacyVerificationReviewHttpTest` |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T14 — Creator-review (represented applicant)

| Field | Value |
| --- | --- |
| Asset / flow | Admin-created doctor |
| Attacker | Creating admin |
| STRIDE / privacy | Elevation |
| Abuse scenario | Same admin claims/decides the represented case |
| Existing control | Creating admin cannot claim/decide; other admin cannot upload/submit that applicant |
| Evidence | `AdminCreatedDoctorHttpTest`; `VerificationPolicyV1HttpTest`; Playwright `admin-created-doctor.spec.ts` |
| Residual | Dual-approval for high-risk is not implemented (P02-T47) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T15 — Reviewer privilege escalation

| Field | Value |
| --- | --- |
| Asset / flow | `doctors.admin.create` / review |
| Attacker | AAL1 admin or non-admin |
| STRIDE / privacy | Elevation |
| Abuse scenario | Create represented doctor or review without AAL2 |
| Existing control | Privileged AAL2 + named capabilities |
| Evidence | `IdentityRulesTest`; `VerificationPolicyV1HttpTest` |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T16 — Reviewer clinical-data overreach

| Field | Value |
| --- | --- |
| Asset / flow | Admin verification projection |
| Attacker | Admin verifier |
| STRIDE / privacy | Information disclosure / privacy |
| Abuse scenario | Join medical records or global patient search from verification UI/API |
| Existing control | Admin DTOs are verification/profile fields only; Verification cannot query clinical modules; no clinical routes in Phase 02; architecture boundary tests |
| Evidence | `ArchitectureBoundaryTest`; `AdminVerificationReviewHttpTest` field allowlist; Playwright queue; phase non-goals (no medical record) |
| Residual | Later clinical phases must not add joins; independent privacy review is G-08-04 |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T17 — Verification status forgery

| Field | Value |
| --- | --- |
| Asset / flow | `doctor_profiles.verification_status` |
| Attacker | Applicant posting status or forging events |
| STRIDE / privacy | Tampering / Spoofing |
| Abuse scenario | Direct status field or event replay to `approved` |
| Existing control | Transitions only via `VerificationService::recordDecision`; mass-assign 422; pending has no clinical capability |
| Evidence | Mass-assignment tests; capability tests in doctor/pharmacy flows; `ArchitectureBoundaryTest` |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T18 — Verification decision replay

| Field | Value |
| --- | --- |
| Asset / flow | Decide HTTP |
| Attacker | Reviewer or thief replaying idempotent decide |
| STRIDE / privacy | Tampering |
| Abuse scenario | Replay approve after reject or double-decide |
| Existing control | Idempotency keys; case version; assigned reviewer; concurrent claim/decide races converge to one winner |
| Evidence | `VerificationRaceTest`; `PharmacyVerificationRaceTest`; `AdminVerificationClaimRaceTest`; decide idempotency in HTTP tests |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T19 — Stale optimistic versions

| Field | Value |
| --- | --- |
| Asset / flow | case/profile/location/org versions |
| Attacker | Concurrent editors |
| STRIDE / privacy | Tampering |
| Abuse scenario | Overwrite with stale `expected_version` |
| Existing control | `VersionConflict` 409 |
| Evidence | `VerificationFlowsTest`; `PharmacyVerificationFlowsTest`; `PatientProfileFlowsTest`; `ClinicLocationFlowsTest`; `PharmacyAdditionalBranchFlowsTest` |
| Residual | Clients must refresh; mapped in desktop UI tests |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `NOT_REQUIRED_FOR_TECHNICAL_CONTROL` |

### P02-T20 — Document requirement bypass

| Field | Value |
| --- | --- |
| Asset / flow | v1.0.1 catalogues |
| Attacker | Applicant submitting without required AVAILABLE+clean docs |
| STRIDE / privacy | Tampering / Elevation |
| Abuse scenario | Unknown/withdrawn codes; skip `medical_license` |
| Existing control | `VerificationPolicy` / `ApprovedVerificationPolicyV1`; submit blocked until required set is AVAILABLE+clean |
| Evidence | `ApprovedVerificationPolicyV1Test`; `VerificationPolicyV1HttpTest`; TS `policy.correspondence.test.ts`; artifact SHA-256 `a5cdab95e446224b57407fb017a7c5b32a8f494a7cade6b0d0e274ceb1ea281d` |
| Residual | Grandfathered pending-review cases stay decidable without re-applying live required set (intentional) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T21 — Malicious verification uploads

| Field | Value |
| --- | --- |
| Asset / flow | Upload pipeline F9 |
| Attacker | Applicant uploading hostile bytes |
| STRIDE / privacy | Tampering / Denial of service |
| Abuse scenario | Executables, active PDF, oversized objects |
| Existing control | Declared MIME allowlist; size cap; magic inspector; quarantine until scan; Electron client filters |
| Evidence | `VerificationUploadFlowsTest`; `BoundedDocumentInspectorTest`; doctor/pharmacy `evidence-handles.test.ts` |
| Residual | See P02-T23/T25 |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T22 — MIME spoofing

| Field | Value |
| --- | --- |
| Asset / flow | `detected_mime` vs declaration |
| Attacker | `.pdf` that is PNG/ZIP |
| STRIDE / privacy | Spoofing |
| Abuse scenario | Extension/declared type ≠ magic |
| Existing control | Magic-byte inspector; mismatch rejects; zip unsupported |
| Evidence | `BoundedDocumentInspectorTest`; `VerificationUploadFlowsTest` mime_mismatch |
| Residual | None material for allowed types |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `NOT_REQUIRED_FOR_TECHNICAL_CONTROL` |

### P02-T23 — Polyglot / parser attacks

| Field | Value |
| --- | --- |
| Asset / flow | PDF/JPEG/PNG parsers |
| Attacker | Trailing payload / polyglot |
| STRIDE / privacy | Tampering |
| Abuse scenario | Valid magic plus appended executable; parser confusion |
| Existing control | Trailing bytes after PDF EOF / JPEG EOI / PNG IEND → `malformed`; active PDF JS rejected |
| Evidence | `BoundedDocumentInspectorTest` trailing payload and active PDF. **No** named polyglot corpus |
| Residual | Broader polyglot/parser-differential corpus not present |
| Status | **PARTIAL** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T24 — Malware

| Field | Value |
| --- | --- |
| Asset / flow | Worker → clamd |
| Attacker | EICAR/malware in PDF |
| STRIDE / privacy | Tampering |
| Abuse scenario | Infected object promoted to AVAILABLE |
| Existing control | clamd INSTREAM; infected → `malware_detected`; scanner miss → `unavailable` → `TransientProviderFailure`, stays `scanning` (fail-closed) |
| Evidence | `VerificationUploadFlowsTest` EICAR + DisabledScanObject; `ClamdScanObjectTest`; live Secure-file Pest (merged PR #29 CI `36348605976`) |
| Residual | Scanner host empty binds `DisabledScanObject` (fail-closed, not fail-open) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T25 — Compressed / resource bombs

| Field | Value |
| --- | --- |
| Asset / flow | Upload inspector / scanner |
| Attacker | Zip bomb, huge page-count PDF |
| STRIDE / privacy | Denial of service |
| Abuse scenario | Expand-to-exhaust CPU/memory |
| Existing control | ZIP not an allowed MIME (`unsupported_format`); PDF page-count bound; size cap; scan timeout |
| Evidence | `BoundedDocumentInspectorTest` oversized page counts; zip unsupported. **No** zip-bomb corpus (zip never admitted) |
| Residual | PDF resource-bomb beyond page count not separately corpus-tested |
| Status | **PARTIAL** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T26 — Signed URL theft / replay

| Field | Value |
| --- | --- |
| Asset / flow | Reviewer download grant; F10 |
| Attacker | Stolen URL holder |
| STRIDE / privacy | Information disclosure |
| Abuse scenario | Replay after TTL; tamper signature; rebind case/doc; use S3 GET |
| Existing control | Application HMAC (`ReviewerDocumentUrlSigner`); TTL ≤ 300s; no S3 `temporaryUrl` for reviewers; expiry/tamper/rebind 404 |
| Evidence | `VerificationInvariantsTest`; `AdminVerificationDocumentAccessTest`; `AdminVerificationDocumentDownloadTest` |
| Residual | A stolen URL inside TTL still works (by design); no session binding on GET |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T27 — Raw object-key leakage

| Field | Value |
| --- | --- |
| Asset / flow | `/q/` and `/c/` locators |
| Attacker | Renderer, logs, JSON, events |
| STRIDE / privacy | Information disclosure |
| Abuse scenario | Client receives `object_key` or `X-Amz-` |
| Existing control | Opaque upload id; PUT grant not projected to Electron renderer; reviewer JSON omits locators; logs drop signed query |
| Evidence | `VerificationUploadFlowsTest`; `doctor-gateway.test.ts` / `pharmacy-gateway.test.ts`; `AdminVerificationDocumentAccessTest`; `upload-target.test.ts` |
| Residual | Main process still sees the PUT URL (not the renderer) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T28 — Quarantine bypass

| Field | Value |
| --- | --- |
| Asset / flow | Ingress `/q/` vs canonical `/c/` |
| Attacker | Applicant completing before scan; worker skip |
| STRIDE / privacy | Tampering / Elevation |
| Abuse scenario | Mark AVAILABLE without clean scan; reviewer reads ingress overwrite |
| Existing control | PUT only on `/q/`; copyExact to `/c/` after clean; reviewer streams canonical; complete does not promote |
| Evidence | `VerificationUploadFlowsTest`; `AdminVerificationDocumentDownloadTest` canonical vs ingress; `S3StoreObject` grant refuses `/c/` |
| Residual | `platform:prune` does not delete abandoned quarantine objects |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T29 — Scan-result forgery

| Field | Value |
| --- | --- |
| Asset / flow | Trusted evidence issuer |
| Attacker | HTTP actor or forged outbox |
| STRIDE / privacy | Spoofing |
| Abuse scenario | Doctor/admin ActorContext mints AVAILABLE; forged `clean` without clamd |
| Existing control | Production HTTP binds `DisabledTrustedDocumentEvidenceIssuer`; only worker `ProcessingTrustedDocumentEvidenceIssuer`; outbox consumer reloads state |
| Evidence | `VerificationFlowsTest` production binder + ActorContext cannot issue; processor fail-closed |
| Residual | Compromised `clinic_worker` can still process |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T30 — Renderer → filesystem / IPC escalation

| Field | Value |
| --- | --- |
| Asset / flow | F2/F3 Electron |
| Attacker | XSS in renderer |
| STRIDE / privacy | Elevation |
| Abuse scenario | Read arbitrary files; generic IPC; Node require |
| Existing control | Sandbox, contextIsolation, no Node, no generic invoke, CSP `connect-src 'none'` in renderer, typed allowlist |
| Evidence | `trust-boundary.test.ts` (doctor + pharmacy). **No** packaged WebdriverIO XSS-to-file corpus executed in this audit |
| Residual | Packaged XSS-to-IPC remains an independent retest item |
| Status | **PARTIAL** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T31 — Forged Electron IPC sender

| Field | Value |
| --- | --- |
| Asset / flow | `ipcMain.handle` |
| Attacker | Compromised frame / sibling origin |
| STRIDE / privacy | Spoofing |
| Abuse scenario | `event.senderFrame` from untrusted origin invokes upload |
| Existing control | `isTrustedFrameOrigin` allowlist (packaged exact origin; no credentialed/file/javascript) |
| Evidence | `sender-policy.test.ts` unit tests. **No** live `event.senderFrame` integration against a hostile frame |
| Residual | Unit-level origin policy only |
| Status | **PARTIAL** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T32 — Stale opaque file handle

| Field | Value |
| --- | --- |
| Asset / flow | Evidence handle store |
| Attacker | Replay handle after TTL, clear, or path swap |
| STRIDE / privacy | Tampering |
| Abuse scenario | Upload a replaced file; leak path on unknown handle |
| Existing control | TTL; session clear; inode pin; errors omit paths |
| Evidence | doctor/pharmacy `evidence-handles.test.ts` |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `NOT_REQUIRED_FOR_TECHNICAL_CONTROL` |

### P02-T33 — Upload after logout / revocation

| Field | Value |
| --- | --- |
| Asset / flow | Handle store + bearer |
| Attacker | Logged-out renderer still holding a handle |
| STRIDE / privacy | Elevation |
| Abuse scenario | Upload continues after logout |
| Existing control | Gateway/handle clear on logout-equivalent reset; server session revoke; logout fail-closed if revoke fails |
| Evidence | `doctor-gateway.test.ts` reset+clear; `platform-gateway.test.ts` logout. **Not** a full IPC upload after `auth.logout` |
| Residual | Simulated via store clear, not packaged E2E after revoke |
| Status | **PARTIAL** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T34 — Unsafe navigation / webview / external URL

| Field | Value |
| --- | --- |
| Asset / flow | Electron navigation |
| Attacker | `window.open` / remote content |
| STRIDE / privacy | Tampering / Elevation |
| Abuse scenario | Load remote webview; `shell.openExternal` from renderer |
| Existing control | No webview; navigation deny; renderer cannot shell; packaged HTTPS allowlist (Phase 01 P01-T10) |
| Evidence | `trust-boundary.test.ts`; Phase 01 packaged origin tests |
| Residual | Signing/notarization still `OPERATIONAL_FOLLOW_THROUGH` |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T35 — PII leakage to logs / events / metrics / cache keys

| Field | Value |
| --- | --- |
| Asset / flow | NID, phone, address, notes, object keys |
| Attacker | Operator with sink access |
| STRIDE / privacy | Information disclosure / privacy |
| Abuse scenario | Canaries in Monolog, outbox, audit metadata, metrics labels |
| Existing control | Redacting log tap; event payloads IDs-only; metric labels without applicant ids |
| Evidence | `PatientProfileFlowsTest` events; `RedactionCanaryTest`; verification decide tests omit NID; chunk-01 listed sinks |
| Residual | Other collectors (export/Sentry prod) remain G-07-05 / `OPERATIONAL_FOLLOW_THROUGH` |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `OPERATIONAL_FOLLOW_THROUGH` (export) |

### P02-T36 — Reviewer-note leakage

| Field | Value |
| --- | --- |
| Asset / flow | `notes_ciphertext` |
| Attacker | Applicant UI or event consumer |
| STRIDE / privacy | Information disclosure |
| Abuse scenario | Notes in applicant status, outbox, desktop DOM |
| Existing control | Encrypted notes; applicant projection omits; desktop gateway drops notes |
| Evidence | `AdminVerificationReviewHttpTest`; `AdminPharmacyVerificationReviewHttpTest`; `doctor-gateway.test.ts`; `App.test.tsx` |
| Residual | Reviewer UI still shows notes to the assigned reviewer (intended) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T37 — Location / address privacy leakage

| Field | Value |
| --- | --- |
| Asset / flow | Address ciphertext; geography |
| Attacker | Other doctor; logs; public search |
| STRIDE / privacy | Information disclosure / privacy |
| Abuse scenario | Staff personal location collected; plaintext address in events |
| Existing control | Only clinic/branch locations; address ciphertext; events carry ids/version/change_type; no public geo HTTP |
| Evidence | `ClinicLocationFlowsTest`; `PharmacyAdditionalBranchFlowsTest`; event payload tests |
| Residual | `public_name` is not ciphertext (directory-safe by design) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T38 — Unauthorized location modification

| Field | Value |
| --- | --- |
| Asset / flow | Clinic/branch PATCH |
| Attacker | Non-owner member or foreign doctor |
| STRIDE / privacy | Tampering |
| Abuse scenario | Move another clinic’s pin; skip version |
| Existing control | Owner write capability; 404 cross-id; optimistic version |
| Evidence | `ClinicLocationFlowsTest`; `PharmacyAdditionalBranchFlowsTest` |
| Residual | None material |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T39 — PostGIS / query abuse

| Field | Value |
| --- | --- |
| Asset / flow | `geography(Point,4326)` |
| Attacker | Caller injecting radius search |
| STRIDE / privacy | Information disclosure / Denial of service |
| Abuse scenario | Unbounded ST_DWithin across all clinics |
| Existing control | No public/directory geo search HTTP in Phase 02; reads are own-location/own-org |
| Evidence | Route catalog (no search endpoint); own-scope tests. **No** adversarial PostGIS injection corpus |
| Residual | Phase 08 discovery will need a new delta |
| Status | **PARTIAL** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T40 — Concurrency around identity / profile attachment

| Field | Value |
| --- | --- |
| Asset / flow | Onboarding vs unlinked create |
| Attacker | Two writers same HMAC |
| STRIDE / privacy | Tampering |
| Abuse scenario | Duplicate insert under race |
| Existing control | Unique constraint + lock; unlinked vs onboard race test |
| Evidence | `PatientProfileRaceTest` (two-user + unlinked vs onboard) |
| Residual | Claim attach still disabled |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T41 — Replay / idempotency abuse

| Field | Value |
| --- | --- |
| Asset / flow | Idempotent POSTs |
| Attacker | Replay with mutated body |
| STRIDE / privacy | Tampering |
| Abuse scenario | Reuse key to attach a different NID; complete replay promotes twice |
| Existing control | Idempotency keys on onboarding/submit/upload; canonical hasher strips secrets (P01-T3); complete is stateful |
| Evidence | Patient/doctor/pharmacy onboarding idempotency tests; upload complete tests |
| Residual | Unkeyed fingerprint of non-secrets (Phase 01 residual) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T42 — Verification resubmission / history tampering

| Field | Value |
| --- | --- |
| Asset / flow | Decisions / cases |
| Attacker | Applicant or insider DELETE/UPDATE history |
| STRIDE / privacy | Tampering / Repudiation |
| Abuse scenario | Erase old decision; rewrite reason |
| Existing control | Append-oriented cases; new case after `changes_requested`; historical rows remain; DB deny UPDATE/DELETE on audit |
| Evidence | `VerificationFlowsTest` historical decisions kept; audit privilege tests (Phase 01) |
| Residual | Appeal deferred (P02-T48) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T43 — Audit-log omission / tampering

| Field | Value |
| --- | --- |
| Asset / flow | `audit_events` |
| Attacker | Compromised `clinic_app`; failed append |
| STRIDE / privacy | Repudiation |
| Abuse scenario | Decide without audit; UPDATE audit row |
| Existing control | DEFINER append; deny UPDATE/DELETE; decision rolls back if audit fails |
| Evidence | `AdminVerificationRollbackTest`; pharmacy rollback twin; Phase 01 `PostgresPrivilegeTest` |
| Residual | Not a qualified signature |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T44 — Worker / job confused-deputy

| Field | Value |
| --- | --- |
| Asset / flow | F8 `clinic_worker` |
| Attacker | Forged outbox row; worker using `clinic_app` |
| STRIDE / privacy | Elevation / Spoofing |
| Abuse scenario | Worker promotes arbitrary upload; HTTP identity issues AVAILABLE |
| Existing control | `WorkerDatabaseIdentity` refuses `clinic_app`; consumer takes `upload_id` only and reloads; fail-closed scan |
| Evidence | `VerificationWorkerAuditIdentityTest`; `WorkerDatabaseIdentity` tests; processor tests |
| Residual | Worker still has broad table rights (Phase 00 B3 residual) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T45 — Profile-correction policy missing

| Field | Value |
| --- | --- |
| Asset / flow | `PATCH /patients/me/demographics` |
| Attacker | Patient or support process |
| STRIDE / privacy | Tampering / privacy |
| Abuse scenario | Engineering allowlist (`full_name`, `gender`, `date_of_birth`, `height_cm`, `weight_kg`, `marital_status`, `blood_type`) used as if it were an approved correction policy, including National-ID immutability and provenance |
| Existing control | Technical allowlist + revision history + version; National ID is **not** in `EDITABLE` |
| Evidence | `UpdateOwnDemographics`; `PatientProfileFlowsTest` stale version / forbidden fields. **No** product/privacy/security policy artifact for correction/provenance |
| Residual | **EXTERNAL_POLICY_INPUT_REQUIRED:** which demographic fields may be self-corrected vs staff-corrected, required reason/source, whether DOB/name changes need re-verification, retention of revision plaintext vs ciphertext, and dispute/merge workflow. Engineering must not invent that decision. |
| Status | **OPEN** |
| Owner | product / privacy / security (policy); engineering (current allowlist) |
| Independent acceptance | `EXTERNAL_HUMAN` |

### P02-T46 — Profile-claim enablement (out of scope)

| Field | Value |
| --- | --- |
| Asset / flow | F13 `FEATURE_IDENTITY_PROFILE_CLAIM` |
| Attacker | Operator enabling flag; user claiming another profile |
| STRIDE / privacy | Elevation |
| Abuse scenario | Live claim ceremony without approved assurance policy |
| Existing control | Flag default false; forced false in production; `LinkVerifiedPatientAccount` throws `FeatureUnavailable`; even isolated flag-on tests do not attach |
| Evidence | `IdentityAccessPortsTest`; `PatientProfileFlowsTest`; `PlatformFeatures` |
| Residual | Enablement is **P02-AUDIT-005**, not this task |
| Status | **OPEN** |
| Owner | P02-AUDIT-005 |
| Independent acceptance | `EXTERNAL_HUMAN` (enablement) |

### P02-T47 — Dual-approval high-risk exceptions

| Field | Value |
| --- | --- |
| Asset / flow | Verification decide |
| Attacker | Single rogue reviewer |
| STRIDE / privacy | Elevation |
| Abuse scenario | One AAL2 admin approves a high-risk fraudulent case without a second reviewer |
| Existing control | Single assigned reviewer + AAL2 + reason catalogue. **No** dual-approval workflow |
| Evidence | Absence: no dual-approval tests or config. Phase plan mentioned it as optional; not implemented |
| Residual | Not fabricated here |
| Status | **OPEN** |
| Owner | product / security (whether required) |
| Independent acceptance | `EXTERNAL_HUMAN` |

### P02-T48 — Appeal flow abuse

| Field | Value |
| --- | --- |
| Asset / flow | Appeal |
| Attacker | Applicant |
| STRIDE / privacy | Elevation |
| Abuse scenario | Invoke a non-existent appeal API |
| Existing control | `appeal.allowed=false`; no capability; no route |
| Evidence | `VerificationPolicyV1HttpTest` `does not expose an appeal capability or route`; policy JSON |
| Residual | Product appeal remains deferred (not a live attack surface) |
| Status | **NOT_APPLICABLE** |
| Owner | product (future) |
| Independent acceptance | `NOT_REQUIRED_FOR_TECHNICAL_CONTROL` |

### P02-T49 — SF-001 extract-zip toolchain

| Field | Value |
| --- | --- |
| Asset / flow | Electron build toolchain |
| Attacker | Supply-chain / zip symlink traversal in `extract-zip@2.0.1` |
| STRIDE / privacy | Tampering |
| Abuse scenario | Compromised extract during desktop packaging |
| Existing control | Merge-only exception; `promotion_allowed=false`; CI SF-001 binding job |
| Evidence | `infra/security/exceptions/SF-001.json`; Security scans “SF-001 merge exception binding” |
| Residual | Unaccepted High; **not remediated or accepted here** |
| Status | **OPEN** |
| Owner | independent acceptor / G-01-21 |
| Independent acceptance | `PENDING_INDEPENDENT_ACCEPTANCE` |

### P02-T50 — Insider document browsing

| Field | Value |
| --- | --- |
| Asset / flow | Queue + grants |
| Attacker | Insider/reviewer |
| STRIDE / privacy | Information disclosure |
| Abuse scenario | Bulk export; browse unassigned cases’ bytes |
| Existing control | Claim/need-to-know for grants; no bulk export API; unclaimed access 404; no public document URL |
| Evidence | `AdminVerificationDocumentAccessTest`; queue Playwright. **No** volume/anomaly alert proven in this audit |
| Residual | Assigned reviewer still sees documents; alerting residual |
| Status | **PARTIAL** |
| Owner | engineering / security operations |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T51 — External object-storage / scanner boundary

| Field | Value |
| --- | --- |
| Asset / flow | F7/F8 MinIO + clamd |
| Attacker | Anonymous HTTP; scanner fail-open |
| STRIDE / privacy | Information disclosure / Tampering |
| Abuse scenario | List/get bucket; skip scan when clamd down |
| Existing control | `anonymous set none`; `anonymousGet` throws; fail-closed unavailable; CI source-build MinIO (PR #30), not `quay.io/minio` pull |
| Evidence | `S3StoreObjectUploadGrantHeadersTest`; Secure-file providers CI on `273cd85` / main merge; compose `mc anonymous set none` |
| Residual | Staging object-store not provisioned (Phase 00) |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

### P02-T52 — Unlinked walk-in HTTP exposure

| Field | Value |
| --- | --- |
| Asset / flow | `CreateUnlinkedPatientProfile` |
| Attacker | Arbitrary authenticated client |
| STRIDE / privacy | Elevation / Information disclosure |
| Abuse scenario | Call a public exact-match or walk-in HTTP |
| Existing control | **No HTTP**; default-denied capabilities until Phase 03 grants |
| Evidence | `PatientProfileFlowsTest` unlinked create/resolve denied without capability; route catalog |
| Residual | Phase 03 must not expose an enumerating lookup |
| Status | **MITIGATED** |
| Owner | engineering |
| Independent acceptance | `PENDING_INDEPENDENT_REVIEW` |

---

## Status counts (P02-AUDIT-004 register)

| Status | Count | IDs |
| --- | --- | --- |
| MITIGATED | 39 | T01–T11, T13–T22, T24, T26–T29, T32, T34–T38, T40–T44, T51–T52 |
| PARTIAL | 8 | T12, T23, T25, T30, T31, T33, T39, T50 |
| OPEN | 4 | T45, T46, T47, T49 |
| NOT_APPLICABLE | 1 | T48 |
| **Total** | **52** | |

`STATUS_COUNTS MITIGATED=39 PARTIAL=8 OPEN=4 NOT_APPLICABLE=1 TOTAL=52`

---

## Effect on P02-AUDIT-003

This file is the **threat-model component** of the Phase 02 exit-gate cluster
that also names data-retention, document-requirement, reviewer-separation, and
profile-correction policies.

- Document-requirement / reviewer-separation engineering controls are in the
  v1.0.1 policy install and tests (not closed here as P02-AUDIT-003).
- Profile-correction policy remains **OPEN** (P02-T45).
- Therefore **P02-AUDIT-003 stays OPEN**. This work must not be read as closing
  it.

Known documentation mismatch (left in place per scope):
`docs/evidence/phase-02/verification-policy-v1.0.1-phase02.md` historically
mislabels P02-AUDIT-003/P02-AUDIT-008 relative to later QA. Not rewritten
here.

---

## Implemented vs future (Phase 02)

| Topic | State |
| --- | --- |
| Profile-correction policy | OPEN — `EXTERNAL_POLICY_INPUT_REQUIRED` |
| Profile-claim enablement | OPEN — P02-AUDIT-005 (out of scope) |
| Dual approval | OPEN — not implemented |
| Appeal | deferred / NOT_APPLICABLE as HTTP |
| SF-001 | OPEN / UNCHANGED |
| G-08-04 | OPEN / EXTERNAL_HUMAN |
| Staging deploy | NOT executed (Phase 00 fail-closed) |
| Production promotion | NOT executed |
| Production KMS | NOT live |
| Public location search | NOT in Phase 02 |
