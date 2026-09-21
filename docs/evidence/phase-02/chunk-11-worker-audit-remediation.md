# Phase 02 chunk 11 — worker audit identity remediation (not phase PASS, not chunk CLOSE)

This remediation fixes the post-merge exploratory QA blocker on GitHub `main`
`a438aba2d141ee41e59318c76b3f956998faf718`:

`verification.upload_completed` reached the outbox worker, `VerificationUploadProcessor`
started, and audit append failed because `clinic_worker` cannot `EXECUTE`
`clinic_append_audit_event`. The upload stayed `scanning` and GUI submit stayed disabled.

It does **not** itself close Chunk 11.
**Chunk 11 remains OPEN** until post-merge exploratory GUI QA re-runs the mandatory Doctor journey.
**Phase 02 remains NOT PASS.**

Do **not** mark READY_TO_MERGE from this note. Independent review decides that.

- **Branch:** `cursor/phase-02-chunk-11-worker-audit-identity-cc7f`
- **Draft PR:** https://github.com/mahmoudemad68/clinical_system/pull/20
- **Baseline (GitHub `main`):** `a438aba2d141ee41e59318c76b3f956998faf718`
- **Implementation HEAD:** `ac44350a6fc983acdf713638a7563aeb60be1c50`
- **Recorded-CI HEAD:** `3f808eb6d693f245e765228d334a570d9158d8fd`
- **GitHub CI:** SUCCESS `pull-request` run [35642597565](https://github.com/mahmoudemad68/clinical_system/actions/runs/35642597565) on `3f808eb6d693f245e765228d334a570d9158d8fd` (9 success, 4 skipped by path filter)
- **Recorded:** 2026-09-21

## QA failure reproduced

Post-merge exploratory QA on the baseline SHA proved:

- strong Electron keystore works
- Doctor login/MFA works without a cookie-strip proxy
- cookieless Electron transport works
- onboarding works
- real S3/MinIO upload grant works
- real PUT reaches MinIO
- upload completes and reaches scanning

The flow then failed:

```
verification.upload_completed
→ outbox worker
→ VerificationUploadProcessor
→ audit append
→ permission denied for clinic_append_audit_event
→ retries
→ DEAD_LETTER
→ upload remains scanning
```

Observed least privilege (must remain true):

```
clinic_worker EXECUTE clinic_append_audit_event(...) = false
```

That denial is expected. This remediation does **not** grant `clinic_append_audit_event`
to `clinic_worker`.

## Root cause

`config/database.php` defined:

```php
'pgsql_audit' => [
    'url' => env('DB_AUDIT_URL', env('DB_URL')),
    'username' => env('DB_AUDIT_USERNAME', 'clinic_audit_writer'),
    'password' => env('DB_AUDIT_PASSWORD', ''),
]
```

Laravel treats a non-empty `url` as authoritative. A generic `DB_URL` (including a
worker DSN) overrode `DB_AUDIT_USERNAME` / `DB_AUDIT_PASSWORD`, so `pgsql_audit`
could connect as `clinic_worker`. There was no fail-closed `select current_user`
guard on that connection.

Intended boundary:

```
business/outbox work  → clinic_worker
audit append          → pgsql_audit → clinic_audit_writer → SECURITY DEFINER clinic_append_audit_event()
```

After the audit identity is corrected, worker promotion also requires
`clinic_verification_documents_protect()` to take `SELECT ... FOR SHARE` on
`verification_cases` without granting `clinic_worker` `UPDATE` on that table.
PostgreSQL `FOR SHARE` is not satisfied by `SELECT` alone. The trigger function
is now `SECURITY DEFINER` so the lock runs as the function owner. Worker case
`UPDATE` remains false.

## Fix

1. `pgsql_audit.url` is `env('DB_AUDIT_URL')` only. Absent URL uses host/port/database
   plus `DB_AUDIT_USERNAME` / `DB_AUDIT_PASSWORD`. Serving `pgsql` is unchanged.
2. `AuditDatabaseIdentity` requires `pgsql_audit` `current_user = clinic_audit_writer`
   before a non-testing `AppendAuditEvent` implementation is used. Wrong identities
   fail closed. Passwords and DSNs are not included in the error.
3. `WorkerDatabaseIdentity` still requires `clinic_worker` and must not rewrite
   `pgsql_audit` onto the worker identity.
4. `clinic_worker` still has no audit table DML and no `EXECUTE` on the append function.
5. Document-protect trigger is `SECURITY DEFINER`; worker does not gain case `UPDATE`.

## Local commands

| Command | Result |
| --- | --- |
| `./vendor/bin/pint --dirty` | passed |
| Pest `AuditConnectionConfigTest` + `PostgresPrivilegeTest` + `WorkerDatabaseIdentityTest` + `AuditDatabaseIdentityTest` + `VerificationPostgresPrivilegeTest` + `VerificationWorkerAuditIdentityTest` | **25 passed** / 201 assertions |
| Pest `tests/Feature/Identity` + worker/outbox + `tests/Feature/Verification` + `tests/Unit/Audit` | **209 passed** / 2108 assertions |
| Pest audit-chain + checkpoint + `VerificationUploadFlowsTest` + `VerificationSecureFileProviderTest` + `OutboxDispatcherTest` | **60 passed** / 498 assertions |
| `./vendor/bin/pest` (full Core) | **806 passed**, 1 failed, 8 skipped, 17181 assertions |
| `./vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | 0 errors |
| `./vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress --fail-on-uncovered` | 0 violations |
| `npm run typecheck --workspace apps/doctor-desktop` | passed |
| `npm run test --workspace apps/doctor-desktop` | **161 passed** / 14 files |
| `npm run typecheck --workspace apps/pharmacy-desktop` | passed |
| `npm run test --workspace apps/pharmacy-desktop` | **138 passed** / 13 files |
| `npm run typecheck --workspace packages/typescript/desktop_bridge_contracts` | passed |
| `npm run test --workspace packages/typescript/desktop_bridge_contracts` | **5 passed** / 1 file |
| `node --test scripts/desktop/cookieless-electron-transport.test.mjs` | **2 passed** |

The single full-suite failure is `ClamdScanObjectTest` live EICAR → expected `infected`, observed `clean`. That is a local clamd signature/environment miss, not this identity change. CI secure-file starts digest-pinned clamd.

Worker/provider gate (not skipped): `VerificationWorkerAuditIdentityTest` processed
`verification.upload_completed` as `clinic_worker` with `pgsql_audit` =
`clinic_audit_writer`, outbox `PROCESSED` (not `DEAD_LETTER`), upload `available`,
document created, audit rows present, worker `EXECUTE` still false. Real MinIO PUT
path in the same file passed when MinIO was reachable. Wrong audit identity left
the outbox `FAILED` (retry policy retained) and the upload not `available`.

Audit chain: `VerifyAuditChain` `ok=true` after dedicated-writer appends. Existing
hash-chain and checkpoint tests passed under `APP_ENV=testing`.

## Final-head GitHub CI (`3f808eb`)

https://github.com/mahmoudemad68/clinical_system/actions/runs/35642597565 — **SUCCESS** (`pull_request`, candidate SHA `3f808eb6d693f245e765228d334a570d9158d8fd`).

| Check | Result |
| --- | --- |
| Detect changed areas | success |
| Contracts | success (oasdiff could not load `/base/base.yaml`; job still succeeded) |
| Supply-chain policy | success |
| Security scans | success |
| Core API | success — PHPStan `[OK] No errors`; Deptrac `--fail-on-uncovered` succeeded; Pest **800 passed**, 15 skipped, 17011 assertions; browser CSRF **4 passed**. `AuditDatabaseIdentityTest`, `PostgresPrivilegeTest`, `WorkerDatabaseIdentityTest`, `AuditChainIntegrityTest`, `AuditChainCheckpointTest` PASS. `VerificationWorkerAuditIdentityTest` WARN in this job: the in-memory worker path passed; live MinIO/scanner cases skip without the provider stack. |
| Secure-file providers | success — Pest **72 passed** / 791 assertions, **0 skipped**, live digest-pinned MinIO + clamd. Includes `VerificationWorkerAuditIdentityTest` (3/3 PASS: worker+audit identities, fail-closed wrong audit identity, real MinIO PUT → worker → available), `VerificationSecureFileProviderTest`, `ClamdScanObjectTest` live scan, `S3StoreObjectUploadGrantHeadersTest`, `AuditDatabaseIdentityTest`, `PostgresPrivilegeTest`, `WorkerDatabaseIdentityTest`. |
| Admin web | success — admin unit **46 passed**; browser admin verification **1 passed** |
| Runtime image scan (core-api) | success |
| Runtime image scan (ai-service) | success |
| Electron desktops | skipped (path filter; no desktop file changes on this branch) |
| Packaged Electron E2E | skipped (path filter) |
| Flutter | skipped (path filter) |
| AI service | skipped (path filter) |

Worker/provider gate counted here is the **Secure-file** job, not the Core API skips. Live MinIO create → PUT → complete → `outbox:work` as `clinic_worker` with `pgsql_audit` = `clinic_audit_writer` → upload `available`, outbox `PROCESSED` (not `DEAD_LETTER`), document created, audit rows present, worker `EXECUTE` still false. Wrong audit identity left outbox `FAILED` (retry retained) and upload not `available`. `ClamdScanObjectTest` live EICAR passed in this job.

Superseded cancelled run on implementation SHA `ac44350`: [35642519728](https://github.com/mahmoudemad68/clinical_system/actions/runs/35642519728).

Core API postgres logs during container stop include `password authentication failed for user clinic_audit_writer`. Tests still passed (`AuditDatabaseIdentityTest` PASS). That is shutdown noise after the Pest process, not a grant of audit `EXECUTE` to `clinic_worker`.

## Remaining risks

- Chunk 11 stays OPEN until GUI QA re-runs the mandatory journey without workarounds.
- Phase 02 remains NOT PASS.
- Legal/regulatory sign-off is not claimed by this note.
- Packaged Electron E2E, Electron desktops (including Forge Doctor smoke and
  cookieless runtime), Flutter, and AI service were skipped by GitHub path
  filters because this branch did not change those trees. Local Doctor **161**,
  Pharmacy **138**, cookieless node **2** still passed on this agent.
- Local full Pest observed one unrelated live-clamd miss; do not treat that as
  this remediation regressing malware detection.
