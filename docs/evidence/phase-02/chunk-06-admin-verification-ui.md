# Phase 02 chunk 06 — React Admin verification review UI (not phase PASS)

> **Catalogue supersession (2026-09-24):** Phase 02 Verification Policy
> v1.0.1-phase02 replaces the ENGINEERING_DEFAULT Admin decision/reason
> catalogue recorded below. See
> `docs/evidence/phase-02/verification-policy-v1.0.1-phase02.md`.

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** claim the branch is READY_TO_MERGE.

**Scope implemented:** browser-admin human review workspace for the doctor
verification backend from chunk 05. An operator can sign in (password + MFA),
bootstrap session and capabilities from the server, browse the pending review
queue, inspect a safe professional case, claim it, explicitly request access to
a trusted verification document, download the attachment, record an
approve / reject / changes_requested decision, and see the authoritative
refreshed case state.

**Explicitly deferred:** Pharmacy organization UI/backend, pharmacy branches,
clinics/location UI, memberships, Doctor Electron verification UX, Patient
Flutter Phase 02 completion, doctor public listing, clinical capability
activation, scheduling / Phase 03, generic Admin role designer, reviewer
reassignment, bulk review, bulk document export, SLA escalation, inline
verification document viewer.

**SF-001** remains unresolved / unaccepted (`MERGE_ONLY`,
`promotion_allowed=false`). `FEATURE_IDENTITY_PROFILE_CLAIM` remains off.
National-ID checksum remains an open decision. Staging `Deploy to staging`
remains fail-closed. This chunk does not bypass those controls and does not
claim resolution of G-08-04.

- **Branch:** `cursor/phase-02-admin-verification-ui-cc7f`
- **Draft PR:** recorded on the pull request after open
- **Base (GitHub `main`):** `dadf2641bbec40c6bd2acc6fd97cc19c22bc8139`
- **Recorded:** 2026-09-20

## Session bootstrap

The previous `signedIn: boolean` client flag is gone. `SessionProvider`
loads `/api/v1/me` and, when authenticated, `/api/v1/me/capabilities` on
startup. UI states are:

- `bootstrapping`
- `signed_out`
- `unauthorized` (authenticated without `verification.case.review`)
- `authorized_reviewer`
- `session_expired`

Authentication is never inferred from `localStorage`, `sessionStorage`, or
IndexedDB. The existing HTTP-only admin cookie is the session.

After login/MFA the session queries are invalidated and refetched. Logout
POSTs `/api/v1/auth/logout`, revokes Blob URLs, sets a local signed-out flag
so observers do not refetch `/me` into a stale workspace, and
`queryClient.clear()`.

Access tokens, refresh tokens, passwords, MFA codes, and signed reviewer URLs
are never written to Web Storage, IndexedDB, URL fragments, or analytics.

## Capability / MFA gating

The verification workspace is shown only when the server-derived capability
`verification.case.review` is present. The queue query is `enabled` only in
that state. An authenticated actor without the capability sees a safe
unauthorized panel. The existing password → MFA challenge → cookie session
flow is reused; no new MFA API was added.

## Queue and cursor pagination

Route `/verification` (and `/` for authorized reviewers) lists pending doctor
verification cases.

- Filters: `assignment=unassigned|mine|all` (default unassigned),
  `case_type=doctor_verification`, `status=pending_review`.
- No offset pagination and no fabricated totals.
- Next page uses the signed cursor as an opaque string. Previous pages use an
  in-memory cursor stack. Cursors are never decoded or persisted.
- Changing assignment clears the stack and refetches page one.
- `CURSOR_INVALID` discards the stale cursor and refetches page one with an
  operator message.
- TanStack Query: ~8s stale time, refetch on window focus, manual refresh,
  invalidate after claim/decision. No optimistic decision writes.

Rendered queue fields are the backend projection only (professional name,
localized specialty, statuses, assignment, submitted time, case version).
National ID, syndicate, phone, email, locators, hashes as dominant UI, user
ids, reviewer ids, and clinical fields are not rendered.

## Case detail and claim

`GET /api/v1/admin/verification-cases/{case_id}` is the only detail source.
Claim POSTs `{ expected_case_version }` only. Documents and decision controls
appear only when `assigned_to_me` and the case is `pending_review`. A
foreign-assigned case is a read-only safe summary. `VERSION_CONFLICT` /
`STATE_CONFLICT` stop the mutation, refetch, and explain that the case
changed. Guessed/missing cases show a generic safe error plus request id.

## Document access (explicit, ephemeral URL)

No grant runs on queue load, case open, mount, or hover. One click on
**View / download document** issues one
`POST .../documents/{document_id}/access`.

The signed URL exists only inside `grantAndDownloadReviewerDocument` /
`downloadSignedReviewerFile`. It is not stored in React Query, component
state, Web Storage, or the DOM. After a successful grant the operator
sees that **document access was recorded** (grant issuance, not proof of
reading).

The helper still forwards `parsed.search` on the same-origin GET so HMAC
query parameters reach Laravel. Logs must not. The Admin E2E host
(`tests/e2e/admin-web-server.mjs`) still proxies `path: req.url` (query
intact) but writes only method + pathname + `qs=0|1` plus cookie/auth
presence flags. `tests/e2e/admin-web-server.test.mjs` GETs a reviewer-file
path with `signature=SIGNED_SECRET_CANARY` and
`X-Amz-Signature=AMZ_SECRET_CANARY` (plus cursor/token canaries) and
asserts those values are absent from proxy stdout/stderr while the
upstream request still receives the original query. That is the log
oracle; Playwright DOM/cache assertions are separate and insufficient
for this invariant. php-router logs remain presence-only (no
REQUEST_URI). php -S stderr is piped through
`tests/e2e/redact-e2e-stdio.mjs` before `/tmp/clinic-e2e-laravel.log` so
a REQUEST_URI warning cannot be tailed into GitHub Actions.

The helper:

1. POSTs the grant through the generated OpenAPI client.
2. Rewrites the application-owned URL onto the current origin only when the
   path is `/api/v1/verification-review-files/{uuid-v7}/{uuid-v7}`.
3. GETs with `cache: 'no-store'`, `referrerPolicy: 'no-referrer'`,
   `credentials: 'omit'`, `redirect: 'error'`.
4. Verifies HTTP success and that the body byte count equals the signed
   grant `size_bytes`. A missing `Content-Length` is allowed and is
   treated as that expected size; when the header is present it must
   match both the body and `size_bytes`.
5. Creates a Blob URL, triggers an attachment download with a generic
   filename (`verification-document.pdf` and siblings), and revokes the Blob
   URL immediately.

Failed/expired grants are not retried in a loop. A new click mints a new
grant. Locators, SHA mismatch details, HMAC signatures, and provider
exceptions are not shown.

## Decision form and idempotency

React Hook Form + Zod. Historical chunk catalogue (superseded by
v1.0.1-phase02; see `verification-policy-v1.0.1-phase02.md`):

- `approved` + `approved`
- `rejected` + `evidence_incomplete` | `identity_mismatch`
- `changes_requested` + `evidence_incomplete` | `documents_illegible`

`expected_case_version` is server-derived and not editable. Notes max 2000.
A confirmation dialog shows decision, safe reason, and professional display
name. Approval text states that approval verifies this profile status only
and does **not** automatically list the doctor or activate clinical
capabilities.

One UUID idempotency key per logical payload fingerprint. Network retry of
the exact same payload reuses the key. A changed payload mints a new key.
Keys are not persisted across cases or browser storage. `4xx` is not
auto-retried. `VERSION_CONFLICT` / `STATE_CONFLICT` / `IDEMPOTENCY_KEY_REUSED`
stop and refetch. After success, queue and detail are invalidated and
decision controls disappear.

## Cache / persistence

Query keys: `['session', ...]`, `['verification', 'queue', assignment, cursor]`,
`['verification', 'case', caseId]`. No React Query persistence plugin. Logout
and session expiry clear verification queries and Blob URLs. A refresh
refetches authoritative server state.

## Arabic / English / RTL

All new operator strings live in `apps/admin-web/src/i18n/en.ts` and `ar.ts`.
`dir` and `lang` are set on `<html>`. MUI theme `direction` follows the
locale. Specialty uses `label_ar` / `label_en`. Vitest covers Arabic
specialty + `dir=rtl`.

## Accessibility

Semantic MUI controls, labelled filters, loading text, alert roles, labelled
confirmation dialog, status chips with text (not colour alone). axe-core
`wcag2a` / `wcag2aa` serious/critical violations are asserted empty on the
queue. No axe-rule suppression.

## DOM / prohibited-field security

Static scan: no `dangerouslySetInnerHTML`, no iframe, no `window.open(`.
Routes are `/`, `/verification`, `/verification/:caseId` only. Vitest canaries
assert National ID, syndicate, phone, locators, signed URL, and reviewer notes
are absent from queue/detail HTML. Feature code cannot call raw `fetch`
(`no-restricted-globals`).

## Tests

- Admin Vitest: 44 passing (session, queue, claim/detail, document access,
  decision pairs/idempotency/conflicts, canaries, axe, prohibited routes).
- Playwright: existing CSRF project preserved (`--project=csrf` on Core API).
  New `--project=admin-verification` against the Admin production build hosted
  by `tests/e2e/admin-web-server.mjs` (static `dist/` plus `/api` proxy that
  forwards Cookie and Set-Cookie arrays via `setHeader` before `writeHead`) and
  Laravel, seeded with synthetic data only. The proxy access log is pathname
  plus `qs=0|1` only; `admin-web-server.test.mjs` regresses signed-query
  canaries against proxy stdio (not DOM). Applying Set-Cookie after
  `writeHead` throws `ERR_HTTP_HEADERS_SENT` on Node 22 and killed the host;
  `tests/e2e/admin-web-server.test.mjs` locks the ordering. Empty POST bodies
  such as logout send `Content-Type: application/json` so EnforceRequestBounds
  does not return 415. The Admin web Browser job starts Laravel with `php -S`
  so the HTTP process inherits SESSION_DRIVER/DB_* from the job environment;
  `php artisan serve` only forwards a short env whitelist to its child. The unauthorized actor is an active secretary
  (`admin_web` cookie session, `/me` allowed, no `verification.case.review`).
  A `password_must_change` admin cannot bootstrap `/me` (existing
  DenyPendingBusinessAccess 404) and is not used for the unauthorized panel.
  The `/api` proxy restores RFC `Cookie` casing from `rawHeaders` and sets
  `Host` to the Laravel listener. PHP's built-in server matches `Cookie`
  case-sensitively; Node's `IncomingMessage.headers` is lowercase. The Admin
  web job invokes `${GITHUB_WORKSPACE}/tests/e2e/php-built-in-router.php`
  with cwd `apps/core-api/public` (a `../../tests/...` relative path from
  public/ would resolve under `apps/`, not the repo root) so `$_COOKIE` is hydrated
  from `getallheaders()` before Laravel boots. Cookie API auth is HMAC-primary
  (`cookie:{laravelSessionId}`); `login_web_*` is not required. If Laravel
  rotates the session id while the web guard still has a user, the latest
  admin cookie row is rebound once.
- Seeder command `e2e:seed-admin-verification` is local/testing only and
  writes credentials under `/tmp`.

## Residual risks

- Signed reviewer downloads depend on the in-memory/persisted test object
  store when `APP_ENV=testing`. Production remains S3 + HMAC streaming from
  chunk 05.
- RTL uses `html[dir]` + MUI `theme.direction`. Emotion stylis RTL plugin was
  not enabled (untyped stylis in this toolchain).
- Browser E2E uses `APP_ENV=testing` so the fixture seeder can persist
  canonical bytes across the artisan serve process. That is not a production
  configuration.
- Playwright traces/HAR (if captured) are browser artifacts, not the
  preview/php-S CI tails covered by the stdio canary. Application
  `storage/logs/laravel.log` still depends on PatternRedactor for signed-URL
  strings. If `redact-e2e-stdio.mjs` exits, the php -S pipe receives SIGPIPE.

GitHub CI on the final HEAD is recorded on the Draft PR after the closing
push of this chunk.
