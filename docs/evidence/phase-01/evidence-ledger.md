# Phase 01 — Evidence ledger

One row per mandatory gate. A gate is `PASS` only when a named artifact or a
reproducible command produced the stated result. Everything else is `PARTIAL`,
`BLOCKED`, or `OPEN`.

**Never in this ledger:** credentials, national IDs, medical content, raw
prompts, object keys, or exploit payloads.

- **Environment:** local implementation; PostgreSQL tests when Compose is up
- **Recorded:** 2026-08-26
- **Status:** Phase 01 is **OPEN**. Do not treat this file as completion.

**Independence:** assessor/remediator separation is lost (Mahmoud holds named
owner roles). Independent security/privacy/legal approval cannot be
self-granted. Phase 22 remains the assurance phase.

## Dependency on Phase 00

Phase 00 is OPEN/PARTIAL (see `docs/evidence/phase-00/evidence-ledger.md`).
Contracts, correlation IDs, idempotency, outbox, and redaction are consumable.
CI never run, packaged Electron E2E (G-02-10) OPEN, G-06-01 Linux-only, SF-001
High `extract-zip` remain. Those do not block local Phase 01 implementation.

## Gates

| Gate | Requirement | Owner | Artifact / command | Result | Residual gap |
| --- | --- | --- | --- | --- | --- |
| G-01-01 | Auth/Identity/Access modules and public ports | laravel | `apps/core-api/app/Modules/{Auth,Identity,Access,Audit}` | `PARTIAL` | Public ports exist, including Access grants and `DisableIdentityCoordinator`. TOTP enrol HTTP is not in the Phase 01 API list; first admin uses `identity:bootstrap-admin`. Patient registry remains a stub until Phase 02. |
| G-01-02 | Identity schema + constraints | postgresql | `2026_08_26_200000_create_identity_and_access_tables.php` applied via Pest `RefreshDatabase` on `clinic_test` | `PARTIAL` | Unique phone/NID HMACs, active grant unique index, audit `REVOKE UPDATE/DELETE`. Independent DBA review of production roles not done. |
| G-01-03 | Assurance + claim ADR | architecture | [ADR 0011](../../adr/0011-identity-assurance-and-profile-claim.md) | `PASS` | Claim/recovery stay gated; production enablement needs owners. |
| G-01-04 | TOTP package ADR + lock | architecture | [ADR 0012](../../adr/0012-totp-verifier-package.md); `spomky-labs/otphp` 11.5.0 | `PASS` | — |
| G-01-05 | Key management ADR | architecture | [ADR 0013](../../adr/0013-identity-key-management.md) | `PARTIAL` | Local keys; production KMS is Phase 23. |
| G-01-06 | OpenAPI + events + generated clients | contracts | `npm run contracts:verify` (2026-08-26); `npm run contracts:breaking` vs `main` | `PASS` | Lint, 10 event schemas, TS/Dart generate succeeded. Breaking detector reported no breaks vs `main` (additive `/auth/*` and `/me`). |
| G-01-07 | Registration → OTP → restricted session | test | `AuthenticationFlowsTest` on FrankenPHP + PostgreSQL | `PASS` | Restricted pending session; password change 404. |
| G-01-08 | Privileged TOTP + replay | test | same | `PASS` | Replay of the MFA step is rejected. |
| G-01-09 | Refresh reuse revokes family | test | same | `PASS` | Revoke commits before the 401; rotated token is then denied. |
| G-01-10 | Enumeration-safe login | test | same | `PASS` | Unknown and wrong password share `UNAUTHENTICATED`. |
| G-01-11 | Default-deny / no clinical capability | test | `IdentityRulesTest`; capabilities JSON missing `clinical.record.read` | `PASS` | Clinical grant names stay unknown to Access listing. |
| G-01-12 | Concurrent OTP/phone/token/link races | test | sequential consume-once + unique grant index | `PARTIAL` | Unique indexes and sequential consume/reuse tests pass. Two-connection/pcntl race harness not run. |
| G-01-13 | Cookie CSRF admin flow | laravel + admin | `ValidateCookieCsrf`; Inertia `/login`; `apps/admin-web` LoginPanel | `PARTIAL` | Device OTP/login skips CSRF; admin cookie login without CSRF is 401. Browser Playwright/E2E not run. |
| G-01-14 | Flutter secure token store | flutter | `packages/flutter/authentication` `flutter test` | `PARTIAL` | Vault write/clear and `AuthOutcome.withoutSecrets` pass. Device OS matrix not run. |
| G-01-15 | Electron main-process credentials | electron | `npm run desktop:test` | `PARTIAL` | Doctor 37 and pharmacy 37 Vitest trust-boundary tests pass. Packaged WebdriverIO remains OPEN (Phase 00 G-02-10). |
| G-01-16 | Session revoke vs realtime SLO | realtime | `SessionRevokedConsumer` metrics; HTTP deny on revoked hashes | `OPEN` | Phase 00 still denies all Reverb subscriptions. No measured socket-close SLO. HTTP refresh/new requests deny. |
| G-01-17 | Redaction of identity canaries | security | OTP outbox payload test; Phase 00 redactor unit tests | `PARTIAL` | Outbox payload omits phone/NID/code. Log/Sentry/Horizon sweep not executed. |
| G-01-18 | Octane alternating identity | test | `OctaneStateIsolationTest`; actor is request-scoped | `PARTIAL` | Phase 00 reset hook passes. No new dual-user authenticated Octane HTTP case. |
| G-01-19 | Threat model + inventory + runbooks + alerts | security | `docs/threat-models/phase-01-identity.md`; inventory; identity runbooks; `infra/monitoring/alerts/platform.yaml` | `PARTIAL` | Engineering draft; not independently reviewed. |
| G-01-20 | k6 abuse harness | test | `tests/k6/auth-abuse.js` | `OPEN` | Script present; k6 not executed this session. |
| G-01-21 | No Critical/unaccepted High | security | — | `OPEN` | Independent review not performed. SF-001 from Phase 00 remains. Assessor/remediator separation is lost. |
| G-01-22 | Profile claim never discloses | product/privacy | flag default false; phpunit claim false; OTP purpose `profile_claim` → 404 | `PASS` (control) | Enablement still requires product/privacy/security/support owners. |
| G-01-23 | Pint, PHPStan, deptrac, Pest, contracts | laravel/test | commands in Verification log | `PARTIAL` | Local commands below. CI workflow has not been executed against this branch. |

## Flags that must stay off outside approved tests

- `FEATURE_IDENTITY_PROFILE_CLAIM` false except an explicit future approval
- `FEATURE_AUTH_RECOVERY` true in phpunit only; local.env false
- `FEATURE_AUTH_REGISTRATION` local true, production always false until rollout

## Verification log (2026-08-26, local)

Commands and results from this implementation session. Host PHP has no `pdo_pgsql`; feature tests ran in `dunglas/frankenphp:1-php8.3` on `clinic_default` with `DB_HOST=postgres`.

| Command | Result |
| --- | --- |
| `vendor/bin/pint --dirty` | passed |
| `vendor/bin/phpstan analyse --memory-limit=1G` (touched handlers/stores) | 0 errors |
| `vendor/bin/deptrac analyse` | 0 violations, 1190 allowed, 7 uncovered |
| Docker `php artisan test` | 225 passed, 1 skipped (MinIO), 0 failed |
| `npm run contracts:verify` | OpenAPI valid; 10 event schemas; TS/Dart generated |
| `npm run contracts:breaking` | no breaking changes against `main` |
| `npm run desktop:test` | doctor 37 passed, pharmacy 37 passed |
| `npm run admin:typecheck` / `admin:test` | tsc clean; 5 passed |
| `flutter test` in `packages/flutter/authentication` | 2 passed |
| `flutter test` in `packages/flutter/secure_storage` | 3 passed |
| `apps/patient-app` backup exclusion test | 1 passed |

## What is still not a phase close

- Independent security/privacy/legal approval (G-01-21, Phase 22)
- Measured Reverb disconnect SLO (G-01-16)
- Two-connection concurrency harness and k6 execution (G-01-12, G-01-20)
- Packaged Electron WebdriverIO (Phase 00 G-02-10)
- CI run of this branch
- TOTP enrollment HTTP/UX beyond bootstrap (not on the Phase 01 route list)
- Profile claim and recovery remain flag-gated; recovery is on only in phpunit
