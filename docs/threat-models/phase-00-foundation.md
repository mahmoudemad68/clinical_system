# Threat model — Phase 00 foundation

STRIDE plus privacy analysis across the **eight** trust boundaries the phase
file names, updated to match the implemented system after Phase 01 identity
landed. Scope is the foundation as built: gateway, core API, PostgreSQL role
split, Redis (including the auth rate-limit database), object storage, workers,
Reverb, the AI service boundary, the outbox, first-party clients
(Inertia admin, Electron doctor/pharmacy, Flutter patient), and the
developer/CI plane.

**Status: engineering draft, not independently reviewed.** This document was
rewritten so it no longer contradicts the running code. Named-owner acceptance
is not independent security/privacy approval. **G-08-04 remains OPEN.** This is
not a legal or compliance position. Independent re-review remains Phase 22.

Method: for each boundary, what crosses it, what an attacker would try, what
stops them today, and what does not.

---

## Boundary 1 — Public clients to gateway

**Crosses:** patient mobile, doctor desktop, pharmacy desktop, and admin
browser traffic. Clients are first-party; they are not a second web stack.

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Spoofing | Stolen device token replayed from another device | Bearer required; refresh is session-linked with a consumed-token ledger; N-2 reuse revokes the family; logout revokes session and device | Packaged Electron and Flutter OS-backup matrices remain evidence gaps |
| Tampering | Request body altered in transit | TLS at the gateway in deployed environments; local Compose is loopback | Certificate pinning on mobile is a Phase 08 decision |
| Repudiation | Client denies making a request | Correlation ID on every outbox row; identity mutations append `audit_events` | Chain verifier is an operations control, not a legal signature |
| Information disclosure | Error response leaks internals | `ExceptionRenderer` maps throwables to a stable code and safe message | CSRF mismatches use `CSRF_MISMATCH`, distinct from `UNAUTHENTICATED` |
| Denial of service | Oversized payload or credential stuffing | `EnforceRequestBounds` (1 MiB, JSON depth 32); auth rate limits on the named Redis `ratelimit` store (DB index 3) | Adaptive/risk-based limits are not wired |
| Elevation of privilege | Client claims a role in the request | `ActorContext` is server-owned; `ClosedJsonValidator` rejects unknown properties on auth API routes | Contextual grants are resource-scoped; unknown clinical actions still 404 |

**Privacy:** a client-supplied `X-Request-Id` is honoured only if it is a valid
UUIDv7.

## Boundary 2 — Admin browser session and CSRF

**Crosses:** first-party Inertia admin (`apps/core-api/resources/js`) cookie
session. Tokens never enter `localStorage`.

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Spoofing | Cross-site request using the admin session | HTTP-only `SameSite` cookie; cookie hash bound to the Laravel session id after regenerate; CSRF required when a session cookie or authenticated web user is present | Device API login does not treat a bare `Origin` as CSRF (Electron `net.fetch`) |
| Information disclosure | Token readable by injected script | No access token in the admin document | — |
| Tampering | XSS injecting a request | React escaping; API CSP `default-src 'none'`; Inertia CSP `default-src 'self'` | — |
| Elevation of privilege | Admin reaching clinical data | Admin has no clinical read path; grant issue/revoke/disable need admin AAL2 | Product UI for grants is not shipped |

## Boundary 3 — Gateway to core workers and Reverb

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Spoofing | Direct call bypassing the gateway | Compose binds ports to `127.0.0.1`; `/live` and `/ready` are outside the public API group | Network policy in a real deployment is unbuilt |
| Information disclosure | `/ready` used for reconnaissance | Body carries check names and status only | — |
| Denial of service | Worker or websocket exhaustion | Horizon workers; Reverb `max_connections` 500 and rate limiting on; client events denied | Measured revoke-to-socket-close SLO is an evidence item, not a legal SLA |
| Tampering | Client forging a private channel name | `auth.session.{id}` authorizes the exact live session row; other channels deny | HTTP deny is authoritative if the socket lags |

Workers consume the outbox (`outbox:work`) and Laravel/Horizon queues as
PostgreSQL role `clinic_worker` on the `pgsql_worker` connection.
`WorkerDatabaseIdentity` selects that connection when a worker process boots
(`queue:work`, `horizon` / `horizon:work`, `outbox:work`) and refuses to run
when `current_user` is the HTTP serving role (`clinic_app`) or the worker
username is not `clinic_worker`. HTTP and Octane stay on `pgsql` /
`clinic_app`. Pest HTTP tests still log in as `clinic_migrator` for schema
convenience; they assert the default connection is not `pgsql_worker`.

Workers have DML on `jobs`, `outbox_events`, `notifications`, and
`platform_diagnostics`, plus OTP delivery column updates, not on `users` or
grants.

## Boundary 4 — Core to PostgreSQL, Redis, object storage

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Elevation of privilege | SQL injection escalating to schema change | Serving roles hold **no DDL**. Only `clinic_migrator` migrates | Initdb on an already-initialized volume does not recreate roles; hardening migrations must run on every database (`clinic` and `clinic_test`) |
| Tampering | Application check raced under concurrency | Unique indexes (idempotency, grants, refresh consumption); `SELECT … FOR UPDATE` on OTP/device/MFA rows; audit append uses an advisory lock plus a `SECURITY DEFINER` insert function | Two-connection tests exist; they are not a formal proof of every future race |
| Denial of service | Runaway connections | Per-role `CONNECTION LIMIT` and statement timeouts | PgBouncer is a deployment concern |
| Information disclosure | Reporting role reading identity ciphertext | After the hardening migration, `clinic_reporter` has **no** `SELECT` on identity tables. It may `SELECT` only `reporting.*` views (counts, not ciphertext) | A database that has not applied those migrations is out of policy |
| Information disclosure | Cache poisoning / auth-limit bypass | Auth counters use Redis DB 3 (`ratelimit`), not the default cache. Empty Redis degrades to a miss; PostgreSQL remains authoritative for identity | phpunit still uses the array driver unless a Redis test opts in |
| Tampering | Audit row forged by the serving role | `clinic_app` has `SELECT` on `audit_events` and `EXECUTE` on `clinic_append_audit_event`; it has **no** table `INSERT`/`UPDATE`/`DELETE`. A trigger rejects UPDATE/DELETE | A compromised migrator or function owner still wins |

**Production transport:** serving connections must use `sslmode=require`,
`verify-ca`, or `verify-full`. Local Compose may use `prefer` because the
loopback server has no server certificate. Production KMS binding is Phase 23
([ADR 0013](../adr/0013-identity-key-management.md),
[production-kms-tls.md](../operations/production-kms-tls.md)).

## Boundary 5 — Outbox producers to consumers

Unchanged in mechanism: only `OutboxRecorder` writes, only in a transaction;
consumers are idempotent on `event_id`; `credential` classification is
rejected. OTP delivery events carry destination handles, never phones or codes.

## Boundary 6 — Core to AI service, AI service to providers

Unchanged: the AI service holds no Core database credential and refuses to
start outside local if `DB_*` is present. Nothing clinical is sent in Phase 00/01.
Cross-border processing remains a **legal** question (OPEN).

## Boundary 7 — Developer, CI, staging, production planes

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Information disclosure | Secret exfiltrated by a fork pull request | The PR workflow requests **no secrets** | — |
| Tampering | Malicious dependency | Frozen installs; Python `--require-hashes`; SHA-pinned Actions; Trivy High/Critical fails the job | SF-001 (`extract-zip`) is isolated to the Electron **build** lockfile for merge scans; **promotion scans the full tree and stay blocked** |
| Tampering | Image substituted between build and deploy | Built once, signed keylessly, provenance attested | Staging deploy is still an intentional `exit 1` placeholder |
| Spoofing | Unauthorized production deploy | Production promotion is hard-disabled until Phase 23 | — |
| Information disclosure | Secret committed to git | Gitleaks | Pre-commit hook not installed |

CI evidence is the GitHub Actions run on the remediation branch, not a claim
that a previous failed post-merge image scan was a passing control.

## Boundary 8 — Electron renderer → main / OS (and Flutter platform channels)

Phase 00 names this boundary. It is not optional after desktop/mobile clients
exist.

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Elevation of privilege | Renderer reaches Node or arbitrary IPC | Typed channels; no generic IPC; fuses; packaged `loadURL` uses `clinic-*-app://-`, not `file://` | SHA-bound installed OS matrix / WebdriverIO against a packaged artifact |
| Information disclosure | Tokens in renderer or IPC | Tokens stay in the main-process vault; Flutter uses a versioned secure-storage envelope | Android/iOS backup/keystore matrix may be host-limited |
| Tampering | Packaged origin confusion | Path containment on the custom protocol; HTTPS required for packaged `net.fetch` | Production API host is scheme-checked, not allowlisted |

Flutter uses platform channels into OS secure storage, not Electron. Same
classification of secrets; different OS backup threat.

---

## Named threats from the phase file

| Threat | Position |
| --- | --- |
| Broken object/tenant authorization | Default-deny; unknown clinical actions 404; grants are resource-scoped. Clinical objects arrive in later phases. |
| Stolen device tokens | Refresh family + consumption ledger + absolute session cap. Residual: client OS backup/packaged E2E evidence. |
| CSRF / XSS | Admin cookie bound to Laravel session id; CSRF when a session cookie exists; CSP as above. |
| Replay | Idempotency plus refresh envelope (not `idempotency_keys` for tokens). |
| Mass assignment | Closed JSON on auth API; OpenAPI `additionalProperties: false`. |
| Injection | Parameterized queries; serving roles hold no DDL. |
| SSRF | No user-supplied URL is fetched. |
| Malicious files | No upload path. Phase 02/07. |
| Event forgery | Recorder-only writes. |
| Queue duplication | Exactly-once-in-effect tests remain. |
| Cache poisoning | Auth limits are a dedicated Redis DB; identity is not cached as truth. Platform meta/readiness keys remain public/internal TTLs. |
| Log leakage | `PatternRedactor` plus log taps plus Sentry `before_send`. Sink canaries exist; they are not a collector-export proof. |
| Insecure defaults | Flags off; production readiness fails closed on HTTPS, secure cookies, trusted proxies, and DB SSL mode. |
| Dependency compromise | Frozen locks, hashed Python, SBOM job, image scan, SF-001 isolated without a promotion exception. |
| Backup exposure | `clinic_backup` is SELECT-only across application tables after the hardening migration. Encrypted backup restore is Phase 23. |
| Prompt injection / excessive AI agency | Phase 16+. Isolation boundary exists. |
| Denial of service / wallet | Request bounds; auth rate limits; OTP hourly budget. |
| Cross-environment data leakage | Separate credentials; staging must not clone raw production medical data. |

## Top residual risks

1. **Independent approval is absent.** This rewrite makes the model accurate; it
   does not close G-08-04.
2. **Legal/privacy questions stay OPEN:** lawful basis, Egyptian retention
   statutes, National ID check-digit policy (ADR 0014), cross-border AI.
3. **SF-001 remains High** in the Electron packaging toolchain. Merge is
   time-boxed; promotion is blocked.
4. **Production KMS and encrypted backups are Phase 23.** Application keys in
   env are a local/staging binding only (ADR 0013).
5. **CODEOWNERS teams are not an enforceable GitHub control** until those teams
   exist.
