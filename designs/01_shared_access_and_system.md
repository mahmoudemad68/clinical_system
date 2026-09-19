# Shared access and system screens

These screens share behavior across products but use the shell and credential model of each client. Desktop credentials remain outside the renderer, patient tokens remain in secure mobile storage, and admin uses cookie/CSRF sessions.

## Screen 1 — Sign in

**Maturity:** Current in all four clients; visual treatment is still a minimal shell. **Users:** patient, doctor, pharmacy user, admin.

**Stitch target:** Multi-platform set — generate four separate frames, not one hybrid layout: patient mobile at 390 × 844 px, doctor Electron desktop at 1440 × 900 px, pharmacy Electron desktop at 1440 × 900 px, and admin desktop web at 1440 × 1024 px. Apply each product's shell and title while keeping the same authentication behavior.

- **Purpose and entry:** Default unauthenticated route. A product-specific title and short trust statement identify whether the user is entering My Clinic, Doctor Workspace, Pharmacy Workspace, or Clinic Admin.
- **Layout:** Centered single-column card on mobile/admin; narrow content pane within the desktop shell. Language control remains visible before authentication.
- **Content and actions:** Mobile number, password with show/hide control, Sign in, recovery link, and patient-only Create account link. Do not offer role selection; the server resolves account context.
- **States:** Initial, client validation, submitting, rate limited with safe retry time, generic credential failure, inactive/pending/suspended account, offline, session expired, and desktop keystore unavailable. Errors never reveal whether an account exists.
- **Accessibility/privacy:** Proper autocomplete, password-manager compatibility, visible labels, focus on the first invalid field, bidi-safe phone entry, and no credentials in renderer/mobile logs or storage outside approved secure mechanisms.

## Screen 2 — Patient account registration

**Maturity:** Partially current in patient mobile. **Users:** patient only.

**Stitch target:** Mobile — Flutter patient app, 390 × 844 px phone frame. Generate one high-fidelity Material 3 registration flow screen with mobile-safe keyboard spacing and a single primary action.

- **Purpose and entry:** Create a patient account without disclosing whether the National ID already has a profile. Entered from patient Sign in.
- **Layout:** A short stepper: Account details → Phone verification → Profile setup. The current implementation combines name, National ID, phone, and password; the target design splits the safety-sensitive flow into digestible steps.
- **Content and actions:** Name, Egyptian 14-digit National ID, mobile number, password requirements, language, privacy notice acknowledgment, Continue, and Back. National ID is masked after entry and never offered as a search field.
- **States:** Field validation, submission, duplicate-safe generic success, SMS delivery delayed, rate limit, manual review pending, idempotency conflict, and resume of an existing registration intent.
- **Accessibility/privacy:** Numeric keyboard where appropriate, explain why identity data is needed, do not persist National ID in Drift/analytics, and never display an “existing patient found” result.

## Screen 3 — OTP or authenticator verification

**Maturity:** Current as an inline continuation in all clients. **Users:** all actors; OTP for patient registration/recovery, TOTP for privileged users.

**Stitch target:** Multi-platform family — generate a patient mobile frame at 390 × 844 px and separate desktop frames for doctor Electron, pharmacy Electron, and admin web. Keep the verification card focused and adapt only the surrounding product shell.

- **Purpose and entry:** Complete a purpose-bound verification challenge after registration, login, recovery, or privileged step-up.
- **Layout:** Focused card with the purpose in the title, six-cell visual code input backed by one accessible input, expiration/retry text, and no unrelated content.
- **Content and actions:** Code, Verify, resend when policy allows, use recovery code for configured privileged flows, and Back/Cancel. TOTP copy must say “Authenticator code”; SMS copy must not imply the destination if disclosure is unsafe.
- **States:** Verifying, invalid-or-expired generic error, attempts remaining only if policy approves, resend cooldown, provider delay, challenge consumed, replay, and clock/reconnect recovery.
- **Accessibility/privacy:** Accept pasted codes, announce errors once, maintain LTR input inside RTL layouts, never log code/challenge data, and prevent repeated submit while preserving the same intent.

## Screen 4 — Account recovery and phone change

**Maturity:** Planned in Phase 01. **Users:** all actors, with stricter step-up for privileged accounts.

**Stitch target:** Multi-platform family — generate a mobile patient recovery frame and separate desktop recovery frames for doctor, pharmacy, and admin. Use a step-based flow appropriate to each canvas; do not combine mobile and desktop navigation patterns.

- **Purpose and entry:** Recover access or change the verified phone through a separate high-risk workflow, not through ordinary profile editing.
- **Layout:** Stepper with Identify account → Verify available channel(s) → Cooling-off/manual review when required → Set new credential → Completion.
- **Content and actions:** Minimum identity input, purpose-specific OTP, old/new-channel notice, new password, security explanation, Cancel, and contact support path that does not reveal account existence.
- **States:** Generic request accepted, code invalid/expired, risk review, cooling-off countdown, old channel unavailable, recovery rejected, completed with all sessions revoked, and safe retry after provider failure.
- **Safety:** Support cannot reveal National ID matches, set a password directly, disable MFA, or relink a patient profile from this screen. Completion clearly states that other devices were signed out.

## Screen 5 — Devices and sessions

**Maturity:** Current on doctor/pharmacy desktop; planned richer view on mobile/admin. **Users:** authenticated actors.

**Stitch target:** Multi-platform set — generate a mobile card-list variant at 390 × 844 px plus desktop table/list variants at 1440 px wide for doctor Electron, pharmacy Electron, and admin web. Preserve the same safe session metadata across frames.

- **Purpose and entry:** Review active sessions and revoke one device or all other devices from Profile/Security.
- **Layout:** List or table of cards showing platform icon, user-assigned device label, client class, approximate last activity, and Current device badge. Avoid precise location/IP.
- **Content and actions:** Revoke per row, Revoke all others, rename device where policy permits, and Sign out. A destructive confirmation explains data/cache cleanup.
- **States:** Loading, empty anomaly, revoke pending, revoked, already revoked, version conflict, network ambiguity, and session revocation received in realtime.
- **Safety/accessibility:** Revocation immediately clears authenticated caches, realtime, mobile tokens, desktop credentials, and sensitive renderer state before navigating to Sign in. Rows remain keyboard operable and status is conveyed by text and icon.

## Screen 6 — Platform health and client status

**Maturity:** Current in all clients; intentionally a Phase 00 diagnostic surface. **Users:** currently all client users; target placement should be admin/support-facing or a compact status panel for other clients.

**Stitch target:** Multi-platform set — generate a compact mobile Help/About status screen, a compact desktop panel for both Electron apps, and a desktop-web admin status page. Use separate frames and preserve text/icon status semantics in every variant.

- **Purpose and entry:** Confirm Core, realtime, and AI availability plus client/server version and server time. In production clients, link from Help/About instead of occupying the primary workflow.
- **Layout:** Overall status banner, semantic component list, version/time details, and Retry. Admin may use the richer system-health screen described later.
- **States:** Checking, operational, degraded, unavailable, unreachable, stale snapshot, and update required. AI failure never labels Core unavailable.
- **Visual behavior:** Operational green, degraded amber, and unavailable red are always paired with icon and text. Request ID is selectable only on a failure.
- **Accessibility/security:** Use polite live regions for changes, assertive alerts only for failures, localize server messages safely, and expose no internal hostnames, topology, logs, or raw probe errors.

## Sources

Phases [00](../docs/phases/00_cross_cutting_architecture_and_delivery_contract.md) and [01](../docs/phases/01_auth_identity_and_access.md), plus the current four client entry screens.
