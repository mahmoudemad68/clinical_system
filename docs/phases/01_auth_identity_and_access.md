# Phase 01 — Authentication, Identity, and Access

## Objective

Deliver the account, device-session, OTP, MFA, National ID protection, and deny-by-default authorization foundations used by every later phase. The phase must prove that authentication establishes only an actor identity; profile membership and contextual policy checks still decide every business action.

The observable outcome is that a synthetic patient, doctor, pharmacy user, secretary, and admin can authenticate through the correct client flow, manage their own sessions, and receive only server-derived capabilities. No actor can infer another account, claim an existing patient profile without the approved identity-proofing workflow, or use an authenticated session as implicit clinical access.

## Plan traceability

- Section 3, lines 114-147: Sanctum, device/session layer, PostgreSQL, Redis, queues, OpenAPI, and observability stack.
- Sections 4-5, lines 153-268: Flutter patient-mobile secure storage, Electron desktop credential isolation, server-authoritative rules, and secure HttpOnly admin sessions.
- Sections 6-9, lines 271-425: central user identity, optional patient account link, protected National ID, registration, OTP, and existing-patient matching.
- Sections 12-14, lines 492-595: actor types and explicit denial of clinical data to admin and secretary.
- Sections 105-107, lines 3029-3105: API envelopes, contracts, and idempotency.
- Sections 109-110, lines 3117-3225: identity tables and non-sequential public IDs.
- Sections 113 and 116-123, lines 3275-3493: rate limits, authentication, passwords, MFA, audit, redaction, and privacy.
- Sections 156-157, lines 4182-4236: layered and critical authorization tests.
- Sections 165, 167, and 169, lines 4367-4389, 4413-4445, and 4465-4480: environment isolation, CI, and secrets.
- Sections 172-176, lines 4522-4714: ownership, consistency, implementation order, and release definition of done.

## Entry criteria and dependencies

- Phase 00 contracts, correlation IDs, idempotency store, outbox, redaction, secret injection, synthetic data, and CI gates are operational.
- Product, privacy, security, and support owners document the patient-profile claim and account-recovery policy before it is enabled. Legal sign-off is not required to implement, test, or complete the phase.
- An ADR identifies the identity assurance levels required for patient registration, existing-profile claim, privileged enrollment, sensitive recovery, and emergency support.

## Non-goals

- No clinical-record query or clinical write.
- No doctor, pharmacy, clinic, or patient demographic onboarding beyond the minimum identity/link contract; Phase 02 owns profiles and verification.
- No caregiver, guardian, minor, deceased-patient, or emergency break-glass access until a separately documented product/clinical model, security controls, and tests exist. Legal sign-off is not an implementation gate.
- No social login, email OTP, passwordless login, patient mandatory MFA, or complex admin-role designer.
- No client-side authorization decision and no production use of a development OTP bypass.

## Laravel module ownership and services

### `Auth` module

Owns password credentials, login throttling, OTP challenges, MFA factors/recovery, device sessions, token rotation/revocation, admin browser sessions, CSRF integration, and authentication audit events.

Public module services:

```text
RegisterAccount
RequestOtp
VerifyOtp
AuthenticatePassword
CompleteMfaChallenge
RefreshDeviceSession
ListOwnSessions
RevokeOwnSession
RevokeAllSessions
ChangePassword
BeginAccountRecovery
CompleteAccountRecovery
```

### `Identity` module

Owns the central `User`, actor status, National ID canonicalization/protection service, account-to-profile link orchestration, and typed `ActorContext` returned to policies. It does not own doctor/patient/pharmacy profile fields.

Public module services:

```text
ResolveActorContext
NationalIdProtector
PatientIdentityRegistry       # implemented by Patients in Phase 02
LinkVerifiedPatientAccount
DisableIdentity
```

`PatientIdentityRegistry` exposes only `findClaimCandidate(blindIndex)` and `attachAccount(candidateId, userId, proof)`; it never returns clinical history or confirms candidate existence to a client.

### `Access` module

Owns capability names, policy interfaces, organization/profile memberships, and time-bounded contextual grants. It does not infer permission from an `account_type` sent by a client.

```text
Authorize(actor, action, resource, context)
GrantContextualAccess
RevokeContextualAccess
ListEffectiveCapabilities
```

Later modules own their resource policies and register them through `Access`; `Access` must not import their persistence models. The default for an unknown action, missing context, stale membership, or policy failure is deny.

### Dependency direction

```text
Auth controller/Form Request -> AuthService -> Eloquent models/policies
Identity controller/Form Request -> IdentityService -> Eloquent models/policies
Resource module policy -> AccessService <- authenticated ActorContext
External SMS/TOTP/KMS integrations -> focused classes or small provider interfaces
```

Single-purpose services separate SMS delivery, password hashing, token generation, MFA verification, clock, random source, encryption, HMAC, and audit publication. Use a small interface only for a genuinely replaceable external provider, and require replacements to pass the same contract tests.

## Packages and platform capabilities

- Laravel Sanctum for SPA cookie authentication and mobile/desktop device tokens.
- Laravel rate limiter backed by Redis; database constraints remain authoritative.
- Laravel password hashing configured for Argon2id with calibrated parameters.
- A security-reviewed RFC 6238 TOTP implementation behind `TotpVerifier`; package and version require an ADR and lockfile pin.
- Laravel encryption is wrapped by a versioned module service backed by the approved KMS/secret manager; do not scatter encryption calls throughout controllers or models.
- `brick/math` only if constant-width numeric parsing needs arbitrary-precision support; National ID remains a string, never an integer.
- Pest/PHPUnit, Laravel HTTP/database tests, OpenAPI contract tests, k6 abuse scenarios, the Phase 00 SAST/SCA/secret tooling, and WebdriverIO with `@wdio/electron-service` for packaged doctor/pharmacy Electron E2E.
- Flutter patient mobile uses `flutter_secure_storage`; bearer/refresh material is never copied into Drift or analytics.
- Electron doctor/pharmacy desktops keep bearer/refresh material in the main process and wrap persisted secrets with Electron `safeStorage`. The app fails closed when the supported OS secret provider is unavailable; credentials never cross preload into the React renderer or Web Storage.
- React admin in the browser relies on HttpOnly/Secure/SameSite cookies and CSRF, never local-storage tokens.

## Data model and migrations

### `users`

```text
id UUIDv7 PK
name varchar(200)                         # personal; not used as authorization input
phone_e164_encrypted bytea
phone_lookup_hmac bytea
password_hash varchar
account_type enum(patient,doctor,pharmacy,secretary,admin)
status enum(pending_phone,active,suspended,locked,closed)
language enum(ar,en)
credential_version bigint default 1
phone_verified_at timestamptz nullable
last_authenticated_at timestamptz nullable
created_at / updated_at timestamptz
```

Constraints and indexes:

- Unique `(phone_lookup_hmac)` for active/pending accounts, with an explicit migration policy for closed accounts.
- Check that `phone_verified_at` exists when status becomes `active`, except audited admin-created bootstrap identities.
- Index `(status, created_at)` for operational review; never index ciphertext for lookup.

### `user_devices`

```text
id UUIDv7 PK
user_id FK users
platform enum(android,ios,windows,macos,linux,web)
device_label varchar(120)
token_hash bytea nullable
refresh_family_id UUID nullable
credential_version bigint
last_seen_at / expires_at / revoked_at timestamptz nullable
revoked_reason varchar(64) nullable
push_token_ciphertext bytea nullable
created_ip_prefix inet nullable
created_at / updated_at
```

- Store token hashes, never bearer tokens.
- Unique active token hash and index `(user_id, revoked_at, expires_at)`.
- Push tokens are separate scoped secrets and are cleared on revoke/change of ownership.

### `otp_requests`

```text
id UUIDv7 PK
purpose enum(registration,phone_change,recovery,profile_claim)
subject_lookup_hmac bytea
code_hash bytea
attempts smallint
max_attempts smallint
expires_at / consumed_at / invalidated_at timestamptz nullable
requested_ip_prefix inet nullable
device_fingerprint_hmac bytea nullable
provider_message_reference varchar nullable
created_at
```

- OTP values are generated cryptographically, hashed with a purpose-bound server key, short-lived, single-use, and never logged.
- Index `(subject_lookup_hmac, purpose, created_at desc)` and partial index on unconsumed, unexpired challenges.

### `mfa_factors` and `mfa_recovery_codes`

- Factor secrets use versioned envelope encryption; recovery codes are individually hashed and single-use.
- Store factor type, verified/disabled timestamps, last-used counter/time, and actor who disabled it.
- Enforce at most one active TOTP factor in V1 unless an ADR changes the UX.

### `auth_sessions`

Admin sessions may use the framework session table, but retain normalized metadata: session hash, user/device, authentication assurance level, CSRF state, idle expiry, absolute expiry, credential version, revoked timestamp, and last-seen timestamp. Index active sessions by user and expiry.

### `identity_profile_links`

```text
id UUIDv7 PK
user_id FK users
profile_type enum(patient,doctor,clinic_staff,pharmacy_membership)
profile_id UUID
link_status enum(pending,active,revoked,disputed)
assurance_level varchar(32)
proof_reference UUID
linked_at / revoked_at
unique(profile_type, profile_id) where link_status = active
```

The polymorphic identifier is permitted only behind typed registry services. Each profile module also holds a relational reverse link or constraint that prevents duplicate active ownership.

### `contextual_access_grants`

```text
id UUIDv7 PK
actor_user_id UUID
capability varchar(120)
resource_type varchar(80)
resource_id UUID
context_type varchar(80)
context_id UUID
valid_from / valid_until timestamptz nullable
revoked_at timestamptz nullable
reason_code varchar(64)
issued_by_type / issued_by_id
version bigint
created_at
```

- Later consultation code may grant cross-doctor history only through this table and the owning access service.
- Check-in alone must never create that grant. Phase 04 defines the triggering transition and Phase 05 consumes it.
- Index the exact policy lookup tuple plus a partial active-grant index.

All encrypted/HMAC columns carry an implicit or explicit key version. Key rotation uses dual-read/new-write, backfill, verification, then retirement; it never decrypts values into logs or migration output.

## Core invariants

1. Authentication never creates a profile role or clinical permission implicitly.
2. Every active user has a verified phone; pending users cannot authenticate to business endpoints.
3. Admin, doctor, and pharmacy-owner identities require verified TOTP before privileged capability activation.
4. A session is rejected when expired, revoked, its credential version is stale, its user is not active, or required MFA assurance is absent.
5. A password or recovery completion increments `credential_version` and revokes existing sessions according to the approved recovery policy.
6. National ID canonicalization is deterministic and accepts only the approved 14-digit Egyptian format; Unicode confusables, Arabic/Western digit ambiguity, whitespace, punctuation, and invalid dates/check digits are handled by one reviewed function.
7. Plain phone, National ID, OTP, token, MFA secret, or recovery code never appears in a URL, event, cache, analytics row, telemetry, exception, fixture, or support screen.
8. Existing-profile discovery is invisible to untrusted callers. Linking requires server-side candidate lookup, approved proof, unique active link, audit, and notification.
9. Policies resolve actor, membership, resource, and context from authoritative storage and deny on dependency uncertainty.
10. Logout/revoke prevents subsequent HTTP refresh and new WebSocket authorization; active realtime disconnection has a defined bounded propagation target.

## Detailed workflows

### Patient account registration

1. Client validates UX fields locally but sends canonical input only over TLS with an idempotency key.
2. Server normalizes phone and National ID, validates shape without revealing whether either exists, and computes purpose-separated HMAC lookup values.
3. In one transaction, create or reuse the same pending registration intent; do not create a second account for a replay.
4. Commit an `OtpRequested` outbox event containing only request ID, destination handle, locale, and expiry—not the code or raw phone.
5. SMS worker resolves the encrypted destination, sends with a deadline, records safe delivery metadata, and applies capped retry only to transient errors.
6. Client submits OTP plus challenge ID. Server locks the challenge, compares the code in constant time, increments attempts atomically, and consumes it once.
7. On success, mark the phone verified. Activation is deferred until Phase 02 profile creation/linking completes.
8. Return the same generic result whether an existing unlinked patient profile may exist. The client never receives an existence flag.

Failure behavior:

- Rate limit by normalized destination HMAC, IP/network, device, account, and global SMS budget.
- Provider failure leaves a retryable delivery state; it never activates an account.
- Expired/exhausted/replayed challenges return one generic invalid/expired code.
- Same idempotency key with a changed payload returns `409 IDEMPOTENCY_KEY_REUSED`.
- Concurrent verifies lock the row; only one consumes it.

### Existing patient-profile claim

1. After phone verification, Identity passes the National ID blind index to `PatientIdentityRegistry` server-side.
2. No candidate means Phase 02 creates a new patient profile within its own command.
3. A candidate already linked to the same account is an idempotent success.
4. A candidate linked to another account becomes a non-disclosing `MANUAL_REVIEW_REQUIRED`; no takeover or second link occurs.
5. An unlinked candidate proceeds through the approved proof policy. Matching National ID plus a newly verified phone is not automatically sufficient when the historical profile phone differs or is absent.
6. The proof may require matching verified historical contact, reviewed identity evidence, or an operator workflow with separation of duties. Record the exact assurance decision in an ADR or project policy; legal approval is not required.
7. Lock the profile candidate and unique link, attach one user, write audit/proof reference, and enqueue a generic security notification in one transaction.
8. Only after commit may Phase 02 expose the linked profile projection. No clinical payload is part of the claim response.

### Password login and MFA

1. Apply enumeration-safe login response and layered rate limits before expensive Argon2id work, while retaining a timing-balanced unknown-user path.
2. Verify user status and password; create a short-lived pre-authentication challenge when privileged MFA is required.
3. Verify TOTP with bounded skew and replay protection, then issue an admin cookie session or device token scoped to that user/device.
4. Admin cookie uses Secure, HttpOnly, SameSite, CSRF, idle timeout, absolute timeout, and session rotation on privilege change.
5. A Flutter patient client receives the token once, stores it in platform secure storage, and never copies it into Drift, logs, crash reports, or deep links. An Electron desktop receives device credentials in the main process, persists only `safeStorage`-wrapped material, and exposes neither token nor a generic authenticated HTTP primitive to preload/renderer code.

### Session refresh and revocation

1. Lock the token family/session and validate actor status, expiry, credential version, device state, and reuse markers.
2. Rotate refresh material atomically; reuse of an old rotated credential revokes the family and raises an alert.
3. Revocation sets the authoritative timestamp, clears push-token association as required, invalidates caches, and publishes a minimal `AuthSessionRevoked` event.
4. HTTP middleware checks authoritative or safely cached revocation state; Reverb disconnects matching sessions within the approved propagation SLO.

### Recovery and phone change

- Recovery is a separate high-risk state machine with purpose-specific OTPs, rate limits, notifications to old/new channels when available, credential-version increment, session revocation, and a cooling-off/manual review path for risk signals.
- Support cannot set a password, reveal whether a National ID exists, disable MFA, or relink a patient profile without an audited, approved workflow.

## API contracts

All endpoints use `/api/v1`, the Phase 00 envelope, strict schemas, safe error codes, and request/correlation IDs.

```text
POST   /auth/registrations
POST   /auth/otp-requests
POST   /auth/otp-verifications
POST   /auth/login
POST   /auth/mfa/challenges/{id}/verify
POST   /auth/token/refresh
POST   /auth/logout
GET    /auth/sessions
DELETE /auth/sessions/{session_id}
POST   /auth/sessions/revoke-all
POST   /auth/password/change
POST   /auth/recovery/start
POST   /auth/recovery/complete
GET    /me
GET    /me/capabilities
```

- Registration, OTP request, verify, refresh, recovery completion, and revoke-all accept idempotency keys where replay could duplicate effects.
- `/me` returns identity/profile handles and safe status only; it never returns password metadata, National ID, raw phone, MFA secrets, or clinical content.
- Resource-not-found and resource-denied responses may both use `404` when existence disclosure creates risk.
- OpenAPI declares cookie/CSRF and bearer schemes separately; endpoints cannot silently accept the wrong scheme.

## Events and jobs

Minimal event schemas:

```text
IdentityAccountRegistered.v1 {user_id, status, locale}
IdentityPhoneVerified.v1 {user_id, verified_at}
IdentityProfileLinked.v1 {user_id, profile_type, profile_id, assurance_level}
IdentityStatusChanged.v1 {user_id, old_status, new_status, reason_code}
AuthOtpDeliveryRequested.v1 {otp_request_id, destination_handle, locale}
AuthSessionRevoked.v1 {user_id, session_id, reason_code, revoked_at}
AuthCredentialVersionChanged.v1 {user_id, credential_version, reason_code}
```

Jobs:

- OTP delivery with deadline, capped backoff, duplicate-effect protection, and provider cost controls.
- Expired OTP/session cleanup that preserves required audit facts but removes secrets according to retention policy.
- Session revocation fan-out to realtime/push adapters.
- Encryption/HMAC rotation backfill with resumable cursors, dual-read validation, metrics, and no plaintext output.
- Suspicious authentication aggregation/alerting using pseudonymous identifiers.

## Client work

### Flutter patient-mobile authentication package

- One interceptor adds the bearer token in memory, handles one coordinated refresh, and never retries a non-idempotent request without its original idempotency key.
- Secure storage adapter is injectable and has platform-specific failure handling.
- Registration/login/MFA/recovery screens use generic account-existence messages and show rate-limit retry time safely.
- Session-management UI displays platform, label, approximate activity time, and revoke action without exposing IP precision.
- On revoke/credential failure, clear sensitive memory, local authenticated caches, and realtime connections before returning to login.

### Electron doctor/pharmacy desktop authentication

- The main process owns device credentials, coordinated refresh, authenticated HTTP/realtime transports, expiry timers, and secure cleanup. React renderers receive typed results and safe errors only.
- A sandboxed preload exposes versioned, narrow operations through `contextBridge`; it does not expose `ipcRenderer`, arbitrary channel names, arbitrary URLs/headers, Node.js, filesystem, shell, or secure-storage APIs.
- Main and preload validate operation identity, sender/window, payload, response, timeout, and cancellation against generated TypeScript/Zod contracts before work or return.
- BrowserWindow uses context isolation, renderer sandboxing, no Node integration, restrictive CSP, denied navigation/new-window creation, and allowlisted external-link handling.
- `safeStorage` availability and the approved OS credential backend are verified after app readiness. Unsupported/fallback plaintext behavior blocks sign-in and local sensitive state rather than silently weakening protection.
- Session-management UI shows safe device metadata and revoke actions. Revocation clears main-process credentials, desktop caches, realtime subscriptions, and renderer state before returning to login.

### React browser admin

- Cookie session only; CSRF token handled by the shared transport.
- Route guards improve UX but never replace API authorization.
- No token, National ID, phone, or MFA seed in local/session storage, URL, analytics, or error reporting.
- MFA enrollment shows a secret only during verified setup; backup codes are shown once and cannot be re-read.

## Security and privacy threats and controls

- **Credential stuffing/brute force:** layered adaptive rate limits, breached-password policy if an approved privacy-preserving service is available, MFA for privileged actors, anomaly alerts, and no permanent IP-only blocking.
- **Enumeration:** identical status/message shape for registration, login, OTP, recovery, and profile claim; response timing and rate-limit metadata reviewed.
- **SIM swap/profile takeover:** identity-assurance workflow, historical-contact mismatch review, link uniqueness, security notification, dispute/revoke path, and no immediate clinical disclosure.
- **Token theft/replay:** short lifetime, hashed storage, rotation/family reuse detection, credential versions, per-device revocation, TLS, secure storage, Electron main-process credential isolation, and CSRF protection for cookies.
- **Electron renderer/IPC compromise:** packaged local renderer only, context isolation and sandbox, no Node integration, narrow typed preload bridge, sender validation, navigation/window/permission denial, and no token-bearing response or generic network/filesystem/shell primitive.
- **Insider privilege:** least-privilege capabilities, MFA/step-up, separate support/admin operations, immutable audit, and periodic access review.
- **National ID disclosure:** purpose-separated HMAC, versioned envelope encryption, KMS access logs, masked displays, no client lookup API, and canary redaction tests.
- **Long-lived Octane leakage:** request-scoped actor/context only, no mutable identity singletons, explicit worker reset hooks, and alternating-user regression tests.
- **Denial/cost abuse:** bounded payloads, Argon2 workload capacity testing, OTP budgets, queue caps, provider circuit breakers, and alerting.

## Test plan

### Unit tests

- Phone and National ID canonicalization/property tests, including Arabic-Indic digits, Unicode confusables, separators, invalid length/date/check data, and round-trip-safe display masking.
- User, OTP, MFA, session, recovery, profile-link, and contextual-grant state transitions with controlled clock/randomness.
- Password policy, assurance-level calculation, capability decisions, default-deny behavior, safe error mapping, and redaction.
- Refresh-family reuse and credential-version invalidation.

### Integration tests

- Real PostgreSQL uniqueness/race tests for phone, OTP consumption, profile linking, token rotation, and active contextual grants.
- Redis rate limiter behavior after restart and under concurrency; database truth still prevents replay.
- Sanctum cookie/CSRF and bearer flows; Argon2id configuration; KMS/encryption adapter rotation fixture.
- SMS adapter timeout, transient retry, permanent failure, duplicate delivery callback, and global cost budget.
- Reverb disconnect after session revoke.

### Contract tests

- OpenAPI security schemes, envelopes, validation/error codes, idempotency semantics, and generated Dart patient-mobile plus TypeScript Electron/admin clients.
- Electron main/preload ports validate current and previous compatible payloads, reject unknown operations/fields/senders, propagate cancellation/deadlines, and never serialize credentials.
- Every password, SMS, TOTP, encryption, HMAC, clock, and patient-registry integration passes its focused service or provider-interface contract.
- Event schemas contain no raw destination, National ID, OTP, token, or MFA material and remain backward compatible.

### End-to-end tests

- Patient registration through OTP, then Phase 02 new-profile creation or approved existing-profile claim.
- Privileged login requires TOTP; rejected/replayed code never creates a session.
- Admin browser CSRF flow; Flutter patient token refresh; Electron main-process token refresh; one-device and all-device revoke across HTTP and realtime.
- Recovery changes credential version and invalidates old HTTP and realtime access.
- Cross-role matrix proves admin/secretary/pharmacy/patient/doctor authentication grants no unintended domain capability.
- WebdriverIO with `@wdio/electron-service` drives packaged doctor/pharmacy Electron builds on every supported OS to prove login, restart/reopen, secure-provider failure, revoke cleanup, and absence of credentials from renderer storage, logs, crash artifacts, and IPC traces. Playwright's experimental Electron launcher remains optional only after the pinned Phase 00/ADR 0010 compatibility spike.

### System tests

- OTP provider outage/backlog and recovery without account activation or duplicate cost.
- Redis loss, worker restart, clock skew, rolling credential-key rotation, and mixed old/new application versions.
- Sustained login/OTP/refresh load meets the agreed p95 and error targets without starving core APIs.
- Lost-device exercise verifies remote revoke, local clear behavior, push-token removal, and support runbook.

### Security tests

- Credential stuffing, password spraying, enumeration timing/content, OTP guessing/replay/flooding, CSRF, fixation, token reuse, mass assignment, BOLA/BFLA, and session-revocation bypass.
- Electron tests attempt renderer XSS-to-IPC escalation, arbitrary operation/channel/URL/header injection, sender spoofing, navigation, unsafe external links, Node/global access, plaintext/fallback secret storage, and post-revoke reuse; all fail closed.
- Fuzz identifiers, headers, cookies, cursors, Unicode phone/National ID inputs, and oversized JSON with bounded resource use.
- Search logs, traces, Sentry, Horizon, events, cache, database debug output, and client crash reports for seeded secret/identity canaries.
- Alternating identities through the same Octane worker proves no actor/capability/response leakage.

## Observability and runbooks

Metrics use bounded labels only:

```text
auth_attempts_total{result,method,actor_class}
auth_latency_seconds{method}
otp_requests_total{purpose,result}
otp_delivery_age_seconds
mfa_challenges_total{result}
active_sessions{client_class}
session_revocation_latency_seconds
profile_claims_total{result,assurance_level}
authorization_decisions_total{action_group,result,reason_code}
```

- Alert on brute-force patterns, OTP budget/queue spikes, privileged MFA bypass attempts, refresh reuse, abnormal profile claims, revocation SLO breach, encryption failures, and redaction canaries.
- Logs carry user/session IDs only when required and authorized; IP is minimized/prefix-truncated according to policy.
- Runbooks cover SMS outage, suspected account takeover, lost device, MFA recovery, mass revocation, key rotation failure, phone reassignment, and disputed profile link.

## Migration and rollout

1. Apply identity schemas with no production users and validate constraints using synthetic fixtures.
2. Enable login/OTP only in development, then staging; use separate provider credentials and approved test destinations.
3. Create the first admin through a one-time audited bootstrap command, require immediate password change and TOTP, then disable the bootstrap path.
4. Shadow-log authorization decisions in staging, but never allow on shadow disagreement; deny remains authoritative.
5. Roll out patient registration behind a server flag after rate, cost, recovery, and profile-claim reviews pass.
6. Roll key versions through dual-read/new-write and rehearse rollback before any real identity import.
7. Forward recovery disables new registration/claim while keeping safe login/revoke available; never drop encrypted identity columns during rollback.

## Measurable exit gate

- 100% of exposed Phase 01 endpoints have explicit authentication, object/action policy, rate-limit, schema, audit, and negative tests.
- All privileged synthetic users require verified TOTP; no privileged session is issued at insufficient assurance.
- Concurrent OTP verification, phone registration, token rotation, and profile linking produce exactly one valid result in repeated database tests.
- Existing-profile claim never reveals candidate existence and cannot attach to two active accounts.
- Session revocation blocks HTTP refresh/new requests and realtime authorization within the approved measured SLO.
- National ID, phone, OTP, password, token, MFA, and clinical canaries are absent from all captured telemetry/artifacts.
- Octane alternating-user leakage, CSRF, enumeration, replay, credential-stuffing, and BOLA/BFLA suites pass.
- Identity threat-model delta, recovery/link policy, key-management ADR, and privacy retention entries have accountable approvals.
- No Critical or unaccepted exploitable High security finding remains; all lower findings have owners and due dates.

## Deliverables

- `Auth`, `Identity`, and `Access` modules with controllers, Form Requests, models, policies, and public services.
- Identity/session/OTP/MFA/link/grant migrations and synthetic fixtures.
- OpenAPI/event schemas and generated client updates.
- Flutter patient-mobile authentication, Electron doctor/pharmacy desktop authentication, and React admin browser-session flows.
- Authorization matrix, identity assurance ADR, key-rotation plan, abuse tests, dashboards, alerts, and runbooks.
