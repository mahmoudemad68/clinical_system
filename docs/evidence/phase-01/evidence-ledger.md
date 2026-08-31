# Phase 01 — Evidence ledger

One row per mandatory gate. Result-column vocabulary is only `PASS`,
`PARTIAL`, `BLOCKED`, or `OPEN` (the gate counter parses those tokens).
Remaining work that is not a repository-technical defect is named in the
residual cell as `EXTERNAL_HUMAN`, `OPERATIONAL_FOLLOW_THROUGH`, or
`FUTURE_PHASE`.

**Repository technical status:** `PASS`  
**Overall phase status:** `OPEN` — G-01-21 only. `extract-zip@2.0.1` remains
an unaccepted High (SF-001). The repository merge-only exception does **not**
constitute independent acceptance. Green CI does not change this. This is not
unfinished product implementation.

**Never in this ledger:** credentials, national IDs, medical content, raw
prompts, object keys, or exploit payloads.

- **Environment:** local implementation; PostgreSQL tests when Compose is up
- **Recorded:** 2026-08-26; independent matrix reconciliation 2026-08-31
- **Current exact-SHA PR CI:** SHA `8830a659ecf102b9672c274e83f4ed24e9eb5588`, GitHub Actions run [33408315440](https://github.com/mahmoudemad68/clinical_system/actions/runs/33408315440) `SUCCESS`

**Independence:** assessor/remediator separation is lost (Mahmoud holds named
owner roles). Independent security/privacy/legal approval cannot be
self-granted. Phase 22 remains the assurance phase. Current AI/agent reviews
are not human approval.

**Gate totals — 23 gates: 22 PASS, 0 PARTIAL, 0 BLOCKED, 1 OPEN.**

Reproduce with
`node scripts/evidence/count-gates.mjs --ledger docs/evidence/phase-01/evidence-ledger.md --expect PASS=22,PARTIAL=0,BLOCKED=0,OPEN=1`.
Historical dated logs below remain as evidence from their original SHA/date;
they do not override current gate rows.

## Dependency on Phase 00

**Phase 00 repository technical status: `PASS`.**  
**Phase 00 overall status: `OPEN`** because of external/human/operational
items (G-08-04 independent security/privacy approval is `EXTERNAL_HUMAN` and
cannot be self-granted). See `docs/evidence/phase-00/evidence-ledger.md`.

Phase 00 repository implementation is **not** technically incomplete. Contracts,
correlation IDs, idempotency, outbox, and redaction are consumable. Packaged
Electron E2E (G-02-10) is `PASS` on Ubuntu, Windows, and macOS (historical first
close `33155677159` / SHA `4a98fac6538546b52f6eff0c5ef98a9608714b90`; current
exact SHA `8830a659ecf102b9672c274e83f4ed24e9eb5588` / run `33408315440`
`SUCCESS`). Remaining OS encryption cells are Phase 05 enablement, not a Phase
00 technical gap. SF-001 High `extract-zip@2.0.1` remains unaccepted
(`EXTERNAL_HUMAN` / G-01-21). Those residuals do not mean Phase 00 repository
technical work is unfinished.

## Gates

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-01-01 | Auth/Identity/Access modules and public ports | laravel | `apps/core-api/Modules/{Auth,Identity,Access,Audit}`; `POST /api/v1/auth/mfa/totp/enroll`; `POST /api/v1/auth/mfa/totp/confirm` | `PASS` | TOTP enrol HTTP exists. Patient registry/profile implementation belongs Phase 02 (`FUTURE_PHASE`). First admin still uses `identity:bootstrap-admin`. |
| G-01-02 | Identity schema + constraints | postgresql | `2026_08_26_200000_create_identity_and_access_tables.php` applied via Pest `RefreshDatabase` on `clinic_test` | `PASS` | Unique phone/NID HMACs, active grant unique index, audit `REVOKE UPDATE/DELETE` are in repository schema/tests. Production DBA review of live grants is `OPERATIONAL_FOLLOW_THROUGH`. |
| G-01-03 | Assurance + claim ADR | architecture | [ADR 0011](../../adr/0011-identity-assurance-and-profile-claim.md) | `PASS` | Claim/recovery stay gated. Owner approval for production feature enablement remains `EXTERNAL_HUMAN`. |
| G-01-04 | TOTP package ADR + lock | architecture | [ADR 0012](../../adr/0012-totp-verifier-package.md); `spomky-labs/otphp` 11.5.0 | `PASS` | — |
| G-01-05 | Key management ADR | architecture | [ADR 0013](../../adr/0013-identity-key-management.md) | `PASS` | Phase 01 local/test key-management requirement is met. Production KMS is Phase 23 (`FUTURE_PHASE` / `OPERATIONAL_FOLLOW_THROUGH`). Lack of production KMS is not a current technical defect. |
| G-01-06 | OpenAPI + events + generated clients | contracts | `npm run contracts:verify` (2026-08-26); `npm run contracts:breaking` vs `origin/main` | `PASS` | Lint, **12 event schemas**, TS/Dart generate succeeded. Breaking detector reported no breaks vs `origin/main` (additive `/auth/*`, `/me`, `access.grant_*`). |
| G-01-07 | Registration → OTP → restricted session | test | `AuthenticationFlowsTest` on FrankenPHP + PostgreSQL | `PASS` | Restricted pending session; password change 404. |
| G-01-08 | Privileged TOTP + replay | test | same | `PASS` | Replay of the MFA step is rejected. |
| G-01-09 | Refresh reuse revokes family | test | same | `PASS` | Revoke commits before the 401; rotated token is then denied. |
| G-01-10 | Enumeration-safe login | test | same | `PASS` | Unknown and wrong password share `UNAUTHENTICATED`. |
| G-01-11 | Default-deny / no clinical capability | test | `IdentityRulesTest`; capabilities JSON missing `clinical.record.read` | `PASS` | Clinical grant names stay unknown to Access listing. |
| G-01-12 | Concurrent OTP/phone/token/link races | test | [`g-01-12-two-connection-races.md`](g-01-12-two-connection-races.md); `bash scripts/perf/run-two-connection-auth-races.sh` | `PASS` | 40 iterations × two OS processes per scenario; 0 deadlocks/timeouts. Phone uniqueness remains unique-index + sequential HTTP. MFA TOTP HTTP consume not raced (OTP + recovery consume were). This gate does **not** keep Phase 01 overall OPEN. |
| G-01-13 | Cookie CSRF admin flow | laravel | `ValidateCookieCsrf`; Inertia `/login`; `apps/admin-web` LoginPanel; `tests/e2e/csrf-session.spec.ts`; CI step "Browser CSRF and session cookies" | `PASS` | Device OTP/login skips CSRF; admin cookie login without CSRF is 401. Current Core API CI includes Browser CSRF and session-cookie Playwright. |
| G-01-14 | Flutter secure token store | flutter | `packages/flutter/authentication` `flutter test`; `packages/flutter/secure_storage` `flutter test` | `PASS` | Envelope write/clear, fail-closed vault write, `AuthOutcome.withoutSecrets`, and Android/iOS backup-exclusion option tests exist. Android/iOS OS backup/keystore ceremony remains `OPERATIONAL_FOLLOW_THROUGH` / Phase 22. Not a current Phase 01 implementation gap. |
| G-01-15 | Electron main-process credentials | electron | `apps/{doctor,pharmacy}-desktop/src/main/device-credentials.ts`; `npm run desktop:test`; G-02-10 packaged matrix | `PASS` | Main-process credential ownership (`safeStorage` vault) plus packaged Electron matrix on Ubuntu, Windows, and macOS. Signing/notarization remain Phase 23 (`FUTURE_PHASE`). |
| G-01-16 | Session revoke vs realtime SLO | realtime | [`g-01-16-reverb-disconnect-slo.md`](g-01-16-reverb-disconnect-slo.md); `bash scripts/perf/run-reverb-disconnect-slo.sh` | `PASS` | Local live Reverb: 100 samples, max 0.131s / p99 0.045s vs 5s SLO, 0 timeouts. HTTP deny remains authoritative. Not a production SLO proof (`OPERATIONAL_FOLLOW_THROUGH`). |
| G-01-17 | Redaction of identity canaries | security | OTP outbox payload test; `RedactionCanaryTest`; `ExportRedactionTest`; `SinkCanaryTest`; `TelescopeGateTest`; `SentryBeforeSend` | `PASS` | Outbox payload omits phone/NID/code. Telescope is local-only. Sentry `beforeSend` strips bodies/cookies/headers. Collector-export proof remains Phase 22 / `OPERATIONAL_FOLLOW_THROUGH`. |
| G-01-18 | Octane alternating identity | test | [`g-01-18-octane-alternating-identity.md`](g-01-18-octane-alternating-identity.md); `bash scripts/perf/run-octane-alternating-identity.sh` | `PASS` | Live FrankenPHP Octane `--workers=1` from exact SHA `d27e2b3c1d74319b1f6f404bc0ab8c236749f780`: 50 alternating iterations + 20 concurrent paired GET `/me` (244 authenticated GETs), 0 leakage failures, worker PID 2401 reused. Not a production SLO proof. |
| G-01-19 | Threat model + inventory + runbooks + alerts | security | `docs/threat-models/phase-01-identity.md`; `docs/threat-models/phase-01-entry-points.md`; inventory; identity runbooks; `infra/monitoring/alerts/platform.yaml` | `PASS` | ISR-016 repository threat-model completeness is closed (HMAC lifecycle, audited decrypt, erasure, named flags, FCM, DFDs, 27 HTTP + 16 non-HTTP entry points). Independent workshop/sign-off remains `EXTERNAL_HUMAN` and is Phase 00 G-08-04, not this gate. |
| G-01-20 | k6 abuse harness | test | [`g-01-20-k6-auth-abuse.md`](g-01-20-k6-auth-abuse.md); `bash scripts/perf/run-k6-auth-abuse.sh` | `PASS` | Live dual FrankenPHP processes, Redis `ratelimit` DB 3. Shared 429 across processes; 429s on login/OTP request/resend/verify/refresh/MFA/recovery; below-threshold 401/200 with zero 429s; Retry-After present; 0 5xx; no canaries. Not production capacity proof. |
| G-01-21 | No Critical/unaccepted High | security | [SF-001.json](../../../infra/security/exceptions/SF-001.json); [independent-phase-00-phase-01-review-2026-08-26.md](../security-review/independent-phase-00-phase-01-review-2026-08-26.md) | `OPEN` | `extract-zip@2.0.1` remains High. SF-001 exception is `MERGE_ONLY`, `promotion_allowed=false`, `independent_acceptance_status=PENDING_INDEPENDENT_ACCEPTANCE`. Independent acceptance is `EXTERNAL_HUMAN`. The merge-only exception is not independent acceptance. Green CI (run `33408315440` `SUCCESS`) does not change this. Keep separate from repository mechanism `PASS` (Phase 00 G-06-05). Current AI/agent reviews are not human approval. Assessor/remediator separation is lost. |
| G-01-22 | Profile claim never discloses | product/privacy | flag default false; phpunit claim false; OTP purpose `profile_claim` → 404 | `PASS` | Production enablement remains `EXTERNAL_HUMAN`. |
| G-01-23 | Pint, PHPStan, deptrac, Pest, contracts | laravel/test | GitHub Actions run [33408315440](https://github.com/mahmoudemad68/clinical_system/actions/runs/33408315440) on SHA `8830a659ecf102b9672c274e83f4ed24e9eb5588` | `PASS` | Exact-SHA PR CI `SUCCESS`. Core API job includes Pint, PHPStan, Deptrac, Pest, and Browser CSRF/session-cookie Playwright. Contracts job includes OpenAPI lint, event schemas, TS/Dart generation. Historical local 2026-08-26 command log remains below; it does not override this CI record. |

## National ID / ISR-017

**Repository technical: `PASS`.** Current behavior is structural National ID
validation only (`NationalId`: length, century, encoded calendar date,
governorate allow-list, Unicode digit canonicalization). There is **no**
authoritative check-digit/modulus implementation and **no** false check-digit
claim ([ADR 0014](../../adr/0014-national-id-check-digit-deferred.md)).

Remaining: authoritative Egyptian specification / accountable identity/legal
decision = `EXTERNAL_HUMAN`. This is **not** a `PARTIAL` technical gate. Do not
invent a checksum.

## Flags that must stay off outside approved tests

- `FEATURE_IDENTITY_PROFILE_CLAIM` false except an explicit future approval
- `FEATURE_AUTH_RECOVERY` true in phpunit only; local.env false
- `FEATURE_AUTH_REGISTRATION` local true, production always false until rollout

## Verification log (2026-08-31, independent matrix reconciliation)

Documentation/evidence only. No product-code change.

| Item | Result |
| --- | --- |
| Exact SHA | `8830a659ecf102b9672c274e83f4ed24e9eb5588` |
| PR workflow | [33408315440](https://github.com/mahmoudemad68/clinical_system/actions/runs/33408315440) `SUCCESS` |
| Gate counter | `PASS=22,PARTIAL=0,BLOCKED=0,OPEN=1` |
| G-01-21 | remains `OPEN` / `EXTERNAL_HUMAN` (SF-001 unaccepted High) |
| SF-001 | not accepted; `MERGE_ONLY`; `promotion_allowed=false`; `PENDING_INDEPENDENT_ACCEPTANCE` |

**Repository technical status: `PASS`.**  
**Overall phase status: `OPEN`** (G-01-21 only). Not unfinished product
implementation.

The dated logs below are **historical evidence** from their original SHA/date.
They do not override the current gate rows or the 2026-08-31 CI record.

## Verification log (2026-08-26, local) — historical

Commands and results from this implementation session. Host PHP has no `pdo_pgsql`; feature tests ran in `dunglas/frankenphp:1-php8.3` on `clinic_default` with `DB_HOST=postgres`.

| Command | Result |
| --- | --- |
| `vendor/bin/pint --dirty` | passed |
| `vendor/bin/phpstan analyse --memory-limit=1G` | 0 errors |
| `vendor/bin/deptrac analyse --fail-on-uncovered --report-uncovered` | 0 violations, 0 uncovered, 1310 allowed |
| Docker `./vendor/bin/pest` (`dunglas/frankenphp:1-php8.3`, `DB_HOST=postgres`) | **235 passed**, 1 skipped (MinIO), 0 failed |
| `npm run contracts:verify` | OpenAPI valid; **12 event schemas**; TS/Dart generated |
| `npm run contracts:breaking` | no breaking changes against `origin/main` |
| `npm run desktop:test` | doctor 37 passed, pharmacy 37 passed |
| `npm run admin:typecheck` | tsc clean |
| `npm run admin:test` | 5 passed |
| `flutter test` in `packages/flutter/authentication` | 3 passed |
| `flutter test` in `packages/flutter/secure_storage` | 3 passed |

## Verification log (2026-08-27, G-01-16) — historical

| Command | Result |
| --- | --- |
| `bash scripts/perf/run-reverb-disconnect-slo.sh` | Pest `--group=reverb-slo` **2 passed** (sibling isolation + 100 live revoke→WS-close samples). p50 0.025804s, p95 0.036743s, p99 0.044737s, max 0.131135s, timeouts 0 vs 5s SLO. |
| Docker `./vendor/bin/pest tests/Unit/Auth/DisconnectRevokedReverbSessionsTest.php` | **2 passed** |
| `vendor/bin/pint --dirty` | passed |

Historical note from this dated log: Phase 01 remains OPEN. G-01-21 stays OPEN.

## Verification log (2026-08-27, G-01-12) — historical

| Command | Result |
| --- | --- |
| `bash scripts/perf/run-two-connection-auth-races.sh` | Pest `--group=two-connection-race` **5 passed**, 815 assertions, 171.34s. 40 iterations each: dual refresh, refresh vs logout, rotated reuse vs in-flight successor, OTP consume + wrong-code attempts, recovery consume. 0 failures, 0 deadlocks, 0 timeouts. |
| `vendor/bin/pint --dirty` | passed |

## Verification log (2026-08-27, G-01-20) — historical

| Command | Result |
| --- | --- |
| Docker `./vendor/bin/pest tests/Feature/Auth/AuthHttpRateLimitTest.php tests/Feature/Auth/RedisRateLimitTest.php` | **5 passed** (29 assertions) |
| Docker `./vendor/bin/pest tests/Feature/Auth/AuthenticationFlowsTest.php` | **22 passed** (143 assertions) |
| `vendor/bin/pint --dirty` | passed |
| `vendor/bin/phpstan analyse` (touched Auth limiter files) | 0 errors |
| `bash scripts/perf/run-k6-auth-abuse.sh` | Live dual-process API, Redis DB 3. k6 v2.2.0, 292 req, 0 dropped, 0 5xx, 0 below-threshold 429s, 429s on all 8 abuse scenarios, Retry-After present, privacy scan clean. **PASS**. |

Historical note from this dated log: Phase 01 remains OPEN. G-01-21 stays OPEN.

## Verification log (2026-08-28, G-01-18) — historical

| Command | Result |
| --- | --- |
| `bash scripts/perf/run-octane-alternating-identity.sh` | Live FrankenPHP Octane `--workers=1 --max-requests=10000`. Pest `--group=octane-isolation` against the live server (not kernel HTTP). 50 alternating iterations + 20 concurrent paired GET `/api/v1/me`, 244 authenticated GETs, 0 leakage failures, one reused worker PID. **PASS**. |
| `vendor/bin/pint --dirty` | passed |

## Verification log (2026-08-28, G-01-18 exact-SHA rebind) — historical

Measured from a clean detached worktree at `d27e2b3c1d74319b1f6f404bc0ab8c236749f780` (`git status`: working tree clean). Not the dirty primary worktree.

| Command | Result |
| --- | --- |
| `bash scripts/perf/run-octane-alternating-identity.sh` | `candidate_sha` `d27e2b3c1d74319b1f6f404bc0ab8c236749f780`. FrankenPHP Octane `--workers=1`, worker PID **2401** reused across 244 authenticated GETs. 50 alternating iterations + 20 concurrent paired GET `/me`. 0 leakage failures. **PASS**. |

Historical note from this dated log: Phase 01 remains OPEN. G-01-21 stays OPEN.

## EXTERNAL_HUMAN residual

These do **not** make repository technical status `PARTIAL`.

| Item | Gate | Residual |
| --- | --- | --- |
| Independent High risk acceptance or upstream remediation | G-01-21 / SF-001 | `extract-zip@2.0.1` High. `MERGE_ONLY`. `promotion_allowed=false`. `PENDING_INDEPENDENT_ACCEPTANCE`. |
| Independent security/privacy approval | Phase 00 G-08-04 | Cannot be self-granted. Current AI/agent reviews are not human approval. |
| Real `@clinic/*` GitHub teams / ruleset enforcement | Phase 00 G-01-04 | CODEOWNERS file exists; teams/ruleset enforcement do not. |
| Lawful basis / statutory retention / PDPL | Phase 00 G-05-02 | Inventory and erasure are technical. Legal decisions remain human. |
| National ID check-digit specification | ISR-017 / ADR 0014 | Structural validation only. Authoritative Egyptian checksum is deferred. |
| Production feature enablement | G-01-03 / G-01-22 | Recovery / profile claim / registration where accountable owner approval is required. |

## OPERATIONAL_FOLLOW_THROUGH residual

Do **not** claim these ceremonies executed.

- Staging deployment
- Production DBA review
- Production TLS / KMS
- Live provenance / promotion
- Collector-export redaction (Phase 22)
- Production Reverb SLO
- Flutter OS backup/keystore ceremony
- FCM remote invalidation
- Signing/notarization
- Encrypted backup restore
- Future local-encryption OS matrix when Phase 05 activates local clinical DB

## FUTURE_PHASE residual

- **Phase 02:** patient/profile registry work
- **Phase 05:** local encrypted clinical DB adoption after the remaining OS encryption matrix
- **Phase 21:** load/performance/resilience
- **Phase 22:** assembled security/privacy assurance and relevant ceremonies
- **Phase 23:** production KMS, signing/notarization, promotion, backup restore/release readiness

## Honest summary

**Repository technical status: PASS.**  
**Overall phase status: OPEN** (G-01-21 only).

**23 gates: 22 PASS, 0 PARTIAL, 0 BLOCKED, 1 OPEN.**

Phase 01 **repository technical** work is complete. Phase 01 is **not CLOSED
overall** because G-01-21 is OPEN (`EXTERNAL_HUMAN` independent acceptance of
SF-001). Overall OPEN does **not** imply unfinished product implementation.
