# Independent retest — Phase 00 / Phase 01 (ISR-001 through ISR-017)

**Retest date:** 2026-08-27  
**Technical recommendation:** **DO NOT APPROVE**  
**G-08-04:** **FAIL**  
**G-01-21:** **FAIL**  
**Legal / PDPC / clinical / pharmacy approval:** **NOT PROVIDED** (external; not in scope of this technical assessment)

This is an independent technical retest of ISR-001 through ISR-017 from
[independent-phase-00-phase-01-review-2026-08-26.md](independent-phase-00-phase-01-review-2026-08-26.md).
The 2026-08-26 retest and the remediator note are used only as a checklist.
Remediation claims, ledgers, and PASS labels were not trusted without
reproduction.

No product code, tests, ledgers, configuration, or gate statuses were modified
in this review. This file is a new dated report; prior reviews were not
overwritten.

This is **not legal advice** and does not grant legal, regulatory, clinical,
pharmacy, privacy-officer, PDPC, or statutory approval.

## 1. Reviewed commit

| Item | Value |
| --- | --- |
| Reviewed `HEAD` | `11e876d04134efce4fe1534dad30d6f0331291ed` |
| Commit date | 2026-08-27 01:49:55 +0300 |
| Subject | Update Deptrac cache to reflect changes in class and interface references… |
| Branch | `isr-001-017-retest-remediation` |
| Working tree | **clean** (`git status --porcelain` empty) |
| On `origin`? | **No.** `git branch -r --contains HEAD` empty. GitHub API: `No commit found for SHA`. |
| `origin/main` | `574f16db10484817b8892562c0c6e6ec08a8b00a` |
| Local `main` | `0462dbf` (ahead of `origin/main`) |

Uncommitted working-tree changes were not used as evidence. The reviewed
candidate is this commit only. It has not been pushed, so it cannot have a
GitHub Actions run.

Commits after the original review SHA `574f16d` (local only): `0462dbf`,
`c3341a2`, `b029f49`, `11e876d`.

## 2. Result summary

| ID | Original severity | Retest | Blocks Phase 00 / 01 closure? |
| --- | --- | --- | --- |
| ISR-001 | High | **PARTIAL** | Original reporter/worker identity exploit is denied on live `clinic` and `clinic_test`. Runtime worker/reporter connections still unused. Does **not** independently fail G-01-21 as an exploitable High. Still blocks a strict G-04-01 least-privilege close. |
| ISR-002 | High | **PASS** | Original unlink / N-2 / logout-after-refresh High is closed. Residual: no dedicated absolute-lifetime test; HTTP two-connection races are not a full G-01-12 proof. |
| ISR-003 | High | **PARTIAL** | Cookie/CSRF High is closed in code and Pest. Browser Playwright was **not** independently executed (no Playwright install; API not serving). Blocks G-01-13 packaged/browser evidence. |
| ISR-004 | High | **PASS** | Secret-stripping hasher and refresh envelope reproduced. |
| ISR-005 | High | **PASS** | Auth URI ignore, boot tap assert, Sentry `before_send`, and sink canary Pest reproduced. Residual: not a collector-export proof. |
| ISR-006 | High | **PARTIAL** | Redis `ratelimit` store Pest passed. Independent k6 against a live process was **not** reproduced (API did not stay up). Blocks G-01-20. |
| ISR-007 | Medium | **PARTIAL** | Cooling-off, MFA enroll/confirm, bootstrap `--confirm` exist. Operator apply HTTP and recovery-code abuse matrix are thin. Does not fail G-01-21. |
| ISR-008 | High | **PARTIAL** | App table INSERT denied; trigger + `CHECK (chain_sequence > 0)` + verifier Pest passed. DEFINER still trusts PHP hashes; `clinic_audit_writer` still has table INSERT. Residual Medium, not the original app-INSERT High. |
| ISR-009 | Medium | **PASS** | Initiator `ActorContext`, AAL2, resource A≠B, disable tests reproduced. |
| ISR-010 | Medium | **PARTIAL** | HTTP deny + broadcasting/auth Pest passed. Reverb port closed; socket-close SLO **not measured**. **Blocks G-01-16.** |
| ISR-011 | Medium | **PARTIAL** | Linux ASAR custom origin + 38+38 Vitest. No Windows/macOS/WebdriverIO installed-package matrix. **Blocks G-02-10.** |
| ISR-012 | Medium | **PARTIAL** | Flutter package tests 4 passed. Android/iOS backup/keystore **not_run**. Blocks G-01-14 OS evidence. |
| ISR-013 | Medium | **PARTIAL** | Inventory expanded but missing live tables; `lawful_basis=OPEN_LEGAL_DECISION`; no subject-erasure. **Blocks G-08-04.** |
| ISR-014 | Medium | **PARTIAL** | Dual HMAC lookup and production SSL fail-closed config exist. Live production KMS/TLS **not measured**. Do not close production-key gates. |
| ISR-015 | High | **FAIL** | Reviewed SHA is absent from GitHub. Zero Actions runs for this SHA. SF-001 `extract-zip@2.0.1` still High. Local Trivy is not a CI gate. **Blocks G-01-21 and G-01-23.** |
| ISR-016 | Medium | **PARTIAL** | Eight-boundary rewrite is largely accurate. Overclaims worker runtime credential and CI-on-branch evidence. Engineering draft; this review does not approve it. **Blocks G-08-04.** |
| ISR-017 | Low | **PARTIAL** | Closed JSON reproduced. National ID check-digit remains a legal decision (ADR 0014). Legal residual is external. |

**High findings that still independently fail G-01-21:** ISR-015 (unexecuted CI
on the reviewed commit plus unfixed SF-001). Former application Highs
ISR-001/002/003/004/005/006/008 are no longer the original exploits on this
SHA, but several remain PARTIAL because required runtime/CI evidence is
missing.

## 3. Per-finding evidence

### ISR-001 — Database roles / least privilege — **PARTIAL**

**Reproduced** (psql as each role on Compose `clinic-postgres-1`, 2026-08-27):

Both `clinic` and `clinic_test` have migrations
`2026_08_26_210000`, `220000`, and `230000` applied.

| Probe | `clinic` | `clinic_test` |
| --- | --- | --- |
| `clinic_reporter` `SELECT` `users` | permission denied | permission denied |
| `clinic_reporter` `SELECT` `reporting.account_status_counts` | allowed | allowed |
| `clinic_worker` `UPDATE users` | permission denied | permission denied |
| `clinic_worker` `UPDATE jobs` | allowed (`UPDATE 0`) | allowed |
| `clinic_app` `INSERT audit_events` | permission denied | permission denied |
| `clinic_app` `EXECUTE clinic_append_audit_event(...)` | allowed | allowed |
| `clinic_backup` `SELECT users` | true | true |
| `clinic_backup` `INSERT users` | permission denied | permission denied |
| `audit_events` UPDATE/DELETE trigger | `audit_events_no_update_delete` enabled | same |
| `CHECK (chain_sequence > 0)` | present | present |

`has_table_privilege` matrix (same on both databases): reporter has no
SELECT/DML on identity tables; worker has no DML on `users` / grants /
`audit_events`; worker retains SELECT+UPDATE on `otp_requests` and DML on
`jobs`/`outbox_events`; app has no audit INSERT/UPDATE/DELETE.

Pest `PostgresPrivilegeTest`: **5 passed** (FrankenPHP image `clinic-php-pgsql:local`,
`pdo_pgsql`, `clinic_test`).

**Affected:** `infra/docker/postgres/initdb/01-roles-and-extensions.sql`;
migrations `210000`/`220000`/`230000`; `config/database.php`;
`AuditServiceProvider` (non-testing uses `pgsql_audit`).

**Remaining risk:**

- Laravel default `ConnectionInterface` is still `clinic_app`. `pgsql_worker`
  and `pgsql_reporter` are configured but **not selected** by Horizon/outbox
  consumers. A queue process using `DB_USERNAME=clinic_app` still has full
  identity DML. The narrowed `clinic_worker` role is unused at runtime.
- Worker **SELECT** on `otp_requests` includes unused `code_ciphertext`.
- `clinic_audit_writer` still has table **INSERT** on `audit_events`.
- Pest privilege tests run as `clinic_migrator` and query
  `has_table_privilege`; they do not run the HTTP stack as `clinic_app`.

**Closure:** does not independently fail G-01-21 as the original reporter/worker
High. Still blocks a strict Phase 00 G-04-01 close until serving processes
actually use the named roles.

### ISR-002 — Refresh / logout / reuse / absolute lifetime — **PASS**

**Reproduced** (Pest `AuthenticationFlowsTest` refresh group, 22 auth tests
passed in the same FrankenPHP run):

- Rotated refresh reuse → 401 and family revoked.
- Lost-response replay with the same idempotency key returns the same refresh
  token (device envelope, not `idempotency_keys`).
- Consumed generation (N-2) → 401.
- Logout then refresh → unauthorized.
- `ResolveActorContext::fromAccessToken` requires a live
  `findActiveSessionByDevice` row (no `sessionId=null` fallback).
- `RefreshDeviceSessionHandler` writes `auth_refresh_consumptions`, caps
  `refresh_expires_at` to `absolute_expires_at`, and treats consumed hashes as
  reuse.

`TwoConnectionRaceTest`: duplicate consumed-hash INSERT and duplicate grant
tuple INSERT block on a second connection (3 passed). That is unique-index
serialization, not a concurrent HTTP OTP/refresh proof (G-01-12 remains
PARTIAL as a separate gate).

**Remaining risk:** no Pest case freezes clock past `absolute_expires_at` while
sliding refresh TTL would otherwise extend. Code caps expiry; the original
required test is still missing.

**Closure:** original High is closed. Does not block G-01-21. G-01-12 still
needs broader two-connection HTTP races.

### ISR-003 — Admin cookie / CSRF / client class — **PARTIAL**

**Reproduced in Pest:**

- Inertia `POST /login` without CSRF → **419**.
- API login with a session cookie and no CSRF → **403** `CSRF_MISMATCH`
  (distinct from `UNAUTHENTICATED` via `ExceptionRenderer`).
- `ValidateCookieCsrf`: `hasValidOrigin` always false; bearer exempt;
  admin MFA completion uses stored `mfa_challenges.client_class`, not a
  client-supplied field; session/`XSRF-TOKEN` cookies force CSRF.
- Cookie hash bound after `session()->regenerate()` via
  `bindCookieSessionHash('cookie:'.$laravelSessionId)`.

**Not reproduced this session:** `tests/e2e/csrf-session.spec.ts` (Playwright).
`@playwright/test` is not installed in this workspace. `http://127.0.0.1:8080`
was down. Remediator `playwright-csrf-2026-08-26.txt` was **not** accepted.

**Remaining risk:** cross-browser two-cookie CSRF is specified but unmeasured
here. Device API login without cookies still skips CSRF (intended for Electron
`net.fetch`).

**Closure:** original High (unbound cookie / `client_class` CSRF bypass) is
closed in application tests. Browser E2E still blocks G-01-13 as measured
evidence.

### ISR-004 — Idempotency credential oracle — **PASS**

**Reproduced:**

- `CanonicalRequestHasher` strips `password`, OTP/TOTP/recovery codes, tokens,
  and National ID keys (nested).
- Pest: stored `idempotency_keys.response_reference` does not contain
  access/refresh tokens; OTP outbox payload omits phone, National ID, and
  `otp_code`.
- Refresh lost-response uses encrypted `refresh_replay_ciphertext` on the
  device row; `EnforceIdempotency` strips tokens from stored response refs and
  special-cases `/api/v1/auth/token/refresh`.

**Remaining risk:** non-secret body fields still fingerprint in `request_hash`
(accepted residual).

**Closure:** original High closed. Does not block G-01-21.

### ISR-005 — Telescope / logs / export — **PASS**

**Reproduced:**

- `config/telescope.php` `ignore_paths` includes `api/v1/auth*`.
- `TelescopeServiceProvider` filter drops `/api/v1/auth` URIs and hides
  password/token/NID/cookie headers.
- `AppServiceProvider` fails boot if a log channel lacks `RedactingLogTap`.
- `SinkCanaryTest`: National ID `29901011234567` does not reach the Monolog
  `TestHandler`; Sentry `before_send` strips cookies/data/headers. **2 passed.**

**Remaining risk:** canary is in-process, not a proof that OTel/Sentry SaaS
export is clean. Local `clinic` still has `telescope_*` tables (documented
local-only).

**Closure:** original High closed for application sinks. Collector-export
proof remains Phase 22 telemetry work, not a G-01-21 High.

### ISR-006 — Auth abuse controls / Redis rate limit / k6 — **PARTIAL**

**Reproduced:**

- `AuthenticationRateLimiter` is constructed on named store
  `cache.auth_rate_limiter` (`ratelimit`), Redis DB index 3.
- Hits exist for login, OTP, refresh, MFA, recovery.
- `RedisRateLimitTest`: third login hits `RateLimited` against live Redis.
  **1 passed** (not skipped).
- phpunit.xml still forces `AUTH_RATE_LIMIT_DRIVER=array` except this test.

**Not reproduced:** `tests/k6/auth-abuse.js` on this SHA. Core API was not
listening on `:8080`. A brief `artisan serve` attempt failed closed on missing
identity encryption keys in the container environment; this review did not load
live `.env` secrets to force a boot. Remediator `k6-auth-abuse-2026-08-26.txt`
was **not** accepted.

**Remaining risk:** default Pest suite does not prove Redis; trusted-proxy IP
keying was not dynamically tested; k6/G-01-20 unmeasured.

**Closure:** original “limiter on default cache / no refresh hit” High is
closed in code + Redis Pest. G-01-20 still OPEN without measured k6 on a live
process.

### ISR-007 — Recovery / MFA lifecycle / bootstrap — **PARTIAL**

**Reproduced:**

- Recovery complete with cooling-off → status `cooling_off`, `applied_at` null
  (Pest). Immediate apply when cooling-off is 0 still exists (phpunit default).
- `identity:apply-due-recoveries` scheduled every 15 minutes; cooling-off apply
  uses `operator=null`; `manual_review` requires AAL2 + `auth.recovery.apply`.
- MFA enroll/confirm HTTP with recovery codes array (Pest).
- `identity:bootstrap-admin --confirm --totp-code` exists; step 1 writes a
  private provisioning file and does not mark verified.

**Remaining risk:** no Pest for operator `POST /auth/recovery/requests/{id}/apply`
AAL2 matrix; recovery-code consume vs replay is store-level (`FOR UPDATE`)
without a dedicated HTTP abuse case; bootstrap file hygiene is operator
procedure.

**Closure:** does not fail G-01-21. Does not fully close recovery/MFA lifecycle
evidence.

### ISR-008 — Audit chain — **PARTIAL**

**Reproduced:**

- App cannot `INSERT`/`UPDATE`/`DELETE` `audit_events`.
- Trigger rejects UPDATE/DELETE.
- `CHECK (chain_sequence > 0)` replaced `CHECK (true)`.
- Advisory lock in `PostgresAuditStore`; `audit:verify-chain` command exists.
- Pest verifies chain after an identity write (`ok=true`, `checked>0`).
- Non-testing `AppendAuditEvent` binds `pgsql_audit`.

**Remaining risk:** `clinic_append_audit_event` is `SECURITY DEFINER` and
inserts **caller-supplied** hashes (computed in PHP). A compromised
`clinic_app` that can `EXECUTE` can still forge a syntactically valid chain
row. `clinic_audit_writer` retains table INSERT, so the audit connection can
bypass the function. Two-connection advisory-lock Pest proves lock contention,
not every append race.

**Closure:** original “app INSERT + CHECK(true) + no verifier” High is closed.
Residual forgery via EXECUTE/audit-writer INSERT remains. Does not by itself
fail G-01-21 if treated as Medium residual; still blocks a strict append-only
integrity close.

### ISR-009 — Grant / disable authorization — **PASS**

**Reproduced** (`IdentityAccessPortsTest`, 8 passed):

- Patient cannot grant.
- Stale AAL1 admin cannot grant.
- Operator capability `access.grant.issue` is not grantable
  (`InvalidValueObject`).
- Grant on resource A does not authorize resource B.
- Disable increments credential version; patient disable denied.
- `DefaultDenyAuthorizer` requires resource/context for grantable actions;
  privileged operator set needs admin AAL2.

**Remaining risk:** only `access.context.delegate` is grantable; clinical names
still unknown (intentional). No product UI.

**Closure:** original Medium closed for Phase 01. Does not block G-01-21.

### ISR-010 — Realtime / Reverb revocation — **PARTIAL**

**Reproduced:**

- Pest: after logout, `/api/v1/me` is 401 and `/broadcasting/auth` for
  `private-auth.session.{id}` is 401/403 inside
  `identity.session.revocation_slo_seconds` (HTTP path).
- `routes/channels.php` authorizes the exact live session row.
- `SessionRevokedConsumer` publishes Redis `clinic.session.disconnect` and
  broadcasts `SessionDisconnectedBroadcast`. Comments correctly say HTTP deny
  is authoritative.

**Not reproduced:** Reverb `:8081` **connection refused**. Socket-close SLO is
**NOT_MEASURED**. Remediator `reverb-revoke-slo-2026-08-26.txt`
(`reverb_reachable=no`) was not accepted as a pass.

**Closure:** **blocks G-01-16** and the measurable session-revocation exit
criterion. HTTP deny is not a socket-close SLO.

### ISR-011 — Electron packaged origin — **PARTIAL**

**Reproduced:**

- Source `loadURL` uses `${APP_CONFIG.packagedOrigin}/index.html` when not in
  development (`clinic-doctor-app://-` / `clinic-pharmacy-app://-`).
- Independent ASAR byte inspection (2026-08-27 00:53/00:54 Linux x64 packages):
  doctor ASAR contains `clinic-doctor-app://` and `loadURL`; pharmacy ASAR
  contains `clinic-pharmacy-app://`; both still contain a `file://` string.
  `scripts/desktop/inspect-packaged-asar.mjs` exit 0.
- `npm run desktop:test`: **38 + 38 passed**.

ASARs predate `HEAD` by ~1 hour (Deptrac-only commit). They are **not** a
SHA-bound installed artifact for `11e876d`. No WebdriverIO. No Windows/macOS.

**Closure:** **blocks G-02-10** and G-01-15 packaged evidence. Source+Linux ASAR
do not satisfy installed OS matrix.

### ISR-012 — Flutter token store / OS matrix — **PARTIAL**

**Reproduced:** `/home/mahmoud/sdk/flutter/bin/flutter test` in
`packages/flutter/authentication`: **4 passed** (atomic envelope, fail-closed
write, fail-closed clear, `AuthOutcome` strips tokens). Host devices: Linux
desktop only. Android emulator / iOS simulator / OS backup-restore: **not_run**.

**Closure:** blocks G-01-14 as an OS-matrix gate. Package tests do not prove
keystore/backup behavior.

### ISR-013 — Inventory / retention / deletion — **PARTIAL**

**Reproduced:** Phase 01 tables are inventoried with classification and
`lawful_basis=OPEN_LEGAL_DECISION`. `deletion-and-purge.md` describes OTP
ciphertext NULL-on-consume and `auth:prune-expired`. Consume/invalidate paths
set `code_ciphertext`/`destination_ciphertext` null.

**Gaps vs live `clinic` public tables:** no inventory rows for `jobs`,
`job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`,
`identity_profile_links`, `migrations`. `spatial_ref_sys` (PostGIS) is
unlisted. Subject erasure is explicitly **not** implemented. Audit rows are
append-only with no legal erasure path.

**Closure:** **blocks G-08-04** data-classification completeness. Lawful basis
is an **external legal** item (listed separately; not converted into a
technical PASS).

### ISR-014 — Keys / TLS / KMS — **PARTIAL**

**Reproduced in code/config (not a production measurement):**

- HMAC `lookupDigests` used at login (`phoneLookupHmacs`).
- 32-byte key floor; production `ConfigurationCheck` fails on
  `prefer`/`disable`/`allow` for all pgsql connections, insecure cookies, and
  non-HTTPS `app.url`.
- Local/CI `DB_SSLMODE=prefer` documented. Production default `verify-full`
  when `APP_ENV=production` and sslmode unset.
- `docs/operations/production-kms-tls.md`: KMS **not implemented**.
- `identity:rotate-keys` refuses ciphertext rewrite in production.

**Not reproduced:** staging/production TLS handshake, mTLS, or KMS binding.

**Closure:** do not PASS production TLS/KMS from documentation. Phase 23 owns
KMS. This PARTIAL does not by itself fail G-01-21 if remaining Highs are
elsewhere.

### ISR-015 — CI / SF-001 / Trivy / supply chain — **FAIL**

**GitHub evidence (authoritative for CI):**

| Query | Result |
| --- | --- |
| `GET /repos/mahmoudemad68/clinical_system/commits/11e876d…` | `No commit found` |
| Actions `head_sha=11e876d…` | `total_count: 0` |
| Associated PRs | 422 / no commit |
| Remote branches | only `main:574f16db1048` |
| Only stored Actions run | [post-merge 32997555916](https://github.com/mahmoudemad68/clinical_system/actions/runs/32997555916) on **`574f16d`**, `conclusion=failure`, event `push` |

Local Pest/Vitest/Flutter **must not** satisfy the CI gate.

**SF-001:** `package-lock.json` still has `extract-zip@2.0.1`. Independent
Trivy 0.58.1 filesystem scan with `infra/security/trivy-merge.ignore`:
extract-zip `CVE-2026-56876` is **suppressed**; promotion-fs-scan workflow
correctly omits that ignore file. Merge exception expiry job is coded to
2026-11-26T00:00:00Z. This review **does not independently accept** SF-001 as
closing G-01-21.

Same local merge-ignore scan also reported **1 HIGH remaining**:
`nanoid` `CVE-2026-67213` (scanner listed 3.3.16; committed
`package-lock.json` / `apps/core-api/package-lock.json` pin **3.3.18**). Treat
as an unresolved scanner observation until GitHub Trivy on this SHA is the
record. Image scans use `trivy-image.ignore` (base-image IDs, not extract-zip).

Workflows on this SHA pin Actions by commit SHA (observed in
`.github/workflows/pull-request.yaml`). That is source review only, not
execution.

**Closure:** **FAIL**. Blocks G-01-21, G-01-23, and any claim that High/Critical
container scans passed for this candidate.

### ISR-016 — Threat models — **PARTIAL**

Phase 00 model now names **eight** boundaries, reporter-no-identity-SELECT,
Redis DB 3, Electron packaged origin, SF-001 merge vs promotion, and G-08-04
OPEN. Phase 01 identity delta exists as an engineering draft.

**Inaccuracies vs this retest:**

- Boundary 3 says workers consume as `clinic_worker`. Runtime still uses
  default `clinic_app` unless a separate process sets `DB_WORKER_USERNAME`.
- Boundary 7 says CI evidence is the GitHub run on the remediation branch.
  That run **does not exist** for `11e876d`.

**Closure:** no longer the previous FAIL (seven boundaries / reporter SELECT
every table / nothing cached). Still **not approved**. Blocks G-08-04.

### ISR-017 — Closed JSON / National ID check-digit — **PARTIAL**

**Reproduced:** `ClosedJsonValidator` on auth API controllers; Pest rejects
unknown `admin` on login; Inertia login rejects unknown `admin` with session
errors. `NationalId` has no modulus/check-digit (ADR 0014).

**Remaining:** check-digit policy is **legal/privacy-owned**. Engineering
correctly refused to invent one. That is an external blocker, not a code
defect.

**Closure:** technical closed-JSON control holds. Legal ID policy still open.

## 4. Gate reassessment

### G-08-04 — Threat model and data classification have security/privacy approval — **FAIL**

Phase 00 exit criterion: “Threat model and data classification have
security/privacy approval.”

This independent review **withholds** that approval because:

1. Inventory is incomplete versus the live schema (ISR-013).
2. Threat-model runtime/CI statements are still wrong in places (ISR-016).
3. Mandatory measured controls (CI, Reverb SLO, packaged OS matrix) lack
   evidence.
4. Named-owner concentration (Mahmoud holds every listed owner role) remains a
   process independence problem for *human* sign-off. This agent retest is
   independent of the remediator session; it is not a substitute for a named
   human security/privacy officer.

Legal/privacy statutory approval is **out of scope** and remains OPEN
regardless of this technical FAIL.

### G-01-21 — No Critical or unaccepted exploitable High — **FAIL**

Phase 01: “No Critical or unaccepted exploitable High security finding remains.”

Unaccepted Highs on this candidate:

1. **ISR-015 / SF-001** — `extract-zip@2.0.1` High, no upstream fix, merge-only
   ignore, promotion scan must fail closed. Not independently accepted here.
2. **ISR-015 / CI** — the reviewed commit has **no** GitHub execution. The only
   GitHub run is a **failed** post-merge image scan of a **different** SHA
   (`574f16d`). Unexecuted High/Critical scanning is an unaccepted High
   control-plane gap.

Former application Highs that this retest scored PASS, or PARTIAL with the
original exploit closed, are **not** counted as currently exploitable Highs.

## 5. Remaining technical blockers (implementer)

Do **not** treat this list as authorization to weaken tests or ignore files.

1. **Push `11e876d` (or a successor that includes it) and open a PR.** Obtain a
   GitHub `pull-request` run whose `head_sha` equals the next reviewed commit.
   Green local Pest does not count.
2. **Reconcile Trivy on that GitHub run:** SF-001 still High; confirm whether
   `nanoid` CVE-2026-67213 appears in CI; do not add ignore IDs to
   `trivy-merge.ignore` except a time-boxed, independently reviewed SF-001
   exception. Promotion filesystem scan must stay ignore-free.
3. **Keep promotion blocked** while SF-001 is unfixed. Do not claim G-01-21
   PASS on a merge exception alone.
4. **Measure Reverb revoke-to-socket-close** with Reverb actually listening.
   HTTP 401 after logout is not G-01-16.
5. **Run Playwright `tests/e2e/csrf-session.spec.ts` against a live
   `CLINIC_WEB_BASE_URL`** and retain SHA-bound logs (no secrets).
6. **Run `tests/k6/auth-abuse.js` against a live API** with
   `AUTH_RATE_LIMIT_DRIVER=redis` and synthetic data only.
7. **Wire `pgsql_worker` (and reporter, if used) in the actual worker
   process**, or stop the threat model from saying workers run as
   `clinic_worker`.
8. **Inventory** `jobs`, `failed_jobs`, `job_batches`, `cache`, `cache_locks`,
   `sessions`, `identity_profile_links` (and any other live tables).
9. **Add an absolute-lifetime Pest** (refresh must not extend past
   `absolute_expires_at`).
10. **Packaged Electron OS matrix / WebdriverIO** on SHA-bound artifacts
    (G-02-10). Linux ASAR strings are not enough.
11. **Flutter Android/iOS backup-restore / keystore matrix** (or an explicit
    BLOCKED host limitation that does not convert into PASS).
12. **Do not claim production KMS/TLS done.** Keep Phase 23 ownership.

This assessor will not make those changes.

## 6. Remaining external / human blockers

These are **not** technical closure items this implementer can code away:

- Egyptian legal / PDPC / privacy-officer decisions: lawful basis, retention
  statutes, National ID check-digit (ADR 0014), cross-border AI, subject
  erasure of audit/identity.
- Named human security/privacy approval of G-08-04 (this report withholds
  technical approval; a human officer is still required by the phase file).
- Assessor/remediator/owner concentration on one person.
- Clinical and pharmacy professional sign-off: **not applicable** to Phase
  00/01 identity foundations; do not invent them.
- Staging environment / production promotion (Phase 23). Ledger already fails
  staging deploy closed.

## 7. Final recommendation

**DO NOT APPROVE** Phase 00 G-08-04 or Phase 01 G-01-21.

APPROVE is reserved for a candidate where every mandatory technical closure
condition for those gates has sufficient evidence. This SHA is not on GitHub,
CI has not run, SF-001 remains an unaccepted High, Reverb SLO is unmeasured,
and classification/threat-model approval is withheld.

**APPROVE WITH NON-BLOCKING FINDINGS** is also refused: ISR-015 is blocking.

---

## Appendix A — Commands executed (this retest)

```text
git rev-parse HEAD
# 11e876d04134efce4fe1534dad30d6f0331291ed
git status --porcelain   # empty
curl GitHub commits/actions/pulls/branches for mahmoudemad68/clinical_system
docker exec clinic-postgres-1 psql … privilege matrix on clinic and clinic_test
docker run --network host clinic-php-pgsql:local vendor/bin/pest \
  PostgresPrivilegeTest TwoConnectionRaceTest IdentityAccessPortsTest \
  AuthenticationFlowsTest RedisRateLimitTest SinkCanaryTest
# 41 passed (183 assertions)
npm run desktop:test
# 38 + 38 passed
node scripts/desktop/inspect-packaged-asar.mjs
python3 ASAR byte needles (custom origin / file:// / loadURL)
flutter test packages/flutter/authentication
# 4 passed
docker run aquasec/trivy:0.58.1 fs --include-dev-deps \
  --ignorefile infra/security/trivy-merge.ignore
# extract-zip suppressed; 1 HIGH nanoid reported by scanner
ss/curl :8080 :8081  # down / Reverb refused
```

Not executed independently: Playwright, k6, Reverb SLO, GitHub PR CI on this
SHA, production TLS/KMS, Android/iOS device matrix, Windows/macOS packaged
Electron.
