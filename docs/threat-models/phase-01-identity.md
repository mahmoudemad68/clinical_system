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
| Device tokens | Theft / replay | Hashed storage; rotation; family revoke on reuse; Flutter secure storage; Electron `safeStorage` fail-closed | Packaged Electron E2E still OPEN (G-02-10) |
| Admin cookie | CSRF / fixation | `clinic_session` HttpOnly/SameSite; CSRF on `admin_web` and cookie-authenticated mutations | Cookie session hash is metadata alongside Laravel session; idle/absolute expiry is application-enforced |
| Electron IPC | Renderer compromise | Typed channels; no tokens in IPC schemas; main-process vault | Packaged-window adversarial suite not run |
| Octane | Context leak | Actor on the request; no identity singleton | Alternating-user Octane test for Phase 01 sessions is PARTIAL (Phase 00 hook exists) |
| National ID | Disclosure | Purpose HMAC + envelope; no lookup API | Production KMS is Phase 23 (ADR 0013) |
| Authorization | BFLA | Default-deny; unknown clinical actions 404; pending users restricted | Contextual grants unused until Phase 04 |

Privacy: phones and National IDs are personal/sensitive. Retention periods in
the inventory are engineering defaults, not a legal schedule.
