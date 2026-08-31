# Phase 01 entry-point catalog

Generated from current `apps/core-api/routes/api.php`, `routes/web.php`,
`bootstrap/app.php` broadcasting, `packages/contracts/openapi/openapi.yaml`,
and module artisan commands / services. **Not** a memory dump.

Scope: Phase 01 Auth / Identity / Access only. Platform health, diagnostics,
persona status pages, and Phase 02+ clinical routes are omitted.

Access grant issue/revoke and identity disable/erase/export have **no HTTP
routes**. They appear only in the non-HTTP catalog.

CSRF notes used below:

- **cookie-session:** `ValidateCookieCsrf` on the `identity.session` group.
  Required on unsafe methods when a session cookie, XSRF cookie, or
  authenticated web user is present, and on `admin_web` MFA completion.
  Bearer tokens are exempt. A bare `Origin` is not CSRF.
- **web-always:** `ValidateAlwaysCsrf` on the web stack.
- **n/a:** middleware not on the route (device JSON without cookies).

AAL: privileged operator actions require admin +
`AssuranceLevel::satisfiesPrivilegedSession()` (`aal2_totp` or
`aal2_recovery_code`). Self-service capabilities do not.

---

## HTTP entry points (27)

| # | Method | Route | Actor / client class | Auth state | Required AAL | CSRF | Rate-limit class | Idempotency | Feature flag | Privileged capability | Primary threat |
| ---: | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/auth/csrf` | admin_web (session start) | unauthenticated; starts session | n/a | cookie-session (GET not enforced) | none | no | — | — | P01-T2 |
| 2 | POST | `/api/v1/auth/registrations` | patient_mobile (body later) | unauthenticated | n/a | n/a | `otp` (subject+IP+global hourly) | yes | `FEATURE_AUTH_REGISTRATION` | — | P01-T5, P01-T13, P01-T17 |
| 3 | POST | `/api/v1/auth/otp-requests` | any client | unauthenticated | n/a | n/a | `otp` | yes | recovery purpose → `FEATURE_AUTH_RECOVERY`; `profile_claim` → `FEATURE_IDENTITY_PROFILE_CLAIM` | — | P01-T5, P01-T17 |
| 4 | POST | `/api/v1/auth/otp-verifications` | `patient_mobile` / `doctor_desktop` / `pharmacy_desktop` / `admin_web` | unauthenticated challenge | n/a | cookie-session if cookies present | `otp_verify` (challenge+IP) | yes | — | — | P01-T1, P01-T2, P01-T5 |
| 5 | POST | `/api/v1/auth/login` | same client_class enum | unauthenticated | n/a | cookie-session if cookies present | `login` (subject+IP) | no | — | — | P01-T2, P01-T5 |
| 6 | POST | `/api/v1/auth/mfa/challenges/{id}/verify` | same; `admin_web` stored on challenge | MFA pending | n/a (step-up) | cookie-session; **required** for `admin_web` challenge even without cookie | `mfa` (challenge+IP) | no | — | — | P01-T2, P01-T6 |
| 7 | POST | `/api/v1/auth/token/refresh` | device | refresh token (not bearer session) | n/a | n/a | `refresh` (family+IP) | yes (envelope, not `idempotency_keys`) | — | — | P01-T1, P01-T3 |
| 8 | POST | `/api/v1/auth/recovery/start` | any | unauthenticated | n/a | n/a | `otp` (via `RequestOtpService`) | no | `FEATURE_AUTH_RECOVERY` | — | P01-T6, P01-T17 |
| 9 | POST | `/api/v1/auth/recovery/complete` | any | OTP challenge | n/a | n/a | `recovery` (challenge then subject HMAC) | yes | `FEATURE_AUTH_RECOVERY` | — | P01-T6, P01-T5 |
| 10 | POST | `/api/v1/auth/logout` | device bearer XOR admin cookie | authenticated actor | any live session | cookie-session if cookie | none | no | — | — | P01-T1, P01-T9 |
| 11 | GET | `/api/v1/auth/sessions` | same | authenticated; pending-phone allowed | any live | cookie-session (GET) | none | no | — | `auth.session.list_own` | P01-T1 |
| 12 | DELETE | `/api/v1/auth/sessions/{session_id}` | same | authenticated; pending-phone allowed | any live | cookie-session if cookie | none | no | — | `auth.session.revoke_own` | P01-T1, P01-T9 |
| 13 | POST | `/api/v1/auth/sessions/revoke-all` | same | authenticated; pending-phone and password-must-change allowed | any live | cookie-session if cookie | none | yes | — | `auth.session.revoke_all` | P01-T1, P01-T9 |
| 14 | POST | `/api/v1/auth/password/change` | same | authenticated; **denied** if pending-phone; **required path** if password-must-change | any live | cookie-session if cookie | none | no | — | `auth.password.change` | P01-T6 |
| 15 | POST | `/api/v1/auth/mfa/totp/enroll` | same | authenticated; denied if pending-phone | any live (self-service) | cookie-session if cookie | none | no | — | `auth.mfa.manage_self` | P01-T6, P01-T14 |
| 16 | POST | `/api/v1/auth/mfa/totp/confirm` | same | authenticated; denied if pending-phone | any live | cookie-session if cookie | none | no | — | `auth.mfa.manage_self` | P01-T6, P01-T14 |
| 17 | POST | `/api/v1/auth/mfa/recovery-codes/rotate` | same | authenticated; denied if pending-phone | any live | cookie-session if cookie | none | no | — | `auth.mfa.manage_self` | P01-T6, P01-T14 |
| 18 | POST | `/api/v1/auth/mfa/totp/disable` | same | authenticated; denied if pending-phone | any live + TOTP proof | cookie-session if cookie | none | no | — | `auth.mfa.manage_self` | P01-T6, P01-T14 |
| 19 | POST | `/api/v1/auth/recovery/requests/{id}/apply` | admin_web or privileged device | authenticated admin | **privileged AAL2** | cookie-session if cookie | none | no | `FEATURE_AUTH_RECOVERY` | `auth.recovery.apply` | P01-T6, P01-T8 |
| 20 | GET | `/api/v1/me` | same | authenticated; pending-phone allowed | any live | cookie-session (GET) | none | no | — | `identity.me.read` | P01-T8 |
| 21 | GET | `/api/v1/me/capabilities` | same | authenticated; pending-phone allowed | any live | cookie-session (GET) | none | no | — | `identity.capabilities.read` | P01-T8 |
| 22 | POST | `/broadcasting/auth` | device bearer XOR admin cookie | authenticated actor (`identity.session` + `auth.actor`) | any live | cookie-session if cookie | none | no | — | private `auth.session.{id}` must match presenting session | P01-T9 |
| 23 | GET | `/login` | admin browser | unauthenticated | n/a | web-always (GET) | none | no | — | — | P01-T2 |
| 24 | POST | `/login` | admin browser | unauthenticated | n/a | **web-always** | `login` (via password service) | no | — | — | P01-T2, P01-T5 |
| 25 | GET | `/mfa` | admin browser | MFA pending cookie flow | n/a | web-always (GET) | none | no | — | — | P01-T2, P01-T6 |
| 26 | POST | `/mfa` | admin browser | MFA pending | n/a (step-up) | **web-always** | `mfa` | no | — | — | P01-T2, P01-T6 |
| 27 | POST | `/logout` | admin browser | authenticated cookie | any live | **web-always** | none | no | — | — | P01-T1, P01-T2 |

Authenticated API group middleware: `identity.session`, `auth.actor`,
`auth.pending` (`DenyPendingBusinessAccess`). Actor is bearer XOR cookie; the
schemes are not mixed.

OpenAPI documents the 21 `/api/v1/auth/*` and `/api/v1/me*` operations above
(csrf through capabilities). Broadcasting and Inertia admin login are
framework/web routes, not OpenAPI public API.

---

## Non-HTTP security entry points (16)

These are not HTTP routes. Do not invent REST paths for them.

| # | Entry | Kind | Actor | What it can do | Primary threat |
| ---: | --- | --- | --- | --- | --- |
| 1 | `identity:bootstrap-admin {phone} {--name=} {--confirm}` | artisan | operator with CLI + `IDENTITY_BOOTSTRAP_ENABLED` (non-production unless already bootstrapped) | Create first admin; TOTP confirm via hidden prompt; audited decrypt for bootstrap TOTP | P01-T6, P01-T14 |
| 2 | `identity:rotate-keys` (`--dry-run` default, `--apply`, `--confirm` in production, `--status`, `--batch=`) | artisan | operator | Dual-read/new-write envelope and HMAC backfill; never prints secrets; never deletes old keys | P01-T13, P01-T11 |
| 3 | `identity:apply-due-recoveries` | artisan / scheduler | system | Applies **patient cooling-off** rows only; does not apply `manual_review` | P01-T6 |
| 4 | `auth:prune-expired` | artisan / scheduler | system | Expire OTP challenges; prune obsolete sessions/recoveries/devices/refresh consumptions. Ciphertext not logged. | P01-T15, P01-T1 |
| 5 | `platform:prune` `{--chunk=} {--dry-run}` | artisan / scheduler | system | PROCESSED outbox, expired idempotency keys, old diagnostics. Not subject erasure. | P01-T15 |
| 6 | `access:prune-expired` | artisan / scheduler | system | Delete obsolete contextual grants (engineering retention) | P01-T8, P01-T15 |
| 7 | `audit:verify-chain` | artisan | operator | Recompute hash chain + checkpoints; counts only | P01-T7 |
| 8 | `audit:checkpoint-chain` | artisan | operator | Verify then sign/store external chain-tip file | P01-T7 |
| 9 | `outbox:work` | worker process | PostgreSQL role `clinic_worker` | Dispatches consumers: `OtpDeliveryConsumer` (`auth.otp_delivery_requested`, audited OTP decrypt), `SessionRevokedConsumer` (`auth.session_revoked` → Reverb disconnect), `RecoveryOldChannelNoticeConsumer` (SMS + FCM generic notice), `DiagnosticsRoundTripConsumer` | P01-T9, P01-T14, P01-T16 |
| 10 | `queue:work` / `horizon` / `horizon:work` | worker process | `clinic_worker` via `WorkerDatabaseIdentity` | Laravel/Horizon jobs; refuses `clinic_app` | Phase 00 B3 |
| 11 | Reverb WebSocket connection | realtime | client after `/broadcasting/auth` | Subscribe to authorized `private-auth.session.{id}` only; client events denied | P01-T9 |
| 12 | `GrantContextualAccessService` | module service | admin AAL2 | Issue grantable capability on allow-listed resource types. **No HTTP.** | P01-T8 |
| 13 | `RevokeContextualAccessService` | module service | admin AAL2 | Revoke grant. **No HTTP.** | P01-T8 |
| 14 | `EraseSubjectService` | module service | admin AAL2 `identity.erase` | Phase-01 technical erasure. **No HTTP.** | P01-T15 |
| 15 | `ExportSubjectDataService` | module service | admin AAL2 `identity.export` | Enumerates holdings; **no HTTP.** | P01-T15 |
| 16 | `DisableIdentityService` | module service | admin AAL2 `identity.disable` | Disable/suspend is **not** erasure. **No HTTP.** | P01-T8, P01-T15 |
