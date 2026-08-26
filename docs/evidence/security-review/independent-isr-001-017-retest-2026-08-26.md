# Independent retest — ISR-001 through ISR-017

**Retest date:** 2026-08-26  
**Technical recommendation:** **DO NOT APPROVE**  
**G-08-04:** **FAIL**  
**G-01-21:** **FAIL**  
**Legal/compliance approval:** **NOT PROVIDED**

This is an independent technical retest of the 17 findings in
[independent-phase-00-phase-01-review-2026-08-26.md](independent-phase-00-phase-01-review-2026-08-26.md).
It does not modify product code. It does not accept ledger or implementer
claims without reproduction. It is **not legal advice** and does not grant
legal, regulatory, clinical, pharmacy, privacy-officer, or statutory approval.

## 1. What was retested

| Item | Value |
| --- | --- |
| Original review SHA | `574f16db10484817b8892562c0c6e6ec08a8b00a` |
| Current `HEAD` | same SHA (`Implement Phase 01 authentication, identity, and access.`) |
| Current product state | **uncommitted working tree** on `main` (81 tracked product files changed, plus new migrations/tests/events). Agent/tooling files under `apps/core-api/.agents/`, `.claude/`, `AGENTS.md`, `CLAUDE.md`, `boost.json`, `.mcp.json` were not treated as product controls. |
| Assessor/remediator | This retest did not author the remediations. Named owner roles remain concentrated on one person; that loss of independence still applies to SF-001's merge exception and to self-scored ledgers. |

No production, third-party, or DAST target was in scope. Live checks used the
local Compose PostgreSQL/Redis on loopback, FrankenPHP with `pdo_pgsql` for
Pest, Vitest for Electron source tests, `psql` privilege probes, GitHub Actions
API, and inspection of existing unsigned Linux ASAR files.

## 2. Result summary

| ID | Original severity | Retest | Original High still exploitable? |
| --- | --- | --- | --- |
| ISR-001 | High | **PARTIAL** | Original reporter/worker identity DML is denied on migrated `clinic_test`. Live `clinic` volume and unused audit-writer remain. |
| ISR-002 | High | **PASS** | N-2 reuse, logout-after-refresh, lost-response replay, and absolute-lifetime cap reproduced. Two-connection races still untested. |
| ISR-003 | High | **PARTIAL** | Cookie hash is bound to the Laravel session id; admin MFA CSRF is no longer client_class-exempt. Browser E2E / two-cookie tests missing. |
| ISR-004 | High | **PASS** | Password/OTP/token/National ID no longer change `request_hash`. Refresh lost-response uses an encrypted device envelope, not `idempotency_keys`. |
| ISR-005 | High | **PARTIAL** | Auth URIs are filtered/ignored; log taps are asserted at boot; Sentry `before_send` exists. No sink-canary inspection. |
| ISR-006 | High | **PARTIAL** | Auth limiter is constructed on the named `ratelimit` store; refresh/MFA hits exist. Redis/proxy/k6 evidence still missing. |
| ISR-007 | Medium | **PARTIAL** | Cooling-off / manual-review rows exist; bootstrap TOTP confirm exists. No operator apply, notifications, MFA HTTP lifecycle, or recovery-code workflow. |
| ISR-008 | High | **PARTIAL** | Advisory lock + sequence + actor in the row hash. No verifier, `CHECK (true)` remains, serving role can still INSERT audit rows. |
| ISR-009 | Medium | **PARTIAL** | Grant/revoke/disable require initiator `ActorContext` plus admin AAL2, audit, and outbox. No resource-scoped matrix. |
| ISR-010 | Medium | **PARTIAL** | Consumer publishes a Redis disconnect hint; it does not close a Reverb socket. A session channel is now authorizable. SLO unmeasured. |
| ISR-011 | Medium | **PARTIAL** | Source loads the custom origin when packaged; 74 Vitest tests pass. SHA-bound installed OS matrix is still absent; existing ASARs are stale. |
| ISR-012 | Medium | **PARTIAL** | Durable refresh idempotency key and envelope write exist. OS backup/restore matrix not run. |
| ISR-013 | Medium | **PARTIAL** | Inventory gained some Phase 01 rows. Grouped credential tables, blank lawful basis, and no deletion/purge of ciphertext remain. |
| ISR-014 | Medium | **PARTIAL** | 32-byte key floor, login dual-HMAC, TOTP decrypt audit. No production KMS/TLS; DB SSL still `prefer`; rotation rewrite blocked in production. |
| ISR-015 | High | **FAIL** | Actions are SHA-pinned in the working tree. PR CI has never run. The only GitHub run is a failed post-merge image scan at the original SHA. SF-001 remains High. |
| ISR-016 | Medium | **FAIL** | Phase 00 model still says seven boundaries, still describes reporter SELECT on every table, and still says nothing is cached. |
| ISR-017 | Low | **PARTIAL** | Closed JSON on auth API routes plus ADR 0014. Legal check-digit decision is still outstanding. |

**High findings that still independently fail G-01-21:** ISR-015 (unexecuted /
failed CI control plane plus SF-001). Several former Highs are no longer the
original exploit but are **PARTIAL**, so they cannot be scored PASS.

## 3. Per-finding evidence

### ISR-001 — Database roles / least privilege — **PARTIAL**

**Reproduced on `clinic_test` after migrations `2026_08_26_210000` and
`220000` (psql as each role, 2026-08-26):**

- `clinic_reporter` `SELECT` on `users` / `otp_requests` / `idempotency_keys` /
  `audit_events`: **denied**. `SELECT` on `reporting.account_status_counts`:
  **allowed**. Direct `SELECT id FROM users` → `permission denied for table users`.
- `clinic_worker` cannot `UPDATE users`; can `UPDATE jobs`. No DML on
  `contextual_access_grants`.
- `clinic_app` cannot `UPDATE`/`DELETE` `audit_events`; **can INSERT**.
- Default privileges for `clinic_migrator` on `clinic_test` grant table DML
  only to `clinic_app` (worker/reporter defaults revoked).
- Pest `PostgresPrivilegeTest`: 3 passed in FrankenPHP + `pdo_pgsql`.

**Still open:**

- Live serving database `clinic` has **not** applied Phase 01 migrations.
  Cluster default ACL still grants `clinic_reporter=r` and
  `clinic_worker=arwd` on future migrator-created tables. Reporter currently
  has `SELECT` on `clinic.users` and `clinic.idempotency_keys`.
- `PostgresAuditStore` is bound to the default `ConnectionInterface`, not
  `pgsql_audit`. `clinic_audit_writer` is unused at runtime.
- `clinic_backup` has `SELECT` only on `users`, not a backup-complete grant.
- Grant/revoke SQL swallows `insufficient_privilege`. CI/phpunit still connect
  as `clinic_migrator`.
- Initdb on an already-initialized volume does not pick up the working-tree
  role SQL; only a new data directory or the hardening migration applies it.

**Closure impact:** no longer the original High reporter-read of identity
hashes **where the hardening migration has run**. Does not PASS G-04-01 on the
live `clinic` volume or as a complete least-privilege workload split.

### ISR-002 — Refresh / logout / reuse / absolute lifetime — **PASS**

**Code:** `RefreshDeviceSessionHandler` records consumed hashes, looks up
older generations via `auth_refresh_consumptions`, caps `refresh_expires_at`
at `auth_sessions.absolute_expires_at`, and updates the session access hash.
`ResolveActorContext::fromAccessToken` **rejects** a device with no live
session (no AAL1 fallback). `SessionCommandHandler::logoutCurrent` revokes
session **and** device.

**Pest `AuthenticationFlowsTest` (26/26 passed, including these):**

- rotated refresh then previous token → 401, and the new token is then denied
  (family revoke);
- same `Idempotency-Key` after success returns the same refresh token
  (lost-response replay);
- original token after two rotations (N-2) → 401;
- logout with the access token, then refresh → 401.

**Residual (does not reopen the original High on this evidence):** no
two-connection/pcntl race; presenting N-1 with a **different** idempotency key
is treated as reuse (intended) and was not separately load-tested.

### ISR-003 — Admin cookie / CSRF / client class — **PARTIAL**

**Closed from the original High path:**

- After `session()->regenerate()`, both API and Inertia login bind
  `hash(cookie:<laravel session id>)` via `bindCookieSessionHash`.
- `fromCookieUser` resolves **that** hash, not “latest active row”.
- Web logout resolves the cookie session and revokes it before invalidate.
- `ClientClass::compatibleWith` rejects patient/doctor/pharmacy → `admin_web`.
- `ValidateCookieCsrf` does **not** exempt MFA completion from a stored
  `admin_web` challenge; exemption is never taken from a client-supplied
  `client_class`.
- Pest: Origin login without CSRF, with valid credentials, is 401;
  `ValidateCookieCsrf::runningUnitTests()` returns false so CSRF actually runs.

**Still open:**

- `TokenMismatchException` still maps to the same 401 `UNAUTHENTICATED` as a
  bad password (`ExceptionRenderer`). The improved test uses a real user plus
  `Origin`, but it still cannot tell CSRF from auth failure by code.
- No Playwright/browser two-cookie, fixation, or privileged-user CSRF suite.
- `identity.session` wraps device login/MFA too; any Origin/Referer/session
  cookie forces CSRF. Electron `net.fetch` Origin behavior is unproven.
- Web `/login` uses Laravel's default CSRF (good) but has no independent
  browser proof.

### ISR-004 — Idempotency credential oracle — **PASS**

`CanonicalRequestHasher` strips `password`, `current_password`, `new_password`,
`code`, `totp_code`, `recovery_code`, `refresh_token`, `access_token`,
`national_id` / `nationalId` before unkeyed SHA-256.

Unit test: login hashes with different passwords are equal; refresh hashes with
different tokens are equal.

Refresh success replay re-enters the handler; tokens come from
`refresh_replay_ciphertext` on the device row (purpose `refresh_replay`), not
from `idempotency_keys.response_reference`. Pre-auth actor keys use
`preauth-phone:` + SHA-256(phone) when a phone is present.

**Residual:** the remaining fingerprint is still unkeyed SHA-256 of
secret-free fields; refresh without a phone still uses a path-scoped pre-auth
namespace. That is not the original password/OTP oracle.

### ISR-005 — Telescope / logs / export — **PARTIAL**

- `TelescopeServiceProvider` drops entries whose URI contains `/api/v1/auth`
  outside `local`, and hides additional request parameters (codes, tokens,
  national_id, phone). `config/telescope.php` `ignore_paths` includes
  `api/v1/auth*`. Gate: `viewTelescope` is always false. Testing env does not
  register Telescope (`TelescopeGateTest` passed).
- Every configured emitting log channel in `config/logging.php` has
  `RedactingLogTap`. `AppServiceProvider::assertLogRedaction` fails boot if a
  non-null/non-stack channel lacks it.
- `config/sentry.php` sets `before_send` to `SentryBeforeSend::filter` and
  `send_default_pii` false. The filter unsets request cookies/data/env/headers
  when those keys exist as an array.

**Still open (original closure bar):**

- RequestWatcher is still enabled; no `hideResponseParameters`.
- Query/Redis watchers can still persist identity-adjacent local debug.
- No end-to-end canary into Telescope rows, Sentry envelopes, Horizon
  failures, or CI artifacts. Tests would not fail if a sanitizer were removed
  from a live sink.
- Local `.env.example` still sets `TELESCOPE_ENABLED=true`.

### ISR-006 — Auth abuse controls — **PARTIAL**

- `AuthServiceProvider` builds `RateLimiter` from `cache.store(config('cache.auth_rate_limiter'))`,
  default `ratelimit`. `config/cache.php` defines that store on Redis
  connection `ratelimit` (DB index 3).
- `hitRefresh` / `hitMfa` exist; recovery increments OTP `attempts` and the
  limiter. Production `ConfigurationCheck` fails if `identity.trusted_proxies`
  is empty. `bootstrap/app.php` trusts only `TRUSTED_PROXIES`.
- phpunit sets `AUTH_RATE_LIMIT_STORE=ratelimit` but
  `AUTH_RATE_LIMIT_DRIVER=array`. That proves wiring, not Redis durability.
- `tests/k6/auth-abuse.js` still has no refresh scenario, still treats many
  2xx/4xx as success, and was not executed (`k6` binary absent).

### ISR-007 — Recovery / MFA lifecycle / bootstrap — **PARTIAL**

- Recovery no longer resets the password immediately for privileged accounts
  (`manual_review`) or patients when cooling-off > 0. OTP durable `attempts`
  increment. Feature flag still defaults off in local.env.
- **No** HTTP/command was found that applies `manual_review` or expired
  `cooling_off` rows. `recoveryComplete` still returns `status: completed`
  even when the password is not applied. Pest uses
  `IDENTITY_RECOVERY_COOLING_OFF_SECONDS=0`, so it does not prove cooling-off.
- No old/new-channel notifications, risk signals, or operator separation.
- `mfa_recovery_codes` store methods exist; no HTTP enrollment/rotation.
- Bootstrap: refuses production; one admin; password policy; optional hidden
  prompt; unverified TOTP until `--confirm --totp-code`; audit events. Still
  accepts a positional password and **prints the provisioning URI** (process
  list / terminal leakage). Re-enrollment of a lost factor is still not a
  full runbook implementation.

### ISR-008 — Audit chain — **PARTIAL**

- `PostgresAuditStore` takes `pg_advisory_xact_lock`, uses
  `audit_events_chain_sequence_seq`, hashes actor id/type and microsecond UTC.
- Unique `chain_sequence`. App/worker cannot UPDATE/DELETE audit rows on
  `clinic_test`.
- **Still:** `CHECK (true)` on `audit_events`; no chain verifier, schedule, or
  signature; serving `clinic_app` can INSERT arbitrary rows on the connection
  the app actually uses; no concurrent-append Pest test.

### ISR-009 — Grant / disable authorization — **PARTIAL**

- Ports now require `ActorContext`. `DefaultDenyAuthorizer` requires known
  capability, admin + privileged assurance for issue/revoke/disable.
- Audit + outbox events `access.grant_issued` / `access.grant_revoked`.
- Pest: patient grant/disable denied; admin grant unique-index and revoke;
  disable increments credential version.
- **Still:** any AAL2 admin can issue any **known** capability in any
  context; no stale-assurance, forged-issuer, or concurrent grant/revoke
  suite. `ListEffectiveCapabilities` merges known grant names; privilege
  actions still re-check admin+AAL2.

### ISR-010 — Realtime revocation — **PARTIAL**

- `SessionRevokedConsumer` still primarily updates metrics. It now
  `PUBLISH`es `clinic.session.disconnect` on the `realtime` Redis connection.
  Nothing in-tree consumes that into a Reverb disconnect.
- `Broadcast::channel('auth.session.{sessionId}')` **authorizes** the matching
  live session. Phase 00 deny-all is no longer complete.
- Reverb app defaults: `max_connections` 500, rate limiting enabled,
  `accept_client_events_from` empty, origins from env (local list, not `*`).
- `BroadcastChannelDenyTest` still only proves unauthenticated
  `/broadcasting/auth` is refused. No measured revoke-to-close SLO (G-01-16).

### ISR-011 — Electron packaged boundary — **PARTIAL**

- Packaged `loadURL` is `${APP_CONFIG.packagedOrigin}/index.html`
  (`clinic-doctor-app://-` / `clinic-pharmacy-app://-`), not Forge's
  `file://` webpack constant. Protocol registration + path containment exist.
- Packaged `net.fetch` refuses non-HTTPS. Refresh uses a retained UUID
  idempotency key. Credential writes are tmp+chmod+rename. Logout no longer
  swallows HTTP failure before clearing (failure leaves local tokens).
- `npm run desktop:test`: 37 doctor + 37 pharmacy passed. These are still
  source/string/policy tests, not installed-package WebdriverIO.
- Existing `out/.../app.asar` files still contain **both** `file://` and the
  custom-scheme strings and are **not** bound to this working tree. They
  cannot close G-02-09/G-02-10.
- Production API host is still any `CLINIC_API_BASE_URL` with https when
  packaged (scheme check, not an allowlist). Credential delete failure is not
  fail-closed.

### ISR-012 — Flutter token store — **PARTIAL**

- `AuthApi.refresh` retains `_refreshIdempotencyKey` until success or 401.
- `TokenStore.write` stores one JSON envelope then deletes legacy keys.
  `FailingVault` test proves a write throw leaves no split pair.
- `clear()` still sequential deletes without a verified empty vault.
- Flutter SDK was **not** on this host; OS backup/restore/keystore matrix
  not run.

### ISR-013 — Inventory / retention / deletion — **PARTIAL**

- Inventory adds `auth_refresh_consumptions`, `recovery_requests`, and some
  `users` / NID columns. `otp_requests` / `mfa_factors` / `user_devices` /
  `auth_sessions` remain **one grouped sentence**.
- Lawful basis still blank; periods still “engineering defaults”; “Deletion
  and anonymization procedures do not exist.”
- `pruneExpiredOtps` only sets `invalidated_at`; ciphertext/hashes remain.
  Sessions are marked revoked, not removed.

### ISR-014 — Keys / TLS / KMS — **PARTIAL**

- HMAC/encryption constructors reject keys shorter than 32 characters.
  `lookupDigests` is used on **login** phone lookup. Envelope decrypt selects
  the version in the blob.
- TOTP verify appends `auth.sensitive_decrypt` without the secret.
- `identity:rotate-keys` can rewrite phones/NIDs in non-production; production
  rewrite returns failure (“Phase 23”). OTP/TOTP ciphertext is counted, not
  rewritten. HMAC-only version migration of lookup columns is not a complete
  dual-write backfill with rollback rehearsal.
- `DB_SSLMODE` default remains `prefer`. No production KMS, volume/backup
  encryption, or TLS handshake evidence. Secure cookie / https URL checked
  only when `app.env === production`.

### ISR-015 — CI / SF-001 — **FAIL**

**Working-tree YAML (not on `HEAD`):** third-party actions referenced by
commit SHA (checkout, setup-node, setup-php, trivy, gitleaks, semgrep, sbom,
docker, cosign). `ignore-unfixed: true` is gone. CODEOWNERS names the real
workflow files. Trivy High/Critical `exit-code: 1` on fs and image scans.

**Execution:**

- GitHub Actions API (`mahmoudemad68/clinical_system`): **total_count = 1**.
- Run [32997555916](https://github.com/mahmoudemad68/clinical_system/actions/runs/32997555916):
  `post-merge` on `574f16db1048`, **failure**.
  - `Build and sign images (core-api)`: **Scan the built image failed**.
  - `ai-service` image job: success.
  - Deploy staging / promote: skipped.
- **No `pull-request` run exists.** The working-tree pin/scan changes have
  never executed.
- Staging job still `exit 1` by design when it would run.
- CODEOWNERS `@clinic/...` teams still do not exist as an enforceable
  GitHub control.

**SF-001:** lockfile still has `extract-zip@2.0.1` and
`@electron-internal/extract-zip@1.0.5`. Recorded merge exception until
2026-11-26T00:00:00Z is implementer-owned; **promotion remains blocked**.
Independence for that exception is still lost.

### ISR-016 — Threat models — **FAIL**

`docs/threat-models/phase-00-foundation.md` still:

- titles “seven trust boundaries” (Phase 00 names the Electron
  renderer→main/OS boundary as an eighth);
- states reporter “currently sees every table” and that Phase 00 has no
  personal data (false after Phase 01 and after the reporter-view work);
- says “Nothing is cached in Phase 00” while auth rate limits use cache;
- claims SBOM/CI controls that the single failed post-merge run does not
  support.

Phase 01 delta was lightly updated and still is not a complete threat
register (assets, preconditions, verification, residual owners/expiry).

### ISR-017 — Closed schemas / National ID — **PARTIAL**

- `ClosedJsonValidator` rejects unknown JSON keys on auth API routes. Pest:
  extra `admin: true` on login is 422.
- Inertia `/login` still uses `$request->validate` only (no closed JSON).
- ADR 0014 records that check-digit arithmetic is **not** implemented;
  `NationalId` has no modulus. Legal/statutory decision remains human-owned.
  This is the correct engineering constraint; it does **not** close the
  Phase 01 “one reviewed function including check-digit” product claim
  without that decision.

## 4. Gate reassessment

### G-08-04 — Threat model and data classification have security/privacy approval — **FAIL**

Phase 00 exit: “Threat model and data classification have security/privacy
approval.”

- Threat model is still factually stale (ISR-016 **FAIL**).
- Inventory is still incomplete (ISR-013 **PARTIAL**), with blank lawful
  basis and no deletion procedures.
- Mandatory controls still have High/PARTIAL gaps (ISR-015 **FAIL**, audit
  verifier absent, CI/SBOM unproven).
- Named-owner “acceptance” by the implementer is not independent approval.
  This retest is independent of the remediations and **does not approve**.

Legal/privacy-officer sign-off is a separate question and is **not** granted
here.

### G-01-21 — No Critical or unaccepted exploitable High — **FAIL**

Phase 01 measurable exit: “No Critical or unaccepted exploitable High security
finding remains; all lower findings have owners and due dates.”

- **Critical:** still 0 in this retest.
- **Unaccepted High:** ISR-015 remains a High control-plane/supply-chain
  finding (unexecuted PR pipeline; failed image scan; SF-001 High with only a
  non-independent merge exception). Former application Highs ISR-001/003/005/
  006/008 are **PARTIAL**, not closed.
- Lower findings do not all have independent owners and due dates (single
  named owner).

## 5. Remaining blockers outside a clean ISR-001–017 PASS set

These were asked for explicitly. None can be converted to PASS from missing
runs.

| Blocker | Status | Evidence |
| --- | --- | --- |
| CI execution | **Blocked** | No `pull-request` run. Only post-merge run failed Trivy image scan at `574f16d`. Working-tree workflow pins have never run. |
| SF-001 `extract-zip` | **Open High** | `2.0.1` and `@electron-internal/extract-zip@1.0.5` in the lockfile. Merge exception to 2026-11-26; promotion blocked. |
| Electron OS matrix / G-02-10 | **OPEN** | 74 source tests pass. No WebdriverIO installed-package suite. Existing Linux ASARs are not SHA-bound and still contain `file://`. |
| Flutter OS matrix | **OPEN** | Envelope tests exist; Flutter SDK absent here; no Android/iOS backup/restore/keystore evidence. |
| Reverb SLO (G-01-16) | **OPEN** | Redis publish is not a socket close. No latency measurement under load/restart. |
| k6 abuse harness (G-01-20) | **OPEN** | Script present; `k6` not installed; no refresh assertions; not executed. |
| Two-connection races (G-01-12) | **OPEN** | Sequential unique-index/reuse tests only. No pcntl/two-session harness for OTP, refresh, grants, or audit append. |
| Production KMS / TLS | **OPEN** | Env strings; `sslmode=prefer`; no KMS policy, mTLS, or encrypted backup restore. |

Also still open from the original gate table and not closed by this retest:
G-01-18 Octane alternating authenticated identity; G-06-03 staging smoke
(placeholder `exit 1`); CODEOWNERS teams unenforceable.

## 6. Recommendation

### Technical security/privacy: **DO NOT APPROVE**

The previous **DO NOT APPROVE** recommendation **cannot** be changed to
**APPROVE** or **APPROVE WITH NON-BLOCKING FINDINGS**.

Reasons, in order:

1. **G-08-04 FAIL.** Threat-model and classification artifacts are still
   inaccurate/incomplete; independent security/privacy approval is not given.
2. **G-01-21 FAIL.** SF-001 remains an unfixed High with a non-independent
   merge-only exception. ISR-015 is unexecuted/failed CI. Several former Highs
   are only PARTIAL.
3. Material Phase 01 exit items remain OPEN: Reverb disconnect SLO, k6,
   two-connection races, packaged Electron and Flutter OS matrices, production
   KMS/TLS.
4. Remediations are **not on `HEAD`**. Even a future commit would need a
   fresh independent retest against that immutable SHA, with PR CI green
   (and deliberately failing fixtures), not this dirty tree.

What **did** change relative to the first review: the original refresh
logout/reuse/oracle Highs, the reporter-read of identity tables **on a
migrated test database**, MFA CSRF exemption via `client_class`, and
unkeyed password fingerprints are **not** the same defects they were. That
is progress. It is not phase closure.

### Legal/compliance: **NO APPROVAL PROVIDED**

Qualified Egyptian privacy/legal, clinical, pharmacy, and records-retention
reviewers must issue their own written decisions. Engineering mappings,
ADR 0014, and this retest do not substitute for that.

No product code was changed during this retest. The only new artifact is
this evidence file.
