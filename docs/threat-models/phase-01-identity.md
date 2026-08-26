# Threat model delta — Phase 01 identity and access

Additive to [phase-00-foundation.md](phase-00-foundation.md). Scope is account
registration, OTP, password/MFA, device and admin sessions, National ID
protection, and deny-by-default capabilities.

**Status: engineering draft, not independently reviewed.** Assessor and
remediator are the same person (Mahmoud). Independent re-review remains
Phase 22. This is not a compliance position.

| Boundary | STRIDE | Control in this phase | Residual |
| --- | --- | --- | --- |
| Public clients → `/auth/*` | Spoofing / stuffing | Layered rate limits; Argon2id; dummy verify on unknown user; privileged TOTP | Adaptive/risk-based limits and breached-password service not wired |
| Registration / recovery | Enumeration | Generic OTP envelope; identical 401 login messages | Timing balance is best-effort, not a measured constant-time proof |
| OTP SMS | Disclosure / cost | Outbox after commit; destination handle only; encrypted code for the worker; global hourly budget | Live SMS adapter is disabled; delivery SLO unmeasured |
| Profile claim | Takeover | Flag off; unavailable patient registry; no existence flag | Enablement needs product/privacy/security/support (ADR 0011) |
| Admin cookie | CSRF / fixation | Cookie hash bound to the Laravel session id after regenerate; CSRF required when Origin/Referer or a session cookie is present; MFA CSRF reads the stored challenge row, never client_class | Browser E2E still not run |
| Device tokens | Theft / replay | Session-linked refresh; consumed-token ledger; N-2 reuse revokes family; lost-response replay only with the same Idempotency-Key; Electron atomic credential write; Flutter versioned envelope | Packaged Electron E2E still OPEN (G-02-10); Flutter OS matrix not run |
| Authorization | BFLA | Grant/revoke/disable require admin AAL2; issued_by taken from initiator; audit + outbox | Contextual grants still unused in product UI until later phases |
| National ID | Disclosure | Purpose HMAC + envelope; no invented check-digit ([ADR 0014](../adr/0014-national-id-check-digit-deferred.md)) | Legal check-digit policy outstanding; production KMS is Phase 23 |
| Electron IPC | Renderer compromise | Typed channels; no tokens in IPC schemas; main-process vault | Packaged-window adversarial suite not run |
| Octane | Context leak | Actor on the request; no identity singleton | Alternating-user Octane test for Phase 01 sessions is PARTIAL (Phase 00 hook exists) |
| National ID | Disclosure | Purpose HMAC + envelope; no lookup API | Production KMS is Phase 23 (ADR 0013) |
| Authorization | BFLA | Default-deny; unknown clinical actions 404; pending users restricted | Contextual grants unused until Phase 04 |

Privacy: phones and National IDs are personal/sensitive. Retention periods in
the inventory are engineering defaults, not a legal schedule.
