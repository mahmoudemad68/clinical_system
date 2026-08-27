# Independent security, privacy, identity, and architecture review

**Review date:** 2026-08-26  
**Technical recommendation:** **DO NOT APPROVE**  
**G-08-04:** **FAIL**  
**G-01-21:** **FAIL**  
**Legal/compliance approval:** **NOT PROVIDED**

## 1. Executive summary

Phase 00 and Phase 01 must not be closed on the reviewed state. The existing
ledgers correctly admit that the repository has not received independent
assurance, but several individual `PASS` claims are also contradicted by the
implementation. This review identified **0 Critical, 8 High, 8 Medium, and 1
Low** findings. The absence of a Critical finding is not approval: multiple
exploitable High findings independently fail Phase 01 gate G-01-21.

The most material failures are:

- the database reporting and worker privilege model is not least-privileged;
- refresh rotation detaches access tokens from normalized sessions, so normal
  logout can report success without revoking the device or refresh token, and
  reuse detection remembers only one prior generation;
- admin cookies are not bound to their normalized session rows, and the API MFA
  completion route is exempted from CSRF before it creates an admin cookie;
- unkeyed idempotency request hashes retain an offline password-verification
  oracle for registration and recovery requests;
- Telescope and several supported log/export channels bypass the claimed
  sensitive-data redaction boundary;
- authentication throttles use the general cache rather than the named rate
  store, have no trusted-proxy design, and omit refresh abuse controls;
- the audit hash chain is raceable, omits material fields, has no verifier, and
  does not make the database an append-only trusted boundary;
- CI has never run, uses mutable third-party action references in privileged
  jobs, ignores unfixed vulnerabilities, and does not exercise the adversarial
  cases required by the phase exit criteria.

The Phase 00 threat model says it covers seven named boundaries even though the
phase specification names eight; it omits the Electron renderer-to-main/OS
boundary entirely. The 25-line Phase 01 delta does not model the implemented
recovery, bootstrap, token, idempotency, telemetry, queue, realtime, Flutter,
or Electron attack paths. The data inventory claims one row per field/event/
log/metric/cache/file but collapses most Phase 01 credential tables into one
sentence, omits access roles and retention, and has no lawful-basis or deletion
schedule.

### Technical security/privacy assessment

This is an independent technical assessment of repository code, configuration,
contracts, tests, generated artifacts, and evidence. It does **not** accept an
engineering ledger as proof merely because a command or test previously passed.
The technical decision is **DO NOT APPROVE** Phase 00 G-08-04 or Phase 01
G-01-21.

### Legal/compliance approval

This report is **not legal advice and does not grant legal, regulatory,
clinical, pharmacy, privacy-officer, or statutory compliance approval**.
Egyptian privacy, healthcare, pharmacy, electronic-record, retention,
cross-border-processing, employment-monitoring, and breach-notification
decisions require written approval from appropriately qualified humans. The
repository currently names one person for every accountable role and records
no qualifications, lawful bases, approved retention schedule, data-subject
rights procedure, or regulator-specific opinion.

## 2. Reviewed commit SHA

The final source review was performed against:

```text
574f16db10484817b8892562c0c6e6ec08a8b00a
Implement Phase 01 authentication, identity, and access.
```

The working tree was clean for tracked product files before this report was
created. Untracked agent/tooling files under `apps/core-api/.agents/`,
`apps/core-api/.claude/`, `apps/core-api/.mcp.json`, `apps/core-api/AGENTS.md`,
`apps/core-api/CLAUDE.md`, and `apps/core-api/boost.json` were not treated as
reviewed product implementation. Gitignored Electron `out/` artifacts were
used only as corroborating evidence: they predate the commit timestamp and are
not provenance-bound to the SHA, so they cannot close a packaged-artifact gate.

## 3. Scope

The review covered:

- Phase 00 gates G-08-01 through G-08-04 and related architecture, database,
  telemetry, CI, encryption, and Electron gates;
- all Phase 01 gates, with primary emphasis on G-01-21;
- the Phase 00 threat model, Phase 01 threat delta, OWASP mapping, data
  inventory, classification policy, accountable-owner record, ADRs, runbooks,
  evidence ledgers, and gate-count script;
- Laravel Auth, Identity, Access, Audit, and Platform modules; routes,
  middleware, migrations, providers, PostgreSQL grants, Redis/cache wiring,
  Reverb, Horizon/outbox consumers, Telescope, logging, and configuration;
- OpenAPI and event contracts, authentication tests, authorization tests,
  redaction tests, CI workflows, CODEOWNERS, and the k6 abuse harness;
- Flutter authentication/secure-storage code and Android backup configuration;
- doctor and pharmacy Electron main/preload/renderer boundaries, credential
  vaults, HTTP transport, package configuration, source tests, and available
  ASAR artifacts;
- secrets/credential patterns, feature flags, key management, retention,
  deletion, auditability, and current clinical-data scope.

No Clinical module, clinical persistence table, or clinical API route exists in
the reviewed commit. Therefore this review can assess the identity foundation's
readiness to protect future clinical data, but it cannot approve clinical-data
handling that has not yet been implemented.

## 4. Methodology

The assessment used adversarial source and data-flow review rather than
confirmation of ledger claims:

1. Reconstructed trust boundaries and data flows from the phase specifications,
   then compared them with runtime wiring.
2. Traced authentication from route to middleware, controller, transaction,
   persistence, token issuance, refresh, actor resolution, authorization,
   revocation, events, audit, and clients.
3. Reviewed migrations and PostgreSQL default privileges as an attacker holding
   app, worker, reporting, or migration credentials.
4. Reviewed sensitive values through request hashing, errors, Telescope, logs,
   traces, Sentry assumptions, metrics, outbox events, jobs, Redis, client
   storage, and generated artifacts.
5. Examined test oracles for false positives, missing concurrency, missing
   negative assertions, and differences between source tests and packaged
   behavior.
6. Ran safe local checks only. No external target, provider, production system,
   or active DAST/pentest was touched because no approved staging target or
   rules of engagement were supplied.

### Reproduced verification

| Check | Result | Security interpretation |
| --- | --- | --- |
| `npm run desktop:test` | 37 doctor + 37 pharmacy tests passed | Baseline source assertions pass, but they miss the packaged `file://`/custom-origin contradiction. |
| ASAR inspection | Both available packages call `loadURL(file://...)`; neither contains the claimed packaged custom origin | Corroborates G-02-09/G-01-15 failure; artifacts are not SHA-bound. |
| Phase 00 gate counter | 31 PASS / 15 PARTIAL / 0 BLOCKED / 1 OPEN | Proves only that the Markdown counts itself consistently. |
| Local PostgreSQL privilege query | `clinic_reporter` can select current `users` and `idempotency_keys`; `clinic_worker` has DML | Confirms the Phase 00 reporter gap on actual local grants. |
| `clinic_test` privilege query | worker DML present; reporter grants absent | Shows the test database does not reproduce production/default reporting grants. |
| Phase 01 Pest file | 13 setup errors: host PHP lacks `pdo_pgsql` | No independent feature-pass claim is made. The earlier ledger result was not accepted as this review's evidence. |
| Focused Flutter tests | not run: Flutter executable unavailable | OS secure-storage behavior remains unverified. |
| Redaction-safe credential-pattern scan | only the intentional RSA redaction canary matched | No obvious committed live key was found; this is not a substitute for an executed history scan. |

## 5. Findings

### ISR-001 — Database roles violate workload isolation and least privilege

- **Severity:** High
- **Affected files/classes/routes:**
  `infra/docker/postgres/initdb/01-roles-and-extensions.sql`;
  `apps/core-api/config/database.php`;
  `apps/core-api/database/migrations/2026_08_26_200000_create_identity_and_access_tables.php`;
  `.github/workflows/pull-request.yaml`; all Auth/Identity/Access/Audit tables.
- **Attack or failure scenario:** A reporting credential can read password
  hashes, encrypted phones/National IDs, lookup HMACs, idempotency fingerprints,
  OTP/MFA/session material, and future personal tables because default
  privileges grant `SELECT` on every table created by `clinic_migrator`. A
  compromised queue worker can insert/update/delete every table, including
  identities, devices, grants, and outbox rows. The running app has no separate
  worker/reporting connection configuration, while CI migrates and tests as the
  migration role rather than proving serving-role denials.
- **Why existing protection is insufficient:** Removing DDL is not least
  privilege. The Phase 00 threat model explicitly said reporter access must be
  narrowed before Phase 01 stores identity data; Phase 01 then added the data
  without narrowing it. `REVOKE UPDATE, DELETE` on `audit_events` does not fix
  broad reads, broad worker DML, or app/worker inserts into the audit table.
- **Required remediation:** Revoke broad default table privileges. Grant exact
  per-table/per-operation rights to distinct app, queue, reporting, migration,
  backup, and audit-writer roles. Expose reporting through approved,
  de-identified views only. Wire each workload to its own connection and
  credential. Make CI create the real roles and test positive and negative
  privileges with each role.
- **Closure impact:** Blocks Phase 00 G-04-01, G-08-02, and G-08-04; blocks
  Phase 01 G-01-02, G-01-19, and G-01-21.
- **Evidence needed to close:** Reviewed grant matrix; migration that removes
  defaults; `information_schema`/`has_table_privilege` output for every role;
  tests showing reporter cannot read raw identity/credential tables and worker
  cannot mutate identity/grant/audit state outside its narrow duties.

### ISR-002 — Refresh rotation breaks session linkage, logout, reuse detection, and absolute lifetime

- **Severity:** High
- **Affected files/classes/routes:**
  `RefreshDeviceSessionHandler`, `IssueAuthenticatedSession`,
  `PostgresAuthStore`, `ResolveActorContext`, `SessionCommandHandler`,
  `AuthController::refresh`, `AuthController::logout`;
  `POST /api/v1/auth/token/refresh`, `POST /api/v1/auth/logout`.
- **Attack or failure scenario:** After the first refresh, `user_devices.token_hash`
  changes but `auth_sessions.session_hash` does not. The new access token still
  authenticates through the device row, while actor resolution silently falls
  back to `sessionId=null`. Logout then skips revocation and returns
  `{"revoked":true}` while the refresh token remains usable. Separately, only
  `previous_refresh_token_hash` is retained; reuse of generation N-2 or older
  finds no row and therefore does not revoke the family. Every refresh also
  extends `refresh_expires_at` another 30 days without enforcing the normalized
  session's original absolute expiry.
- **Why existing protection is insufficient:** The existing test reuses only
  the immediately prior refresh token. It does not test logout after refresh,
  old-generation reuse, absolute lifetime, a revoked normalized session, or
  concurrent rotations. Falling back to AAL1 with no session converts an
  integrity failure into successful authentication.
- **Required remediation:** Keep one authoritative session/family model. Rotate
  access hashes and normalized session linkage atomically; reject access when
  its required session row is absent/revoked/expired. Preserve a family-wide
  consumed-token ledger or equivalent reuse marker for the family lifetime.
  Enforce a non-sliding absolute lifetime. Logout must revoke by authenticated
  device/family even if metadata lookup fails and must never claim success
  without authoritative revocation.
- **Closure impact:** Blocks Phase 00 G-08-04; blocks Phase 01 G-01-09,
  G-01-12, G-01-16, G-01-21, and the measurable session-revocation exit gate.
- **Evidence needed to close:** PostgreSQL concurrency tests for two refreshes;
  tests for N-2/N-10 reuse, lost responses, logout after one/many refreshes,
  remote revoke, credential-version change, fixed absolute expiry, and proof
  that no issued token authenticates without a live normalized session.

### ISR-003 — Admin browser session and CSRF boundaries are not bound to the actual browser session

- **Severity:** High
- **Affected files/classes/routes:** `IssueAuthenticatedSession`,
  `PostgresAuthStore::latestCookieSession`,
  `ResolveActorContext::fromCookieUser`, `ValidateCookieCsrf`,
  `AuthController::establishAdminCookie`, `AdminSessionController`,
  `ExceptionRenderer`, `AuthenticationFlowsTest`;
  `POST /api/v1/auth/login`, `POST /api/v1/auth/mfa/challenges/{id}/verify`,
  `POST /api/v1/auth/logout`, web `/login`, `/mfa`, `/logout`.
- **Attack or failure scenario:** The normalized admin session hashes
  `cookie:<random auth-session UUID>`, not the regenerated Laravel session ID.
  Cookie authentication selects the user's *latest* active normalized row, not
  the row for the presented cookie. With two browsers, revoking A can leave A
  accepted as B, and web logout leaves the normalized row active. The API MFA
  completion request has no `client_class` and no authenticated web user, so
  `ValidateCookieCsrf` exempts it immediately before it establishes an admin
  cookie. This permits login CSRF/session swapping using an attacker's valid
  admin MFA challenge. Any account can also request `client_class=admin_web`;
  server-side account-to-client compatibility is absent.
- **Why existing protection is insufficient:** The CSRF test uses an unknown
  phone and asserts only 401. `TokenMismatchException` and bad credentials both
  map to the same 401, so the test passes without proving which control fired.
  `HttpOnly`/`SameSite` does not bind the cookie to the normalized row and does
  not protect an explicitly CSRF-exempt login-completion route.
- **Required remediation:** After Laravel rotates the session, store a keyed
  hash of the actual session ID and resolve exactly that row. Revoke both the
  framework session and normalized row atomically. Apply CSRF by route/session
  semantics, including pre-auth MFA completion, not a client-supplied field.
  Enforce server-owned account/client/platform compatibility and step-up rules.
- **Closure impact:** Blocks Phase 00 G-08-02 and G-08-04; blocks Phase 01
  G-01-08, G-01-13, G-01-21, and the admin CSRF exit criterion.
- **Evidence needed to close:** Browser tests with a real privileged user for
  missing/wrong/correct CSRF; login-CSRF negative test; fixation test; two
  simultaneous cookies with revoke-A/retain-B behavior; logout proof; and
  negative patient/doctor/pharmacy-to-admin-client tests.

### ISR-004 — Idempotency fingerprints create a fast offline credential oracle

- **Severity:** High
- **Affected files/classes/routes:** `EnforceIdempotency`,
  `CanonicalRequestHasher`, `EloquentIdempotencyStore`,
  `2026_08_24_100001_create_idempotency_keys_table.php`, data inventory;
  registration, OTP verification, refresh, recovery completion, and revoke-all
  routes using `platform.idempotency`.
- **Attack or failure scenario:** `request_hash` is an unkeyed SHA-256 over the
  canonical full request body. Registration includes name, phone, National ID,
  and password; recovery completion includes OTP and new password. A database
  reader who knows or obtains the other fields can hash password guesses at
  SHA-256 speed and compare them with the stored fingerprint, bypassing the
  intended cost of Argon2id. Public requests are all scoped as `anonymous`, so
  a known/guessed client idempotency key also shares a global pre-auth namespace.
  For refresh, a successfully rotated response is replayed without replacement
  tokens, so the client cannot safely recover from a lost response.
- **Why existing protection is insufficient:** Removing tokens from
  `response_reference` does not remove secrets from the request fingerprint.
  Classifying `request_hash` as `internal` is incorrect when it verifies guesses
  of credential material. The tests check only that plaintext tokens are not
  in `response_reference`.
- **Required remediation:** Never persist a deterministic fast hash over a
  password, OTP, refresh token, National ID, or equivalent secret. Define a
  per-operation, secret-free intent projection and protect it with a
  server-secret MAC where appropriate. Bind pre-auth keys to a server-derived
  flow handle. Design refresh replay so a lost successful response can be
  recovered without replaying the old token or persisting bearer material in a
  broadly readable table.
- **Closure impact:** Blocks Phase 00 G-04-04, G-05-02, G-08-02, and G-08-04;
  blocks Phase 01 G-01-06, G-01-09, G-01-17, and G-01-21.
- **Evidence needed to close:** A data-flow review of every idempotent route;
  tests proving stored rows cannot distinguish password/OTP/token guesses;
  reporter denial; pre-auth cross-user collision tests; and a lost-response
  refresh recovery test.

### ISR-005 — Telescope and supported logging/export paths bypass redaction

- **Severity:** High
- **Affected files/classes/routes:** `TelescopeServiceProvider`,
  `config/telescope.php`, `config/logging.php`, `RedactingLogTap`,
  `RedactingProcessor`, Composer Sentry integration, data inventory; all
  `/api/v1/auth/*` request and response paths.
- **Attack or failure scenario:** Local Telescope's RequestWatcher is enabled
  and can persist phones, National IDs, OTP/TOTP codes, refresh tokens,
  `current_password`, `new_password`, and token-bearing responses. Only
  `_token`, `password`, and `password_confirmation` plus selected headers are
  hidden; no response parameters are hidden. Separately, the redacting log tap
  is attached only to `single` and `stderr`; `daily`, `monthly`, Slack,
  Papertrail, syslog, errorlog, and emergency channels are supported but
  unredacted. The inventory claims Sentry message scrubbing, but there is no
  repository `before_send`/`before_breadcrumb` or equivalent evidence.
- **Why existing protection is insufficient:** A false UI gate does not remove
  credential content from Telescope tables, and “local only” is not a license
  to store reusable credentials or real identity data. Unit tests exercise the
  redactor itself and an in-memory export gateway, not every configured sink or
  Telescope persistence.
- **Required remediation:** Exclude auth routes/bodies/responses from Telescope
  or comprehensively redact them before persistence. Make redaction mandatory
  at the logging manager/export boundary for every emitting channel; refuse
  startup on an unprotected channel. Add explicit Sentry event/breadcrumb
  sanitizers or remove the integration. Define and execute local diagnostic
  retention.
- **Closure impact:** Blocks Phase 00 G-05-02, G-05-03, G-07-05, G-08-02, and
  G-08-04; blocks Phase 01 G-01-17, G-01-19, and G-01-21.
- **Evidence needed to close:** End-to-end canary requests followed by direct
  inspection of every log file/sink payload, Telescope row, Sentry envelope,
  trace, Horizon failure, error response, and CI artifact; tests must fail when
  any one sanitizer is removed.

### ISR-006 — Authentication abuse controls are miswired and proxy-unsafe

- **Severity:** High
- **Affected files/classes/routes:** `AuthRateLimiter`, `AuthServiceProvider`,
  `config/cache.php`, `config/database.php`, `bootstrap/app.php`,
  `.env.example`, `infra/environments/local.env`, `tests/k6/auth-abuse.js`;
  login, OTP request, recovery, MFA, and refresh routes.
- **Attack or failure scenario:** Laravel's `RateLimiter` uses the default
  cache store because no limiter store is configured; the named `ratelimit`
  Redis connection is only checked for readiness and is never selected by the
  auth limiter. Cache flush/eviction therefore resets abuse state. No trusted
  proxy configuration exists even though production traffic is required to
  arrive through a gateway: if the proxy is not trusted, every caller shares
  the gateway IP key and an attacker can sustain a global login denial with
  roughly 20 attempts/minute. Refresh has no endpoint/family/IP throttle.
  Recovery completion increments only the volatile limiter and not the OTP's
  durable attempt count.
- **Why existing protection is insufficient:** Four named Redis connections do
  not prove that callers use them. Tests use `CACHE_STORE=array`. The k6 script
  has no refresh scenario despite its README, accepts almost every safe 2xx/4xx
  status as success, and was never run; it does not prove throttling activates
  or remains isolated under Redis loss.
- **Required remediation:** Bind the auth limiter to the dedicated rate-limit
  store; set exact trusted proxy addresses and validate forwarded headers;
  combine subject, IP/network, device/family, global budget, and durable
  challenge counters; add bounded refresh/recovery/MFA controls; define
  fail-safe behavior for rate-store loss without creating a global proxy-IP
  lockout.
- **Closure impact:** Blocks Phase 00 G-04-05, G-04-06, G-08-02, and G-08-04;
  blocks Phase 01 G-01-10, G-01-12, G-01-20, and G-01-21.
- **Evidence needed to close:** Integration proof of keys in the `ratelimit`
  connection only; exact proxy-chain tests; Redis flush/eviction behavior;
  distributed login/OTP/recovery/refresh/MFA abuse tests with assertions on
  when 429 occurs, unaffected-user behavior, cost ceiling, and recovery after
  decay.

### ISR-007 — Recovery, MFA lifecycle, and bootstrap do not implement the approved assurance design

- **Severity:** Medium
- **Affected files/classes/routes:** `CompleteRecoveryHandler`,
  `RequestOtpHandler`, `CompleteMfaHandler`, `BootstrapAdminCommand`,
  `PlatformFeatures`, `mfa_recovery_codes` schema and unused store methods,
  ADR 0011 and MFA/account-takeover runbooks; recovery/MFA routes and
  `identity:bootstrap-admin`.
- **Attack or failure scenario:** If recovery is enabled, possession of the SMS
  OTP directly resets the password. The approved cooling-off, risk signals,
  manual review, old/new channel notifications, and operator separation are
  absent. Privileged MFA enrollment and recovery-code lifecycle have no HTTP
  workflow. The bootstrap command accepts the password as a positional
  argument, prints a complete TOTP provisioning URI, marks the factor verified
  without confirming a code, bypasses `PasswordPolicy`, emits no audit event,
  and can create multiple admins with different phones while enabled. A lost
  TOTP factor cannot be re-enrolled by the bootstrap command despite the
  runbook's instruction.
- **Why existing protection is insufficient:** Production feature lockout
  reduces current exposure but does not make an incomplete high-risk path
  eligible for Phase 01 closure. A database table and runbook do not implement
  recovery codes, proofing, notification, or separation of duties.
- **Required remediation:** Implement the approved recovery state machine with
  risk decisions, cooling-off/manual review, notifications, durable attempts,
  and purpose-bound proof. Provide verified MFA enrollment, rotation, recovery
  codes, and re-enrollment. Make bootstrap genuinely one-time, audit it,
  enforce password policy/immediate change, use hidden secret input, and verify
  the first TOTP before activation.
- **Closure impact:** Does not independently block the Phase 00 foundation;
  blocks Phase 01 G-01-01, G-01-03, G-01-08, G-01-19, and closure of recovery
  and identity-assurance claims.
- **Evidence needed to close:** Adversarial SIM-swap/reassigned-phone, stolen
  OTP, risk/manual-review, old/new notification, bootstrap replay, process-list
  leakage, TOTP enrollment/recovery, recovery-code replay, Redis-loss, and
  privileged-account recovery tests plus accountable human approval to enable.

### ISR-008 — The audit trail is not a verifiable tamper-evident chain

- **Severity:** High
- **Affected files/classes/routes:** `PostgresAuditStore`,
  `audit_events` migration/grants, all `AppendAuditEvent` call sites, Auth
  issuance/refresh/reuse/bootstrap paths.
- **Attack or failure scenario:** Concurrent appenders read the same latest
  hash without a lock and can create two valid-looking successors. The row hash
  omits `actor_id` and `actor_type` and hashes second-resolution time while the
  row stores microseconds. There is no chain verifier, scheduled verification,
  checkpoint/signature, or alert. `CHECK (true)` provides no append-only
  protection, and app/worker roles can insert arbitrary audit rows. Important
  events—login/session issuance, failed privileged attempts, refresh reuse,
  bootstrap, and decrypt—lack durable audit records.
- **Why existing protection is insufficient:** Revoking UPDATE/DELETE from a
  subset of roles does not serialize writers, authenticate rows, cover omitted
  fields, detect owner/migrator changes, or make missing events observable.
  No test attempts concurrent append or field tampering.
- **Required remediation:** Define the audit trust model. Serialize chain
  assignment (or use independently verifiable per-stream sequencing), hash all
  security-relevant immutable fields in canonical binary form, isolate a
  narrow audit-writer role, prevent arbitrary inserts, checkpoint/sign outside
  the mutable database, and implement continuous verification/alerting.
  Complete the event taxonomy with minimal, redacted metadata.
- **Closure impact:** Blocks Phase 00 G-08-02 and G-08-04; blocks Phase 01
  G-01-02, G-01-19, and G-01-21.
- **Evidence needed to close:** Repeated concurrent append tests with one
  linear chain; mutation/insertion/deletion oracle tests for each role;
  verifier output and alert evidence; event-coverage matrix for every identity,
  credential, grant, session, MFA, recovery, bootstrap, and decrypt transition.

### ISR-009 — Contextual grant and identity-disable ports lack caller authorization and grant audit

- **Severity:** Medium
- **Affected files/classes/routes:** `GrantContextualAccessHandler`,
  `RevokeContextualAccessHandler`, `ListEffectiveCapabilitiesHandler`,
  `DisableIdentityCoordinator`, `PostgresGrantStore`, `DefaultDenyAuthorizer`.
- **Attack or failure scenario:** Any future coordinator or compromised
  in-process/worker caller that obtains these public ports can grant a known
  capability, forge `issued_by_*`, revoke any grant, or disable any identity.
  The services accept no authenticated caller context, perform no authorization
  decision, and write no audit/outbox record. When future phases add known
  clinical/pharmacy capabilities, the existing generic grant path immediately
  becomes a privilege-escalation primitive.
- **Why existing protection is insufficient:** A unique index prevents duplicate
  active grants; it does not decide who may grant what to whom. Filtering
  unknown capability strings on read does not protect newly added known values.
  Current HTTP non-exposure is temporary architecture isolation, not an
  authorization control at the use-case boundary.
- **Required remediation:** Require server-built initiator `ActorContext`,
  explicit action/resource/context policy, allowed issuer type, assurance,
  reason, validity bounds, transaction, audit, and outbox event for grant,
  revoke, and identity-disable operations. Keep persistence ports internal.
- **Closure impact:** Phase 00 impact is architectural residual risk; blocks
  Phase 01 G-01-01, G-01-11, G-01-12, G-01-19, and privilege-escalation closure.
- **Evidence needed to close:** Full authorization matrix; negative cross-role,
  self-grant, wrong-context, stale-assurance, forged-issuer, IDOR, and concurrent
  grant/revoke tests; audit/event assertions.

### ISR-010 — Realtime revocation is a metric placeholder and Reverb defaults are unsafe for activation

- **Severity:** Medium
- **Affected files/classes/routes:** `routes/channels.php`,
  `SessionRevokedConsumer`, `config/reverb.php`, `SessionRevoked` event.
- **Attack or failure scenario:** `SessionRevokedConsumer` updates metrics only;
  it never identifies or disconnects a socket. All current channels deny access,
  so immediate exposure is limited, but enabling the first private channel
  would inherit client events allowed for `members`, no connection cap, and
  disabled rate limiting unless every deployment overrides defaults. Refresh
  reuse also emits a device ID in the event's `session_id` field.
- **Why existing protection is insufficient:** Measuring consumer lag is not
  disconnecting a connection. HTTP revocation does not retract data already
  flowing over an authorized WebSocket. “No channels yet” means the gate is
  open, not passed.
- **Required remediation:** Bind channel authorization and connection registry
  to the exact actor/session/device; implement revoke fan-out and disconnect;
  deny client events by default; set finite connection/message/rate limits and
  exact origins; correct event identifiers.
- **Closure impact:** Does not fail Phase 00's deny-all scaffold by itself;
  blocks Phase 01 G-01-16, G-01-19, G-01-21, and the measured revocation exit
  criterion.
- **Evidence needed to close:** Real Reverb tests showing unauthorized channel
  denial, cross-user/BOLA denial, revoke-to-close latency under load and worker
  restart, no post-revoke delivery, and safe defaults with environment values
  absent.

### ISR-011 — Electron packaged trust boundary and credential lifecycle do not match the design

- **Severity:** Medium
- **Affected files/classes/routes:** both desktop applications'
  `src/main/index.ts`, `src/main/capabilities.ts`, `src/shared/sender-policy.ts`,
  `src/main/platform-gateway.ts`, `src/main/device-credentials.ts`,
  `forge.config.ts`, source tests, and available ASAR artifacts.
- **Attack or failure scenario:** Source calls `loadURL(MAIN_WINDOW_WEBPACK_ENTRY)`;
  Forge's packaged constant resolves to `file://.../renderer/main_window/index.html`.
  Both inspected ASARs do so and contain no claimed custom-origin URL. The
  exact packaged sender policy rejects `file://`, so packaged IPC should fail;
  the registered custom protocol is unused and points at a different renderer
  root. The HTTP adapter accepts an arbitrary `CLINIC_API_BASE_URL`, defaults to
  plaintext local HTTP, and does not enforce the production endpoint/scheme.
  It stores a refresh token but implements no refresh operation; logout swallows
  server failure and clears local state, potentially leaving a 30-day server
  refresh credential active.
- **Why existing protection is insufficient:** The 74 passing tests inspect
  source strings and a pure origin policy; they never open the packaged app or
  prove that the loaded origin is the accepted origin. Fuses/configuration
  intent do not prove the binary. Post-write `chmod` is also non-atomic, and
  credential-file delete failure is not handled fail-closed.
- **Required remediation:** Load packaged content through the actual privileged
  custom origin; fix/verify path containment and renderer root; bind artifact
  provenance to the reviewed SHA; enforce an allowlisted HTTPS production API
  origin; implement coordinated refresh, expiry, revocation, atomic
  credential-file replace/delete, and explicit unrecoverable-cleanup handling.
- **Closure impact:** Blocks Phase 00 G-02-09, G-02-10, G-06-01, and G-08-04;
  blocks Phase 01 G-01-15 and client-flow exit criteria.
- **Evidence needed to close:** Installed-package WebdriverIO tests on every
  approved OS/architecture; runtime URL/origin and IPC success/denial proof;
  binary fuse inspection; malicious navigation/path tests; TLS endpoint tests;
  refresh/lost-response/logout/revoke tests; OS keystore and file-permission
  tests against a SHA-bound artifact.

### ISR-012 — Flutter refresh and secure-storage updates are not failure-atomic

- **Severity:** Medium
- **Affected files/classes/routes:**
  `packages/flutter/authentication/lib/src/auth_api.dart`, `auth_interceptor.dart`,
  `token_store.dart`; `packages/flutter/secure_storage`; patient Android
  manifest and backup tests.
- **Attack or failure scenario:** Refresh creates a timestamp idempotency key on
  each invocation rather than retaining one random key for the logical attempt.
  If the server rotates and the response is lost, a retry presents the old
  refresh token under a new key and can revoke the family. `TokenStore` writes
  access then refresh and deletes them sequentially; an OS/keychain/plugin
  failure can leave mismatched or uncleared credentials while the client
  believes the operation failed or logout completed.
- **Why existing protection is insufficient:** Fake-vault tests do not inject a
  failure between writes/deletes and do not exercise an OS keystore, backup,
  restore, migration, uninstall/reinstall, rooted/jailbroken state, or device
  transfer. Android `allowBackup=false` and device-only iOS accessibility are
  useful configuration, but the required OS matrix has not run.
- **Required remediation:** Use a single durable random idempotency key per
  refresh attempt and a server protocol that can recover the rotated outcome.
  Store one versioned credential envelope atomically or implement rollback and
  mismatch recovery; make clear/delete verification fail closed; define
  keystore invalidation behavior.
- **Closure impact:** Blocks Phase 00 G-06-01/G-08-04 evidence; blocks Phase 01
  G-01-09 and G-01-14.
- **Evidence needed to close:** Fault-injection after each vault operation;
  lost-response/duplicate-refresh tests; Android/iOS backup/restore and device
  transfer matrix; keystore reset, biometric/passcode change, uninstall, and
  secure-clear verification on real/supported OS versions.

### ISR-013 — Data inventory, minimization, retention, and deletion are incomplete

- **Severity:** Medium
- **Affected files/classes/routes:**
  `docs/data-classification/data-inventory.md`; Phase 01 migration;
  `PruneExpiredAuthStateCommand`; `PostgresAuthStore::pruneExpiredOtps` and
  `pruneExpiredSessions`; Identity close/disable paths.
- **Attack or failure scenario:** A breach or deletion request cannot be scoped
  reliably because the inventory omits most Phase 01 fields and entire holdings:
  profile links, contextual grants, audit events, recovery-code fields, MFA
  challenge fields, device/session metadata, rate-limit keys, most events,
  auth metrics, client credential files, and actual sink/access roles. Expired
  OTP pruning merely marks rows invalid; encrypted OTP code/destination and
  hashes remain indefinitely. Expired sessions are marked revoked but retained.
  No account deletion/anonymization workflow or approved retention period
  exists.
- **Why existing protection is insufficient:** Grouping four credential tables
  into one sentence is not “one row per field/event/log/metric/cache/file.”
  Encryption reduces disclosure impact but does not satisfy minimization,
  purpose limitation, expiry, deletion, or lawful basis. A named owner is not
  an approved schedule.
- **Required remediation:** Build a field-level inventory generated/reconciled
  against schemas, events, metrics, caches, queues, logs, object/local files,
  and third parties. Record classification, purpose, lawful basis (by qualified
  reviewer), readers/writers, location, transfers, retention trigger/period,
  deletion/anonymization action, encryption, and accountable owner. Implement
  secure pruning and account closure with audit-preservation rules.
- **Closure impact:** Blocks Phase 00 G-05-02 and G-08-04; blocks Phase 01
  G-01-19 and privacy exit criteria.
- **Evidence needed to close:** Schema-to-inventory completeness check;
  approved retention schedule; timed purge tests proving ciphertext and client
  copies are removed; data-subject deletion/export test; backup/replica/search/
  telemetry propagation evidence; written legal decisions where required.

### ISR-014 — Key management, audited decrypt, and transport/encryption defaults are not production-safe

- **Severity:** Medium
- **Affected files/classes/routes:** ADR 0013, `AesGcmEnvelopeEncryptor`,
  `HkdfHmacHasher`, `NationalIdProtector`, `RotateIdentityKeysCommand`, identity
  schema/store, `ConfigurationCheck`, `config/database.php`, session/CORS/
  Reverb environment defaults.
- **Attack or failure scenario:** Any non-empty one-character identity key
  passes readiness and is hashed into an AES key, preserving only the original
  key's entropy. HMAC lookup computes only the current version; rows have no
  independently usable HMAC version/dual-read lookup, and the “rotation” command
  only counts rows. Rotating the HMAC key can make existing accounts
  unfindable. TOTP/OTP decrypts are not audited despite ADR 0013. Production KMS,
  encrypted volumes/backups, DB/Redis TLS, and certificate/endpoint evidence do
  not exist; DB SSL defaults to `prefer`. Admin `Secure` cookies and exact CORS/
  Reverb origins are not enforced by readiness.
- **Why existing protection is insufficient:** AES-GCM and purpose AAD are good
  primitives, but an environment string is not a key-management system and a
  non-empty check is not key validation. Production-locking registration and
  recovery does not disable login, phone HMAC lookup, TOTP, or existing
  identity reads.
- **Required remediation:** Bind production to an approved KMS/secret manager;
  enforce key format, entropy, version existence, workload/environment
  separation, access policy, and audit. Implement and test dual-read/new-write
  lookup/backfill/rollback. Audit plaintext decrypt use without logging values.
  Enforce TLS/verification and secure cookie/origin settings at startup for
  non-local environments; document volume/backup encryption and restore keys.
- **Closure impact:** Blocks Phase 00 G-05-04, G-08-02, and G-08-04; blocks
  Phase 01 G-01-05, G-01-17, G-01-19, and production identity readiness.
- **Evidence needed to close:** KMS policy and access logs; weak/missing/wrong
  key negative tests; v1/v2 dual-read and resumable backfill with rollback;
  audited-decrypt records; TLS handshakes/certificate validation; secure-cookie
  and exact-origin startup failures; encrypted backup restore drill.

### ISR-015 — CI/security gates are unexecuted and expose a mutable build-control plane

- **Severity:** High
- **Affected files/classes/routes:** `.github/workflows/pull-request.yaml`,
  `.github/workflows/post-merge.yaml`, `.github/CODEOWNERS`,
  `tests/k6/auth-abuse.js`, root lockfile, Phase 00 SF-001 evidence.
- **Attack or failure scenario:** Third-party actions use mutable tags, including
  `aquasecurity/trivy-action@master`. In post-merge, tagged actions execute in a
  job with package write, OIDC, and attestation permissions; upstream tag
  compromise can alter the release control plane. Filesystem scanning uses
  `ignore-unfixed: true`, so the known unfixed High `extract-zip` finding is not
  a reliable blocking gate. No workflow has run, staging deliberately fails,
  CODEOWNERS teams do not exist, and the special security CODEOWNER rule names
  a nonexistent `security.yml` rather than the actual workflows. Root dependency
  changes do not necessarily select all affected client test jobs.
- **Why existing protection is insufficient:** YAML intent is not execution
  evidence. A generated SBOM action is not a reviewed SBOM. The gate-count job
  validates declared counts, not truth. As of review, `extract-zip@2.0.1` remains
  affected by High [GHSA-jmr9-qjv8-65gv](https://github.com/advisories/ghsa-jmr9-qjv8-65gv)
  with no patched release, and npm still lists 2.0.1 as current. There is no
  accepted, time-boxed exception.
- **Required remediation:** Pin every action and container by immutable digest/
  commit, minimize job-scoped permissions, protect workflow changes with real
  reviewers, and verify provenance before deploy. Execute dependency, SAST,
  secret-history, IaC, container, license, SBOM, and policy gates. Do not
  globally ignore unfixed Highs; require a signed exception with owner, controls,
  expiry, and automatic failure. Add real PostgreSQL-role, packaged Electron,
  auth abuse, concurrency, CSRF, redaction-sink, and client OS jobs.
- **Closure impact:** Blocks Phase 00 G-06-02, G-06-03, G-06-05, G-08-02, and
  G-08-04; blocks Phase 01 G-01-20, G-01-21, and G-01-23.
- **Evidence needed to close:** Enforced branch/ruleset screenshots or API
  output; successful and deliberately failing workflow runs at the reviewed
  SHA; pinned-action review; retained signed artifacts/SBOM/provenance; scan
  outputs; SF-001 remediation or valid unexpired exception; staging smoke and
  authorization-canary results.

### ISR-016 — Threat models are incomplete, stale, and materially inaccurate

- **Severity:** Medium
- **Affected files/classes/routes:**
  `docs/threat-models/phase-00-foundation.md`,
  `docs/threat-models/phase-01-identity.md`, phase specifications, data
  inventory, evidence ledgers.
- **Attack or failure scenario:** Reviewers accept risks as covered when the
  modeled system is not the implemented system. Phase 00 calls eight required
  boundaries “seven” and omits Electron renderer→preload/main/OS. It calls
  reporter access harmless before Phase 01, yet the grant remains after
  identity data was added; says nothing is cached while auth rate controls use
  cache; cites an SBOM that was never produced; and defers backup exposure
  without modeling client backups. The Phase 01 delta is a 25-line table that
  omits the concrete failures in this report.
- **Why existing protection is insufficient:** A control/gap summary is not a
  complete threat model when it lacks assets, actors, entry points,
  preconditions, attack paths, impact, control ownership, verification,
  residual rating, and acceptance. Owner acceptance by the implementer cannot
  correct factual omissions.
- **Required remediation:** Rebuild the model from actual deployment/data-flow
  diagrams and all eight Phase 00 boundaries. Add Phase 01 routes, session
  families, cookie mapping, bootstrap/recovery/MFA, rate stores/proxies,
  idempotency, audit, queues, Telescope/Sentry/log sinks, realtime, Flutter,
  Electron, KMS, backups, CI/release, insiders, and support/operator abuse.
  Link each control to executable evidence and an accountable independent
  acceptance decision.
- **Closure impact:** Blocks Phase 00 G-08-01, G-08-03, and G-08-04; blocks
  Phase 01 G-01-03, G-01-19, and G-01-21.
- **Evidence needed to close:** Independent threat-model workshop record;
  versioned DFDs; complete threat register with residual ratings/owners/dates;
  traceability to every finding and negative test; independent security and
  privacy sign-off after remediation.

### ISR-017 — Runtime schemas and National ID validation do not match the stated contracts

- **Severity:** Low
- **Affected files/classes/routes:** Auth controllers using
  `$request->validate`, OpenAPI schemas with `additionalProperties:false`,
  `NationalId`, `IdentityRulesTest`, Phase 01 invariants.
- **Attack or failure scenario:** Clients can submit unexpected properties such
  as role/tenant/scope fields and Laravel silently ignores them even though the
  OpenAPI contract says they are rejected. This is not a current mass-assignment
  exploit because handlers select explicit fields, but it removes the negative
  oracle and creates a future property-level authorization trap. The phase says
  invalid check digits are handled by one reviewed National ID function while
  the function explicitly applies no check-digit algorithm.
- **Why existing protection is insufficient:** OpenAPI generation does not
  enforce runtime request closure. A comment that sources disagree is a design
  question, not evidence that the phase requirement was resolved by the
  accountable identity/legal owner.
- **Required remediation:** Enforce closed request DTO/FormRequest schemas at
  runtime and add unknown-property tests. Obtain and record the authoritative
  Egyptian National ID validation decision; either implement the approved
  algorithm or amend the phase/ADR and assurance claim explicitly.
- **Closure impact:** Does not alone block Phase 00; blocks Phase 01's 100%
  schema/negative-test exit condition unless formally accepted and corrected.
- **Evidence needed to close:** Per-route unexpected-property tests, fuzzing,
  generated-contract/runtime parity check, and a documented National ID
  validation decision with positive/negative property tests.

## 6. Gate-by-gate assessment

Status meanings used here: **PASS** means the implemented control and evidence
satisfy the gate; **PARTIAL** means useful implementation exists but the gate is
not complete; **OPEN** means required execution/evidence is absent; **FAIL**
means implementation or evidence contradicts the gate or a material exploit
remains.

### Relevant Phase 00 gates

| Gate | Independent status | Basis |
| --- | --- | --- |
| G-02-09 Electron trust boundary | **FAIL** | Packaged content is `file://` while the accepted sender must use the custom origin; source tests miss it. |
| G-02-10 packaged Electron E2E | **OPEN** | No SHA-bound installed-package/OS-matrix/fuse evidence. |
| G-04-01 least-privilege PostgreSQL roles | **FAIL** | Reporter reads all migrator-created tables; worker has global DML; workload credentials are not wired/tested. |
| G-04-05 Redis role separation | **PARTIAL** | Connections exist, but Auth's limiter uses the default cache rather than `ratelimit`. |
| G-04-06 Redis loss safety | **PARTIAL** | Authoritative platform rows survive, but security throttle state shares evictable cache and its loss behavior is untested. |
| G-05-01 classification taxonomy | **PASS** | The five-level taxonomy and core type rules exist; this does not validate inventory completeness. |
| G-05-02 complete data inventory | **FAIL** | Phase 01 fields/holdings/access/retention/deletion/lawful basis are incomplete. |
| G-05-03 logging redaction | **FAIL** | Processor tests pass, but supported channels and Telescope bypass the boundary. |
| G-05-04 TLS/private/encrypted storage | **PARTIAL** | Local loopback helps; no non-local TLS, volume/backup, or KMS evidence, and unsafe defaults are accepted. |
| G-05-05 synthetic data | **PASS** | Synthetic generators are deliberately non-real; this does not authorize real-data use in Telescope/local environments. |
| G-06-02 PR CI/security pipeline | **FAIL** | Never run; mutable actions; missing required adversarial jobs and real role tests. |
| G-06-03 post-merge/staging | **OPEN** | Staging deliberately exits 1 and smoke/migration checks are placeholders. |
| G-06-05 SBOM/no unaccepted findings | **FAIL** | No executed/reviewed SBOM; unfixed High SF-001 remains without accepted exception. |
| G-07-05 export-path redaction | **FAIL** | In-memory gateway coverage does not cover Telescope, configured logs, Sentry, Horizon failures, or artifacts. |
| G-08-01 threat model | **FAIL** | One required boundary is omitted and material implemented paths/claims are stale. |
| G-08-02 mandatory security controls | **FAIL** | Least privilege, redaction, audit, secure configuration, CI, and production evidence fail. |
| G-08-03 OWASP mappings | **PARTIAL** | Versioned mapping exists, but implementation/evidence statuses must be revised for these findings. |
| **G-08-04 security/privacy approval** | **FAIL** | The artifacts are incomplete/inaccurate, the sole owner is also implementer, and this review finds blocking defects. No legal approval exists. |

### Phase 01 gates

| Gate | Independent status | Basis |
| --- | --- | --- |
| G-01-01 modules/public ports | **PARTIAL** | Modules exist; MFA/recovery and grant/disable authorization/audit boundaries are incomplete. |
| G-01-02 identity schema/constraints | **FAIL** | Broad grants, audit weakness, and no workable HMAC-version migration model. |
| G-01-03 assurance/claim/recovery ADR | **FAIL** | Implemented recovery and bootstrap contradict the accepted design; approvals are not independent. |
| G-01-04 TOTP package ADR/lock | **PASS** | Package is pinned and verifier has replay protection; lifecycle failures are assessed under other gates. |
| G-01-05 key management | **FAIL** | No KMS, audited decrypt, entropy enforcement, dual HMAC lookup, or executable backfill/rollback. |
| G-01-06 contracts/generated clients | **PARTIAL** | Generated contracts exist, but runtime does not enforce closed schemas and cookie/client behavior drifts. |
| G-01-07 registration→OTP→restricted session | **PARTIAL** | Useful flow implementation exists; independent DB run was unavailable and downstream token/session defects remain. |
| G-01-08 privileged TOTP/replay | **PARTIAL** | TOTP replay primitive is sound; enrollment/bootstrap/recovery/admin binding are not. |
| **G-01-09 refresh reuse** | **FAIL** | Only N-1 reuse is detected; logout-after-refresh and absolute lifetime fail. |
| G-01-10 enumeration-safe login | **PARTIAL** | Response text is collapsed; timing, proxy/rate behavior, and measured resistance are not proved. |
| G-01-11 default deny/no clinical capability | **PARTIAL** | Current route checks are useful; generic grant/disable ports are not authorization-complete. |
| G-01-12 concurrency | **OPEN** | Required two-connection races were not run; refresh and audit designs fail under concurrency. |
| **G-01-13 admin cookie/CSRF** | **FAIL** | Cookie is not bound to normalized session; MFA completion is CSRF-exempt; existing test has a false-positive oracle. |
| G-01-14 Flutter secure token store | **PARTIAL** | Secure-storage configuration exists; refresh/atomicity faults and OS matrix are open. |
| **G-01-15 Electron credentials** | **FAIL** | Packaged origin contradicts source policy, no coordinated refresh, no SHA-bound OS evidence. |
| G-01-16 realtime revocation SLO | **OPEN** | No disconnect implementation or measured SLO. |
| **G-01-17 identity redaction** | **FAIL** | Telescope/log/Sentry/Horizon/artifact paths are not proven and include concrete bypasses. |
| G-01-18 Octane alternating identity | **OPEN** | No authenticated alternating-user HTTP case for Phase 01. |
| **G-01-19 threat/inventory/runbooks/alerts** | **FAIL** | Threat/inventory are incomplete; several runbooks describe nonexistent controls. |
| G-01-20 k6 abuse harness | **OPEN** | Never executed; implementation does not test refresh and does not assert throttle behavior. |
| **G-01-21 no Critical/unaccepted High** | **FAIL** | Eight High findings in this report plus unresolved SF-001; no accepted exceptions. |
| G-01-22 claim non-disclosure control | **PASS** | Production flag is fail-closed and the current registry adapter cannot attach a profile; enablement remains unapproved. |
| G-01-23 quality/CI | **PARTIAL** | Local command claims exist, but CI has never run and this review could not reproduce DB/Flutter suites in the host toolchain. |

## 7. Residual risks and review limitations

- No authorized staging target or rules of engagement existed, so no active
  external DAST, WebSocket attack, provider abuse, proxy-chain test, or network
  segmentation test was performed.
- Host PHP lacks `pdo_pgsql`; Flutter is unavailable. This review does not
  convert those unavailable runs into a pass or a product defect. It relies on
  code/data-flow evidence for the blocking findings and explicitly requires
  independent reruns.
- Available Electron packages were not built from an immutable manifest tied to
  the reviewed SHA. Their `file://` behavior corroborates the source/build
  configuration but cannot close any artifact gate.
- No production KMS, secrets manager, gateway, TLS configuration, encrypted
  backup, staging environment, branch protection, signed artifact, or CI run was
  available to assess. Their absence is evidence missing, not evidence of
  safety.
- No clinical module/data exists yet. Future clinical, pharmacy, lab, file,
  chat, analytics, and AI flows require new threat-model and privacy deltas;
  this report must not be cited as their approval.
- Dependency/advisory state is time-sensitive. Re-scan at remediation and
  release; do not rely indefinitely on this report's 2026-08-26 snapshot.
- Legal/compliance questions remain entirely open and must be decided by
  qualified humans in the relevant jurisdictions and professional domains.

## 8. Required fixes

### Required before either closure decision

1. Correct database role grants and prove real workload separation.
2. Replace the refresh/session model so rotation, all-generation reuse,
   absolute expiry, logout, remote revoke, and lost responses are coherent.
3. Bind admin cookies to exact normalized sessions; close MFA login CSRF and
   enforce account/client compatibility.
4. Remove credential-bearing idempotency fingerprints and redesign refresh
   idempotent replay.
5. Close Telescope/log/export leakage and run end-to-end sink canaries.
6. Wire distributed auth throttles to the dedicated store with an exact trusted
   proxy model and durable challenge controls.
7. Replace the audit design with a serialized, complete, independently
   verifiable, least-privileged trail.
8. Pin and execute CI/security/release controls; resolve or formally accept
   SF-001 under policy with a real expiry.

### Required before Phase 01 closure

9. Implement the approved recovery, MFA enrollment/recovery, and one-time
   bootstrap workflows.
10. Authorize and audit contextual grant/revoke and identity-disable ports.
11. Implement and measure realtime session disconnection.
12. Correct Electron packaged origin/transport/refresh and complete the
    installed OS matrix.
13. Make Flutter token rotation/storage failure-atomic and complete its OS
    backup/restore matrix.
14. Implement production-grade key validation, KMS binding, audited decrypt,
    dual-version lookup/backfill, TLS, and secure configuration checks.
15. Complete the data inventory, retention/deletion implementation, and
    accountable legal/privacy decisions.
16. Rewrite the threat models and assurance mappings against the remediated
    system, then obtain independent human review.

## 9. Re-test requirements

Closure requires fresh evidence against one immutable commit, not edits to the
existing ledgers:

- Run all Laravel tests with real PostgreSQL roles and dedicated Redis
  connections; include repeated two-connection races for OTP, refresh, grants,
  and audit append.
- Add exploit regression tests for logout-after-refresh, N-2/N-10 reuse,
  lost refresh responses, absolute expiry, stale credential versions, and
  per-session/all-session revoke.
- Run valid-admin browser tests for login/MFA/logout CSRF, fixation, two-cookie
  binding, wrong client class, and cookie flags through the production proxy.
- Inspect database rows after synthetic credential requests and prove no
  password/OTP/token guessing oracle remains.
- Inject all identity and clinical canaries through every request/error/job
  path and inspect Telescope, every configured log channel, Sentry, traces,
  Horizon failures, Redis, outbox/dead-letter rows, reports, and CI artifacts.
- Execute k6 scenarios that assert exact limits and unaffected-user behavior
  for stuffing, spraying, OTP flood/guess/replay, recovery, MFA, refresh, and
  cost budgets; repeat during cache/rate-store failure.
- Run Reverb BOLA/channel tests and measure revoke-to-disconnect latency under
  load, duplicate events, and worker restart.
- Build Electron once per approved OS/architecture from the reviewed SHA;
  inspect fuses and SBOM, then run installed-package XSS/IPC/navigation/
  protocol/keystore/token lifecycle tests.
- Run Flutter on supported Android/iOS devices/emulators with backup/restore,
  keystore invalidation, partial-write/delete injection, and refresh-loss tests.
- Run key v1→v2→rollback, wrong/missing/weak key, decrypt-audit, KMS outage, and
  encrypted backup restore tests without plaintext output.
- Run the complete pinned CI pipeline, retain artifacts/provenance/SBOM/scans,
  deliberately seed each gate with a safe failing fixture, and prove branch
  protection prevents bypass.
- Reconcile schema/events/logs/metrics/caches/files/third parties against the
  data inventory and execute approved deletion/retention procedures.
- Perform an independent remediation review. The remediator must not close
  their own findings; lower findings need named owners and due dates, and every
  High must be fixed or accepted exactly as policy permits.

## 10. Final recommendation

### Technical security/privacy recommendation: **DO NOT APPROVE**

- **Phase 00 G-08-04: FAIL.** Threat-model and data-classification artifacts are
  materially incomplete/inaccurate, mandatory controls fail, and the existing
  self-approval is not adequate evidence.
- **Phase 01 G-01-21: FAIL.** Multiple unaccepted exploitable High findings
  remain, including session revocation, admin session/CSRF, database privilege,
  credential leakage, auth abuse controls, audit integrity, and CI/release
  control-plane failures.
- **Phase 00 closure:** not approved.
- **Phase 01 closure:** not approved.

### Legal/compliance recommendation: **NO APPROVAL PROVIDED**

This report intentionally makes no legal-compliance determination. Qualified
legal, privacy, clinical, pharmacy-regulatory, and records-retention reviewers
must issue their own written decisions after the technical findings and data
inventory are remediated.

No implementation finding was remediated during this review.
