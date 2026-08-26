# Threat model — Phase 00 foundation

STRIDE plus privacy analysis across the seven trust boundaries the phase file
names. Scope is the foundation as built: gateway, core API, PostgreSQL, Redis,
object storage, the AI service boundary, the outbox, and the developer/CI plane.

**Status: owner-accepted engineering draft, not independently reviewed.**
Named security/privacy owner (Mahmoud) accepted this draft on 2026-08-26
(G-08-04). Assessor/remediator separation is lost. This is not a compliance
position. Independent re-review remains Phase 22.

Method: for each boundary, what crosses it, what an attacker would try, what
stops them today, and what does not.

---

## Boundary 1 — Public clients to gateway

**Crosses:** all patient, doctor, pharmacy, and admin traffic.

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Spoofing | Stolen device token replayed from another device | Bearer token required; Phase 00 accepts only a synthetic token in local/dev | Device binding, rotation, and revocation: see [phase-01-identity.md](phase-01-identity.md) |
| Tampering | Request body altered in transit | TLS at the gateway | Certificate pinning on mobile is a Phase 08 decision |
| Repudiation | Client denies making a request | Correlation ID assigned and persisted on every outbox row | Audit trail proper is Phase 01 |
| Information disclosure | Error response leaks internals | `ExceptionRenderer` maps every throwable to a stable code and safe message; no stack, SQL, or object key | — |
| Denial of service | Oversized or deeply nested payload | `EnforceRequestBounds`: 1 MiB body cap and JSON depth cap of 32, both before parsing | Per-actor rate limiting is Phase 01 |
| Elevation of privilege | Client claims a role in the request | Policies read server-owned context only; no client-supplied role, tenant, or scope is trusted | Enforced by convention until Phase 01 adds policies |

**Privacy:** a client-supplied `X-Request-Id` is honoured only if it is a valid
UUIDv7. Arbitrary text there would allow log injection and forged correlation
with another user's activity.

## Boundary 2 — Admin browser session and CSRF

**Crosses:** admin dashboard traffic.

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Spoofing | Cross-site request using the admin's session | Session is an HTTP-only, `SameSite` cookie; the transport wrapper echoes the CSRF cookie as a header, which a cross-origin page cannot read to copy | Session issuance itself is Phase 01 |
| Information disclosure | Token readable by injected script | No token in `localStorage`; the session cookie is HTTP-only (`plan.md` section 5) | — |
| Tampering | XSS injecting a request | React escapes by default; API responses carry `default-src 'none'` CSP and `nosniff`; Inertia pages carry a same-origin CSP (`default-src 'self'`, no `unsafe-eval`, no third-party font CDN) plus `nosniff` / `DENY` framing | — |
| Elevation of privilege | Admin reaching clinical data | Admin has no clinical read path by design; "admin" never implies PHI access | Enforced by absence in Phase 00; needs policies from Phase 02 |

## Boundary 3 — Gateway to core workers and Reverb

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Spoofing | Direct call bypassing the gateway | Compose binds every port to `127.0.0.1`; `/live` and `/ready` are registered outside the API group and must not be routed publicly | Network policy in a real deployment is unbuilt |
| Information disclosure | `/ready` used for reconnaissance | Body carries check names and status only — no hostnames, ports, credentials, or dependency versions; asserted by a test | — |
| Denial of service | Readiness probe blocked by a slow dependency | Every dependency check is time-bounded; the AI probe caches its negative result for 5s so an outage does not generate a connection storm | — |

## Boundary 4 — Core to PostgreSQL, Redis, object storage

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Elevation of privilege | SQL injection escalating to schema change | Serving roles hold **no DDL**. `clinic_app`, `clinic_worker`, `clinic_reporter` cannot `CREATE`, `ALTER`, or `DROP`; only `clinic_migrator` can, and only the migration job uses it | — |
| Tampering | Application check raced under concurrency | Invariants enforced by database constraints: unique key on idempotency claims, CHECK constraints on status, classification, and label content | — |
| Denial of service | Runaway worker exhausting connections | Per-role `CONNECTION LIMIT`, `statement_timeout`, and `idle_in_transaction_session_timeout` | PgBouncer is a deployment concern, unbuilt |
| Information disclosure | Reporting role reading raw sensitive columns | `clinic_reporter` is `SELECT`-only and intended for de-identified projections | The projections do not exist yet; the role currently sees every table |

**The reporter gap is real and worth stating plainly.** Default privileges grant
`clinic_reporter` `SELECT` on all tables the migrator creates. In Phase 00 no
table holds personal data, so nothing is exposed. Before Phase 01 stores a
patient profile, that grant must be narrowed to explicit projections.

## Boundary 5 — Outbox producers to consumers

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Tampering | Forged event injected into the outbox | Only `OutboxRecorder` writes rows, and only inside a transaction; a write outside one throws | An attacker with database write access has already won; this is not the defence |
| Repudiation | Effect happened with no record of why | `correlation_id` and `causation_id` on every row | — |
| Denial of service | Poison event starving the queue | Capped retries with jitter, then dead-letter; a stuck row does not block others because claims use `SKIP LOCKED` | — |
| Information disclosure | Clinical payload in an event | Payload size bounded at 16 KiB; `classification` CHECK rejects `credential`; the event validator rejects known sensitive property names | A payload could still carry sensitive content under an innocuous key; review is the control |
| Duplication | At-least-once delivery applied twice | Consumers idempotent on `event_id`; the reference consumer uses a conditional `UPDATE` rather than read-then-write | — |

## Boundary 6 — Core to AI service, AI service to providers

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Elevation of privilege | AI service writing core state | It holds no core database credential. The service refuses to start outside local if `DB_*` variables are present | — |
| Spoofing | Unauthenticated call to the internal contract | Shared token required; empty token denies everything rather than accepting everything | Real credential management is Phase 16 |
| Denial of service | Slow provider blocking a request | Deadline on every internal command; AI work is dispatched post-commit, never inline | — |
| Information disclosure | PHI reaching an external model provider | Nothing is sent in Phase 00; the boundary exists but carries no data | The entire cross-border processing question is open and needs legal sign-off |
| Tampering | Prompt injection via retrieved content | Not applicable yet | Phase 16/17. Deterministic code must own permissions, tool allowlists, and final writes |

## Boundary 7 — Developer, CI, staging, production planes

| STRIDE | Threat | Control today | Gap |
| --- | --- | --- | --- |
| Information disclosure | Secret exfiltrated by a fork pull request | The PR workflow requests **no secrets at all**, so there is nothing for untrusted code to read | — |
| Tampering | Malicious dependency | Frozen installs from committed locks; Python installs with `--require-hashes`; Trivy and Semgrep in CI | No dependency-update review policy yet |
| Tampering | Image substituted between build and deploy | Built once, signed keylessly by digest, provenance attested | No verification step at deploy, because there is no deploy |
| Spoofing | Unauthorized production deploy | Production promotion is hard-disabled until Phase 23 | — |
| Information disclosure | Secret committed to git | Gitleaks scans history and working tree; local values use one recognisable literal so a real secret stands out | Pre-commit hook not installed |

---

## Named threats from the phase file

The phase requires these be addressed explicitly.

| Threat | Position |
| --- | --- |
| Broken object/tenant authorization | No object access exists yet. Policies and tenant predicates are Phase 01–02. The pattern is fixed: server-owned context only. |
| Stolen device tokens | Phase 01. Phase 00's synthetic token is local/dev only and gated by environment. |
| CSRF / XSS | HTTP-only cookie plus header echo; CSP `default-src 'none'` on API responses; React escaping. |
| Replay | Idempotency contract implemented and tested, including same-key/different-body rejection. |
| Mass assignment | Form request rejects unknown properties; OpenAPI sets `additionalProperties: false`. |
| Injection | Parameterized queries throughout; serving roles hold no DDL. |
| SSRF | No user-supplied URL is fetched. The AI base URL is configuration, not input. |
| Malicious files | No upload path exists. Phase 02/07 introduce quarantine. |
| Event forgery | Only the recorder writes, only in a transaction. |
| Queue duplication | Exactly-once-in-effect proven by test with forced duplicate delivery. |
| Cache poisoning | Nothing is cached in Phase 00. |
| Log leakage | `PatternRedactor` with 60+ key rules and 10 value patterns, canary-tested; collector scrubs again. |
| Insecure defaults | Every config default fails closed: flags off, AI optional, tighter bound chosen. |
| Dependency compromise | Frozen locks, hashed Python installs, SBOM, image scanning. |
| Backup exposure | No backups exist yet. Phase 23. |
| Prompt injection / excessive AI agency | Phase 16+. The isolation boundary that makes it containable exists now. |
| Denial of service / wallet | Request bounds and JSON depth cap; AI budget controls are Phase 16. |
| Cross-environment data leakage | Separate credentials per environment; staging may never clone raw production medical data. |

## Top residual risks

1. **`clinic_reporter` sees every table.** Harmless today, unacceptable once
   Phase 01 stores a patient profile. Narrow before then.
2. **Redaction is proven as a unit, not on the export path.** The canary suite
   passes; no test yet proves a synthetic clinical request stays redacted all
   the way to the collector (G-07-05).
3. **No authorization layer exists.** Everything above about deny-by-default is
   a design commitment, not running code, until Phase 01.
4. **Nothing is independently reviewed.** Every control here was written and
   assessed by the same party. That is precisely what Phase 22 exists to fix.
