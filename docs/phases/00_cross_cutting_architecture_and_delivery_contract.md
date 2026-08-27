# Phase 00 — Cross-Cutting Architecture and Delivery Contract

## Objective

Create the technical foundation that makes every later phase safe to implement: repository layout, enforceable module boundaries, shared contracts, local/staging environments, continuous integration, observability baseline, data classification, threat model, test harnesses, and release conventions.

This phase delivers no clinical feature. Its observable outcome is that a minimal authenticated health/readiness slice can travel from each client through the API, database/Redis adapters, outbox worker, and telemetry pipeline in a reproducible environment without granting access to real patient data.

## Plan traceability

- Sections 1-5: final system shape, modular-monolith style, source stack, Flutter mobile, and React admin. [ADR 0010](../adr/0010-electron-react-typescript-desktop-clients.md) supersedes the source's doctor/pharmacy Flutter Desktop choice with Electron, React, and TypeScript.
- Sections 99 and 102-115: realtime shape, queues, Horizon, outbox, API rules, idempotency, PostgreSQL, IDs, indexes, partitioning, Redis, and cache strategy.
- Sections 132-142: performance/capacity targets, horizontal topology, connection pooling, AI worker separation, availability, and health checks.
- Sections 148-155: localization/country/location conventions and client failure/offline behavior.
- Sections 156 and 160-170: test pyramid, load tooling, environments, Docker, CI, migrations, secrets, and feature flags.
- Sections 171-176: V1 exclusions, data ownership, consistency, background-work rule, implementation order, and final definition of done.

## Entry criteria

- `plan.md` is accepted as the V1 product/architecture source.
- Engineering agrees that production infrastructure sizing is a benchmark hypothesis, not a guarantee.
- Product identifies accountable clinical, pharmacy, privacy, security, and operations contacts. Legal approval is not an entry criterion or implementation dependency.

## Non-goals

- No production tenant or real patient data.
- No microservice decomposition of the Laravel core.
- No online payment, emergency specialist chat, medication alternatives/reservation, branch transfer, supplier API, adherence tracking, image diagnosis, multi-country behavior, or complex admin roles.
- No AI feature beyond an isolated stub health contract; AI implementation starts in Phase 16.
- No premature table partitioning, sharding, Elasticsearch/OpenSearch, or Kubernetes requirement.

## Architecture decisions

### System boundaries

```text
Flutter patient mobile     Electron doctor/pharmacy     React admin web
            \                    |                    /
             \________ HTTPS + JSON/OpenAPI ________/
                   API gateway / load balancer
                              |
                 Laravel modular monolith
                  |       |       |       |
             PostgreSQL Redis  Reverb  private S3
                  |
        transactional outbox -> queue consumers

                 isolated internal contract
                              |
                       FastAPI AI service
                              |
                      Qdrant + model integrations
```

- The Laravel process owns authentication, authorization, operational state, medical state, financial state, tenant scoping, and tool authorization.
- FastAPI may read only the minimum authorized context provided through an internal service contract. It cannot connect as a broad application user or mutate core tables.
- Clients never connect directly to PostgreSQL, Redis, S3, Qdrant, or provider APIs.
- Direct module-to-module table writes are prohibited. A module calls a public service on the owning module or consumes a published Laravel/integration event.

### Repository layout

Use one repository initially so contracts and cross-client changes can be atomic while deployment units remain independent.

```text
apps/
  core-api/                 # Laravel 13 modular monolith
  ai-service/               # FastAPI API and workers
  admin-web/                # Browser React + TypeScript
  patient-app/              # Flutter Android/iOS
  doctor-desktop/           # Electron + React + TypeScript
    src/main/               # privileged application and OS adapters
    src/preload/            # small contextBridge capability surface
    src/renderer/           # sandboxed React presentation
  pharmacy-desktop/         # Electron + React + TypeScript
    src/main/
    src/preload/
    src/renderer/
packages/
  flutter/
    api_client/
    authentication/
    common_models/
    design_system/
    error_handling/
    local_database/
    localization/
    networking/
    notifications/
    realtime/
    secure_storage/
  typescript/
    api_client/
    desktop_bridge_contracts/
    design_tokens/
    error_handling/
    localization/
  contracts/
    openapi/
    events/
    ai_internal/
infra/
  docker/
  environments/
  monitoring/
  load-tests/
docs/
  adr/
  architecture/
  data-classification/
  runbooks/
  threat-models/
```

Flutter workspace management uses Melos for the patient mobile app and its shared Dart packages. The admin web app, both Electron desktop apps, and reviewed pure TypeScript packages use repository-standard JavaScript workspaces with one committed lockfile. Python uses a `pyproject.toml` plus a locked resolution. PHP uses Composer with a committed `composer.lock`.

### Laravel module layout

Use `nwidart/laravel-modules` with its top-level `Modules/` directory. Each business module is a conventional mini Laravel application:

```text
Modules/<Name>/
  app/
    Enums/                  # optional backed enums for finite states/types/reasons
    Events/
    Http/
      Controllers/
      Requests/
      Resources/
    Jobs/
    Listeners/
    Models/
    Policies/
    Providers/
    Services/
    Contracts/              # only genuinely replaceable external dependencies
  config/
  database/
    factories/
    migrations/
    seeders/
  resources/
  routes/
  tests/
  composer.json
  module.json
```

- Controllers authenticate/authorize, use Form Requests, call one descriptive module service method, and map the result through an API Resource, redirect, or Inertia response. They contain no multi-step business workflow.
- Services coordinate business rules, Eloquent models, transactions, idempotency, cross-module calls, and post-commit work.
- Models own relationships, casts, scopes, and small model-local behavior. Use PHP backed enums for stable finite states, types, channels, roles, or reasons when they make validation and casts clearer.
- External SDK/provider code lives in a descriptive service or integration class. Add an interface under `app/Contracts` only when the dependency is genuinely replaceable or has multiple implementations.
- Policies receive a typed authorization context. They must not depend on a client-supplied role, tenant, doctor, patient, or scope identifier.
- Jobs carry stable IDs and schema versions, reload current state, re-authorize where needed, and are idempotent.

Do not create or extend `Domain`, `Application`, or `Infrastructure` directories, command/query buses, handler-per-action trees, aggregates, generic repository wrappers around Eloquent, DDD value-object trees, or `*Port` types. Existing DDD-shaped code is migrated module by module into this layout as it is touched, preserving behavior and tests.

Use architecture tests to reject the removed DDD directories, module dependency cycles, generic base repositories/services, and direct writes to another module's tables.

### Cross-module transaction coordination

Critical workflows that span modules are not implemented as eventually consistent event chains. Booking, consultation start/end, prescription exposure, purchase receipt, POS sale/cancellation, and refund require one explicit coordinating Laravel service:

1. The coordinating service owns the use-case contract and `DB::transaction()` boundary.
2. It calls descriptive public services in the participating modules; it never updates another module's table directly.
3. Participating services share the same database transaction and return explicit result data rather than exposing query builders.
4. Laravel events required after commit are represented in the transactional outbox before commit.
5. External/realtime/notification/analytics work begins only after commit.
6. Failure in any strong-consistency step rolls back the whole workflow; compensation is reserved for effects that cannot share the database transaction.

Examples:

- `BookAppointmentService`: Appointments owns availability/booking; Identity supplies the patient reference; the service commits appointment, slot constraint, status event, audit, idempotency, and outbox atomically.
- `StartConsultationService`: Queue validates checked-in eligibility; Clinical creates the encounter/access grant; Appointments changes state; the service commits the sanitized current-patient outbox event in the same transaction.
- `CompleteConsultationService`: Clinical finalizes the encounter and revokes access; Queue advances; Appointments completes; Chat records its future write window; the service commits outbox notifications with those changes.
- `CompleteSaleService`: POS validates cart/payment intent; Inventory allocates FEFO and appends movements; the service commits the invoice/payment and outbox together.

Integration tests must prove that the coordinating service commits or rolls back all participating writes and that event listeners cannot perform a delayed write required for the originating invariant.

### Client architecture

The Flutter patient-mobile app uses feature presentation, state, and data/integration layers:

```text
presentation -> feature controller/state -> typed API/local data client
```

- Riverpod provides dependency wiring and observable state in the patient app.
- UI state is not the authority for permissions or final business state.
- Typed data clients own caching, mapping, error normalization, and safe retry decisions.
- Generated API DTOs stay at the network edge and map into feature/view models.
- Local mobile databases store the minimum needed, encrypt sensitive rows/database files, and expose sync state.

Each Electron desktop app uses an explicit privilege boundary:

```text
React renderer -> typed contextBridge capability -> preload -> main/utility adapter
                                                        |-> HTTPS/realtime
                                                        |-> encrypted local store
                                                        |-> file/print/notification/update OS APIs
```

- The React renderer is an unprivileged presentation process that loads packaged local assets only. It has no Node.js integration, token/database-key access, filesystem access, or raw IPC channel.
- Production windows use `contextIsolation: true`, renderer sandboxing, a strict Content Security Policy, and deny-by-default navigation, new-window, permission, download, and external-protocol handlers.
- Packaged assets load from a privileged standard-scheme custom application protocol with an exact origin rather than permissive `file://`; renderer sessions and caches cannot persist clinical data.
- Preload exposes one TypeScript method per capability through `contextBridge`. IPC request/response schemas are size-bounded and validated; never expose `ipcRenderer`, arbitrary URLs/paths/channels/SQL, Node globals, or provider SDKs.
- Main and optional utility processes implement narrow typed clients/services for device credentials, generated OpenAPI transport, realtime, encrypted drafts, files, printing, local notifications, and signed updates. They contain no clinical/pharmacy business-rule bypass.
- The doctor local outbox stores typed commands and explicit sync state. Pharmacy stock, POS, payment, and other authoritative mutations remain online-only.

React renderers use feature folders with route/page, components, schema, query/mutation hooks, and generated API types. TanStack Query owns server state. Component state remains local unless a proven cross-route need exists. Pure components, design tokens, localization helpers, and generated contract mappings may be shared only through reviewed capability packages. Electron desktop and admin web authentication/transport adapters remain separate: desktop uses device-token capabilities outside the renderer, while admin uses HttpOnly cookies and CSRF. Authorization in every UI affects discoverability only; Laravel remains authoritative.

### Shared request flow

Every externally reachable mutation follows this order:

1. Gateway enforces TLS, request-size limits, and coarse abuse controls.
2. Middleware assigns/validates a correlation ID and authenticates the session/device token.
3. Route/request schema rejects malformed, unknown, oversized, or semantically invalid fields.
4. The policy loads server-owned actor/resource/context and returns allow or a generic denial.
5. The module service checks the idempotency record when the operation is replayable.
6. The service and model validate the transition using current persisted version/state and any applicable backed enum.
7. A bounded database transaction writes authoritative records, audit metadata, and any required outbox event.
8. Commit succeeds or the entire critical change rolls back.
9. The idempotency response is finalized without storing secrets/large clinical payloads.
10. The API returns the stable envelope immediately.
11. Workers claim outbox rows, execute bounded provider calls, record delivery attempts, and mark success/retry/dead-letter.

No HTTP request waits for PDF extraction, OCR, embeddings, push delivery, analytics aggregation, external inventory sync, backups, or other unbounded work.

### API contract

- Prefix public endpoints with `/api/v1`.
- OpenAPI is the source of truth for HTTP shape. CI validates it, detects breaking changes, and regenerates typed clients.
- Response envelope:

```json
{
  "data": {},
  "meta": {},
  "errors": [],
  "request_id": "uuid-v7"
}
```

- Use RFC 3339/ISO-8601 UTC timestamps with explicit offsets; never ambiguous local date-times.
- Use cursor pagination for large/mutable collections. Cursors are opaque, signed if they contain state, size-bounded, and scoped to filter/order/actor.
- Use `400` for malformed protocol/input, `401` for unauthenticated, `403` for denied, `404` when hiding resource existence is safer, `409` for state/version/idempotency conflict, `422` for field/business validation, `429` for throttling, and `5xx` for server/dependency failure.
- Error responses expose stable machine codes and safe human messages, never stack traces, SQL, object keys, provider payloads, or protected resource existence.
- Money is `{amount_minor: integer, currency: "EGP"}`. UUIDv7 identifiers are strings. Quantities include a unit identifier.
- Contract changes are backward-compatible first; removals require deprecation telemetry and a later phase/release.

### Events and outbox

Event envelope:

```text
event_id UUIDv7
event_type namespaced past-tense name
schema_version positive integer
aggregate_type / aggregate_id
occurred_at UTC
actor_id nullable
correlation_id / causation_id
payload minimal and classified
```

Outbox processing logic:

1. Insert the business change and outbox row in one PostgreSQL transaction.
2. A worker claims rows with `FOR UPDATE SKIP LOCKED` or an equivalent safe claim.
3. Publish/handle using `event_id` as the consumer idempotency key.
4. Record attempt count, next attempt, last safe error class, and processed timestamp.
5. Retry only transient failures with capped exponential backoff plus jitter.
6. Move exhausted failures to an operator-visible dead-letter state; do not silently discard.
7. A repair command can replay an explicitly selected event/range without creating duplicate effects.

Events carry identifiers and required non-sensitive facts, not entire patient, prescription, lab, or AI payloads.

### Queue ownership across PHP and Python

Laravel Horizon consumes Laravel-owned Redis jobs only. Python workers must never deserialize or acknowledge Laravel/PHP job payloads.

- Laravel lanes (`critical`, `notifications`, `files`, `integrations`, `analytics`, `reports`, `backups`, and Laravel-side AI orchestration) use Horizon and PHP job classes.
- Laravel starts Python work through an authenticated, versioned internal HTTP command or an ADR-approved typed JSON message envelope carrying an idempotency key, deadline, schema version, and minimal object references.
- FastAPI persists/queues that request through an AI-owned `TaskQueue` interface. Its implementation may use a dedicated Redis namespace/instance and a Python-native worker library selected in Phase 16.
- Python workers return status/result references through an authenticated callback or a polled internal resource. They never write Laravel core tables or consume PHP serialization.
- Both sides retain independent retry budgets. A cross-boundary duplicate resolves through the same idempotency key, and a timeout is an unknown outcome to reconcile, not permission to create a second task.

### Idempotency contract

Apply to booking, check-in, consultation completion, prescription finalization/amendment, purchase receipt, POS sale, cancellation, return/refund, and external synchronization.

1. Client generates a cryptographically random `Idempotency-Key` per user intent and reuses it only for retries of the identical request.
2. Server scopes the key to authenticated actor/device, endpoint/operation, and tenant where applicable.
3. Server stores a canonical request hash, state (`PROCESSING`, `SUCCEEDED`, `FAILED_RETRYABLE`), status code, safe response reference, and expiry.
4. Same key + same hash returns the original outcome.
5. Same key + different hash returns `409 IDEMPOTENCY_KEY_REUSED`.
6. A concurrent duplicate waits briefly or returns `409/202` with a polling reference; it never starts a second transition.
7. Permanent validation/authorization failure is not cached as a successful business result.
8. Retention exceeds the maximum client retry/offline window for that operation.

### Database and consistency conventions

- PostgreSQL/PostGIS is authoritative; use relational columns for filtered/joined invariants and JSONB only for bounded extensible metadata.
- IDs are UUIDv7 and public APIs never expose sequential identifiers.
- Every mutable workflow record uses a version number or compare-and-set condition where stale writes could lose data.
- Foreign keys, check constraints, unique/exclusion constraints, and transaction isolation enforce invariants even if application checks race.
- Store instants in UTC and schedule intent with IANA time zone (`Africa/Cairo` initially).
- Use `citext`/normalized values only where semantics are defined. Never locale-lowercase national IDs, barcodes, or opaque identifiers.
- Migrations follow expand -> deploy -> backfill -> switch -> contract. A migration must not take an unbounded lock on a high-volume table.
- Seed only synthetic data. Staging cannot clone raw production medical data.
- Strong consistency applies to access, appointments, medical records, prescriptions, payments, invoices, movements, and refunds. Notifications, analytics, indexes, and mirrors are eventual.
- Do not partition tables until measured volume/query evidence justifies it. Candidate tables remain audit events, deliveries, AI usage, stock movements, and appointment events.

### Cache and Redis conventions

- Redis is used for cache, rate limits, locks, queues, realtime pub/sub, and short-lived coordination only.
- Every cache entry declares owner, key shape, classification, TTL, invalidation trigger, maximum payload, and behavior when missing/stale.
- Avoid PHI caches. A reviewed exception must encrypt content, sharply bound TTL and access, and prove deletion/invalidation.
- Distributed locks have an owner token, short lease, bounded wait, and safe release. Database constraints remain the final concurrency defense.
- Production separates queue Redis from cache/realtime Redis once load evidence or production tier requires it.
- An empty Redis after restart must degrade performance temporarily, not lose medical/business truth.

### Configuration, secrets, and feature flags

- Validate required configuration at process startup; readiness stays false when a critical configuration is invalid.
- Secrets live in an approved secret manager, are injected at runtime, are never committed, and have owners/rotation procedures.
- Separate credentials per environment and workload. AI, queue, migrations, read-only reporting, and backup jobs do not share broad credentials.
- Feature flags are server-owned, environment-aware, audited, and fail closed for risky features. Define the future flags listed in `plan.md` as disabled metadata only.
- Never use a client flag to bypass authorization or activate a server capability.
- Container images and dependencies use immutable versions/digests. Generate an SBOM and scan before promotion.

## Packages and tools

Versions are resolved during implementation against Laravel 13 and the current supported Flutter-mobile, Electron/Node/Chromium, React/TypeScript, and Python runtimes, then pinned in lockfiles. Do not copy unverified version ranges from this document.

### Laravel/PHP baseline

- `laravel/framework`, `laravel/sanctum`, `laravel/horizon`, `laravel/reverb`, `laravel/octane`, and FrankenPHP runtime.
- `nwidart/laravel-modules` v13-compatible release, installed with `composer require nwidart/laravel-modules`; enable `wikimedia/composer-merge-plugin`, include `Modules/*/composer.json`, publish `config/modules.php`, and commit the lockfile/module status configuration.
- `laravel/pennant` for server feature flags.
- `brick/money` for exact money calculations; Symfony UID/Laravel UUIDv7 support for identifiers.
- Architecture tests for module ownership, service boundaries, and rejection of the removed DDD directory layout.
- Pest or PHPUnit as one repository-standard test runner; Laravel HTTP/database fakes; Mockery only at external boundaries.
- Larastan/PHPStan for static analysis and Laravel Pint for formatting.
- Laravel Telescope (local/non-production request inspection only) and Sentry Laravel SDK, configured with centralized redaction.

### Flutter mobile baseline

- `melos`, `flutter_riverpod`/Riverpod code generation, `dio`, `go_router`, `freezed`, `json_serializable`, `drift`, `flutter_secure_storage`, `firebase_messaging`, `intl`, and `connectivity_plus`.
- Drift over `sqlite3` v3 configured through native hooks for SQLCipher or SQLite3MultipleCiphers. Do not adopt the end-of-life `sqlcipher_flutter_libs` package. Adoption still requires a target-OS compatibility spike plus documented key rotation, recovery, backup exclusion, and migration tests.
- `flutter_test`, SDK `integration_test`, `mocktail`, golden-test tooling, and Patrol only for flows needing native dialogs/notifications.
- `very_good_analysis` or an ADR-approved lint profile, applied consistently across the patient app and shared mobile packages.

### Shared React and TypeScript baseline

- React, TypeScript in strict mode, TanStack Query, React Router, React Hook Form, Zod, Hook Form resolvers, MUI, and i18next. Vite is the admin-web bundler. ECharts is limited to approved admin analytics.
- OpenAPI-generated types/client or `openapi-typescript` + `openapi-fetch`; separate desktop and admin transport adapters own their different authentication, request-ID, cancellation, and error behavior.
- Vitest, React Testing Library, MSW, browser Playwright, and axe-core integration for tests/accessibility.
- ESLint, type-aware rules, Prettier, and dependency-boundary linting.

### Electron desktop baseline

- Electron with React and strict TypeScript; Electron Forge's maintained Webpack/TypeScript pipeline is the default build, packaging, and maker workflow. Required packages are `electron`, `@electron-forge/cli`, the approved Forge Webpack/fuses/auto-unpack-natives plugins and OS makers, `@electron/fuses`, and `@electron/rebuild`. Exact versions are pinned after the target-OS spike. Using an Electron Vite plugin requires a compatibility ADR because its official Forge integration remains experimental.
- Renderer packages are React DOM, TanStack Query, React Router, React Hook Form, Zod, Hook Form resolvers, MUI/Emotion, `i18next`, and `react-i18next`. Contract packages are `openapi-typescript` and `openapi-fetch`; `laravel-echo` and `pusher-js` are isolated behind the main-owned Reverb adapter after a Node/Electron compatibility spike.
- Production `BrowserWindow` configuration fixes `nodeIntegration: false`, `contextIsolation: true`, and `sandbox: true`. The application loads no remote renderer content.
- `contextBridge` exposes narrow typed capabilities backed by allowlisted `ipcMain` handlers. Zod validates IPC at both trust-boundary ends; raw Electron/Node modules never enter renderer feature code.
- Electron `safeStorage` or an ADR-approved OS-keystore adapter wraps device credentials and database keys outside the renderer. Sensitive persistence fails closed if encryption is unavailable or Linux reports the insecure `basic_text` backend.
- The Phase 05 encrypted database uses a pinned Node SQLite adapter built against approved SQLCipher or SQLite3MultipleCiphers only after native ABI, packaging, rotation, migration, recovery, and backup tests pass. Database access stays in main/utility infrastructure and exposes intent-named operations, not SQL.
- Generated HTTP and Reverb clients execute through main/utility services using Electron networking or an approved nonpersistent session so system proxy/TLS behavior is preserved. Renderer TanStack Query functions call typed preload capabilities and never issue authenticated HTTP/WebSocket requests directly.
- `@electron/fuses` disables unused privileged runtime features before signing. Packaged artifacts receive CSP/navigation/permission/new-window checks, dependency/SBOM scans, signing/notarization, and installed-package smoke tests.
- WebdriverIO with `@wdio/electron-service` is the default packaged-app E2E harness. Playwright's experimental Electron launcher may be used only after a pinned compatibility spike. Native dialogs, printing, keystore, encrypted database, installers, signing, and updates require deterministic main-process and installed-package tests on each supported OS regardless of UI harness.

### React admin web baseline

- The admin remains browser-hosted, uses secure HttpOnly/Secure/SameSite cookies plus CSRF, and never shares Electron device-token, preload, IPC, local-database, file, update, or shell adapters.

### AI baseline (scaffold only in this phase)

- FastAPI, Pydantic, `pydantic-settings`, HTTPX, and Uvicorn/Gunicorn-compatible deployment.
- `pytest`, `pytest-asyncio`, `respx`, and Hypothesis.
- Provider/Qdrant/model packages are added in Phase 16 behind small provider interfaces.

### Platform and assurance

- Docker/Compose for local integration; GitHub Actions for CI.
- PostgreSQL with PostGIS, PgBouncer where deployed, Redis, private S3 emulator for local tests, and Qdrant only for the AI profile.
- k6 for API/WebSocket/load suites.
- Gitleaks for secrets, Semgrep plus language-native SAST, dependency audits, Trivy for image/IaC scans, Syft-compatible SBOM, and OWASP ZAP for staged DAST.
- Prometheus, Grafana, Loki-compatible structured logs, Laravel Telescope (local), and Sentry.

## Detailed implementation work

### 1. Record architecture and ownership

1. Create ADRs for modular monolith + separate AI service, repository layout, API-first contracts, outbox, UUIDv7, local encryption, and data ownership.
2. Create C4 context/container diagrams and one component diagram showing module service dependencies.
3. Create a module catalog with owner, public services/events, tables, data classification, and prohibited direct writes.
4. Define CODEOWNERS/review rules for clinical, pharmacy-financial, identity, infrastructure, and AI safety areas.
5. Add an automated architecture check that fails on a known forbidden dependency fixture, then remove/disable only the fixture after proving the check.

### 2. Bootstrap runtime projects

1. In `apps/core-api`, run `composer require nwidart/laravel-modules`, enable the package's Composer merge plugin, include `Modules/*/composer.json`, publish `config/modules.php`, run `composer dump-autoload`, and verify `php artisan module:list`.
2. Keep Core modules at `apps/core-api/Modules/<Name>/` under `nwidart/laravel-modules`. Do not reintroduce `app/Modules` or `Domain/Application/Infrastructure` trees.
3. Inventory the current doctor/pharmacy Flutter scaffolds, shared-package imports, CI paths, application identifiers, signing placeholders, and any user-authored or local-data migration need. Record the disposition of every item before replacement; never silently delete a real desktop database.
4. In one reviewed Phase 00 migration, remove `apps/doctor-desktop` and `apps/pharmacy-desktop` from the Dart/Melos workspace, keep `apps/patient-app` and patient-owned Flutter packages there, scaffold two independent Electron Forge/React/TypeScript apps at the same application paths, and add them plus reviewed `packages/typescript/*` packages to the root npm workspace. Do not leave two runtimes mixed inside either desktop app.
5. Give doctor and pharmacy different application IDs, executable/product names, user-data directories, protocol schemes, encrypted-database namespaces, device-credential namespaces, IPC capability registries, installer metadata, and update channels. A shared pure TypeScript package must not collapse those security contexts.
6. Update path-filtered CI, CODEOWNERS, generated-client destinations, workspace scripts, lockfiles, dependency-boundary rules, local Compose launch helpers, and evidence templates in the same migration. Prove only the patient app is selected by Flutter/Melos and both desktop apps are selected by npm/Electron jobs.
7. Scaffold every remaining deployment unit without feature code. Electron health/version screens query main-owned typed capabilities; the renderer does not connect to the API directly.
8. Add `/live`, `/ready`, build/version metadata, structured error handling, correlation IDs, and graceful shutdown to services.
9. Ensure liveness checks only process health; readiness checks critical startup state without turning every optional dependency into a core outage.
10. Demonstrate that AI/Qdrant readiness failure does not make Laravel core unready.
11. Configure Octane workers to avoid request-scoped mutable state leaking across requests; add a regression test using two synthetic identities.

### 3. Establish contract workflow

1. Create a minimal OpenAPI document with health endpoints and the common envelope/error schemas.
2. Validate/lint it in CI and generate a Dart patient-mobile client plus TypeScript Electron-desktop/admin clients.
3. Add a breaking-change detector against the main-branch contract.
4. Define event JSON Schemas and compatibility rules: additive optional fields within a version; breaking changes require a new schema version and dual-read migration.
5. Add provider contract-test suites that future adapters must implement.

### 4. Establish persistence and migrations

1. Configure PostgreSQL/PostGIS with least-privilege app and migration roles.
2. Add framework tables only when needed; create migration conventions and transactional migration checks.
3. Implement reference abstractions for UUIDv7, clock, transaction runner, pagination cursor, money, country/currency, and safe identifiers.
4. Implement generic outbox and idempotency storage with cleanup/retention jobs.
5. Add Redis namespaces/connections for cache, rate limit, queue, and realtime, even if local Compose uses one instance.
6. Confirm a Redis flush loses no authoritative record and that the app can warm required caches.

### 5. Establish data protection

1. Define classification levels: public, internal, personal, sensitive personal/clinical, credential/secret.
2. For every initial table/event/log/metric, document classification, purpose, lawful/business need, access roles, retention, encryption, and deletion/anonymization policy owner.
3. Add a logging redaction processor and tests containing canary national IDs, phones, tokens, passwords, clinical text, and object keys.
4. Configure TLS for all non-local hops, database/Redis/Qdrant private networking, private object storage, and encrypted volumes/backups where applicable.
5. Create synthetic Egyptian-format data generators that do not accidentally produce known real identities.

### 6. Establish CI and environments

Pull-request pipeline:

1. Format and lint each language.
2. Compile/type-check and run architecture rules.
3. Validate OpenAPI/event schemas and generated clients.
4. Run unit/component suites, then containerized integration suites.
5. Run secret scanning, SAST, dependency license/vulnerability audits, IaC/container scanning, and SBOM generation.
6. Build immutable artifacts once; do not rebuild between staging and production. Electron lanes rebuild native addons for each approved Electron/OS/architecture tuple, inspect BrowserWindow/CSP/fuses, run packaged smoke tests, and emit unsigned verification artifacts without exposing signing credentials to untrusted pull requests.

Post-merge pipeline:

1. Sign/notarize the already-tested Electron artifacts in the protected release lane, attach immutable manifests and SBOMs, and push all signed images/artifacts to the registry without recompiling application code.
2. Deploy to isolated development/staging environment.
3. Run backward-compatible migrations with timeout/lock monitoring.
4. Execute smoke, contract, authorization-canary, and observability checks.
5. Keep production promotion manual until Phase 23 gates pass.

### 7. Establish observability

1. Propagate `traceparent`, request ID, correlation ID, causation ID, actor pseudonymous ID, and service/version fields without PHI.
2. Instrument request rate/error/latency, DB pool and query latency, Redis errors, queue depth/age/failures, outbox backlog, Reverb connections, and provider failures.
3. Bound metric labels; never use patient, doctor, appointment, file, prescription, or free-text values as labels.
4. Define alerts with owner, severity, threshold, sustain period, runbook, and false-positive review.
5. Confirm traces/logs from a synthetic clinical-looking request are redacted before leaving the process.

## Security and privacy work

Create the initial STRIDE + privacy threat model across these trust boundaries:

- public mobile/desktop/web clients to gateway;
- Electron renderer to preload/main/utility capabilities and OS resources;
- admin browser session and CSRF boundary;
- gateway to Laravel workers/Reverb;
- Laravel to PostgreSQL/Redis/S3/providers;
- outbox/queue producers to consumers;
- Laravel to FastAPI and FastAPI to Qdrant/model providers;
- developer/CI/staging/production administrative planes.

At minimum, address broken object/tenant authorization, stolen device tokens, CSRF/XSS, replay, mass assignment, injection, SSRF, malicious files, event forgery, queue duplication, cache poisoning, log leakage, insecure defaults, dependency compromise, backup exposure, prompt injection, excessive AI agency, denial of service/wallet, and cross-environment data leakage.

Mandatory controls in this phase:

- deny-by-default network and application policies;
- strict request/content-size limits and safe parsers;
- no wildcard production CORS origins;
- secure response headers for admin web;
- Electron sandbox/context isolation, local-only renderer content, strict CSP, narrow validated IPC, and deny-by-default navigation/permissions;
- secrets never available to fork/PR jobs from untrusted code;
- non-root/read-only containers where compatible;
- dependency/SBOM provenance and vulnerability policy;
- audit trail for configuration/flag/secret-access changes;
- centralized redaction tested before telemetry export;
- documented emergency credential rotation and artifact revocation.

Maintain versioned verification mappings to OWASP ASVS 5.0.0 for the web/API platform, OWASP API Security guidance for object/function/property authorization and abuse resistance, and OWASP MASVS/MASTG for Flutter mobile releases. Use the NIST AI Risk Management Framework and its Generative AI Profile as an AI risk/evidence taxonomy from Phase 16 onward. These are engineering assurance baselines, not declarations of statutory compliance. Record Egyptian privacy, healthcare, pharmacy, electronic-prescription, retention, and cross-border-processing assumptions as conservative configurable project decisions; legal review is optional and never blocks implementation or phase completion.

## Test plan

### Unit tests

- UUIDv7, money, quantity, Cairo time conversion/DST, pagination cursor, safe error mapping, canonical request hashing, retry classifier, and redaction functions.
- Architecture tests prove modules use the conventional controller/service/model layout and reject reintroduction of `Domain/Application/Infrastructure` trees.
- Idempotency state logic handles same/different hashes, concurrent processing, expiry, and retryable failure.
- Outbox retry schedule is capped, jittered, and distinguishes permanent failures.

### Integration tests

- Laravel with real PostgreSQL/PostGIS and Redis: transaction rollback, outbox atomicity, worker claiming, duplicate consumption, lock expiry, cache loss, and migration forward compatibility.
- S3-compatible store: private objects, encryption metadata, signed URL expiry, and denied anonymous access.
- Reverb private-channel authorization scaffold and disconnect behavior.
- FastAPI stub internal authentication, deadline propagation, and unavailability isolation.
- Redaction and bounded HTTP attributes are proven in-process (`ExportRedactionTest`); Telescope never ships on the production migration path.

### Contract tests

- OpenAPI validation plus generated Dart patient-mobile and TypeScript desktop/admin clients against a running API.
- Common error/status/envelope/cursor/time/money schemas.
- Event consumers accept current and previous compatible event schemas and reject unknown incompatible versions safely.
- Every fake/provider integration passes its owned interface contract.

### End-to-end tests

- Each of four clients starts against the local/staging stack and shows core health/version in Arabic and English.
- Admin browser establishes CSRF/session plumbing without storing a token in local storage.
- Flutter patient test client and both Electron desktop test clients obtain only their synthetic scoped connection and handle `401`, `409`, `422`, `429`, and safe `5xx` presentation.
- Electron packaged-window tests prove renderer sandbox/context isolation, no Node integration, strict CSP, typed IPC denial, safe navigation/window/permission behavior, and unavailable-keystore failure before any sensitive local state is enabled.
- A committed synthetic event reaches a test consumer exactly once in effect despite forced duplicate delivery.

### System tests

- Stop AI/Qdrant: core health and non-AI smoke flow remain available; AI readiness reports degraded.
- Flush Redis/restart workers: authoritative data remains and queues/outbox resume without duplicate effects.
- Kill a worker during outbox processing: another worker recovers after lease expiry.
- Roll one backward-compatible schema change across mixed old/new app versions.
- Validate graceful shutdown under active HTTP/WebSocket/queue load.
- Install and launch each doctor/pharmacy artifact on every approved OS/architecture; verify separate app IDs/user-data roots, native-addon ABI loading, safe-storage availability, CSP/fuses, uninstallation behavior, and clean failure when a strong keystore is unavailable.

### Security tests

- SAST, dependency, image, IaC, license, SBOM, and secret scans enforce blocking severity policy.
- DAST smoke checks TLS, headers, CORS, error leakage, method handling, and size limits.
- Attempt direct access to database, Redis, S3, Qdrant, and internal AI services from the public network segment; all fail.
- Send national ID, token, password, prescription-like text, and lab-like content canaries; none appears in logs/traces/errors.
- Fuzz common schemas/cursors/identifiers and prove bounded CPU/memory and safe rejection.
- Inject a deliberate renderer import of Node/Electron and a generic preload IPC method; dependency/capability checks must reject both. From a renderer XSS fixture, attempts to read tokens/keys, navigate, open a window/protocol, request a permission, reach the filesystem/shell, or invoke an unregistered/oversized IPC operation all fail safely.

## Acceptance and exit gate

- Repository and module dependency rules are documented and enforced in CI.
- The Dart/Melos workspace contains the patient mobile app only; npm workspaces contain the admin web and both Electron desktops. No doctor/pharmacy Flutter runtime or mixed desktop scaffold remains after the controlled migration, and the inventory proves whether any pre-existing user data needed migration.
- All deployment units build reproducibly from locked dependencies; images/artifacts have SBOMs and no unaccepted critical findings.
- Signed-candidate Electron configuration fixes sandbox/context isolation/no Node, loads local content only, exposes no generic IPC, passes target-OS installed-package tests, and includes Electron/Chromium/Node/native-addon provenance in its SBOM.
- Local and staging environments start from documented commands using synthetic data only.
- OpenAPI/event workflows generate clients and reject a deliberate breaking change.
- Outbox and idempotency reference flows pass concurrency/replay tests.
- Health/readiness, structured redacted telemetry, dashboards, and runbooks operate.
- AI/Qdrant/Redis failure-isolation system tests pass.
- Threat model and data classification have repeatable security/privacy evidence; legal approval is not required.
- Every test category above has automated evidence; manual-only evidence has an owner and repeatable script/checklist.
- No V1-excluded feature is functionally enabled.

## Deliverables

- Repository skeleton and module catalog.
- ADR set, diagrams, contract conventions, schema registries, and package/version policy.
- Docker/local/staging profiles and CI pipelines.
- Minimal Laravel/FastAPI, Flutter patient-mobile, Electron doctor/pharmacy desktop, and React admin-web health slices.
- Outbox, idempotency, correlation, redaction, and observability foundations.
- Test harnesses, security baseline, threat model, data inventory, and runbooks.
