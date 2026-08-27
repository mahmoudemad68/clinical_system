# Phase 14 — Medicine Search and Prescription Fulfillment Discovery

## Objective

Deliver patient-facing medicine discovery using the authoritative medication catalog, branch availability, PostGIS distance, and active published prescriptions.

Manual search returns nearby branches that report a medication available, without exposing price or exact stock quantity. Prescription discovery resolves all medication items in one bounded query, ranks branches by prescription coverage descending and distance ascending, and also supports per-medication results. It is discovery only: no reservation, sale, payment, substitution, or inventory mutation occurs.

Published/finalized prescriptions are already immutable. Opening Find My Medicines records an exposure/access audit event but never changes whether the prescription may be edited.

## Plan traceability

- Sections 34-36, lines 1208-1298: prescription immutability, amendments, exposure/access audit, and original-version preservation.
- Sections 48-55, lines 1607-1823: branch inventory, medication identity, packaging, batches, FEFO, and available balance.
- Sections 62 and 66-70, lines 1989-2017 and 2091-2211: native/integrated truth, stale mirror handling, manual search, prescription coverage ranking, active prescription restriction, and PostgreSQL search.
- Sections 106-108, lines 3045-3115: cursor pagination, UTC/time zones, idempotency where applicable, and PostgreSQL truth.
- Sections 111, 113-115, lines 3229-3320: indexes, Redis use, cache strategy, and avoiding PHI cache.
- Sections 117, 119-123, lines 3346-3495: network isolation, throttling, audit/log redaction, and privacy.
- Sections 132-139, lines 3640-3833: medicine text/geo p95 targets and database scaling.
- Sections 149-152, lines 4027-4110: Egypt, ephemeral patient location, Google Maps directions, and failure/retry handling.
- Sections 160-162 and 171-174, lines 4268-4330 and 4503-4622: load/stress, V1 exclusions, source-of-truth, and consistency.

## Entry criteria and dependencies

- Phase 06 provides published immutable prescriptions, current/previous classification, item medication references, and patient ownership policy.
- Phase 10 provides active medication/alias search, branch/location/payment-method tenancy, and operating modes.
- Phases 11-13 provide native available balances and transactionally correct stock.
- Phase 15 may later add integrated mirror availability/freshness; until then integrated branches are excluded.
- Phase 08 provides patient location permission, directions UX, localization, and patient navigation.
- Phase 00 provides OpenAPI, authorization, cache conventions, observability, and test/load infrastructure.

## Non-goals

- No medicine price, exact stock count, batch/expiry, supplier, invoice, pharmacist contact data, or other tenant-private inventory detail.
- No medication alternatives/substitution, reservation, cart, purchase, payment, delivery, adherence tracking, or notification that stock is guaranteed.
- No search of previous/inactive prescriptions through Find My Medicines.
- No persistent patient-location history merely for search.
- No Elasticsearch/OpenSearch.
- No ranking by distance for doctor recommendations; this phase affects medicine branches only.

## Laravel module ownership and services

### Ownership

    MedicineDiscovery query module/facade
      search orchestration, privacy-safe projections,
      coverage/distance ranking and cache policy; owns no stock truth

    MedicationCatalog
      medication resolution and normalized text/alias indexes

    Inventory
      native availability query

    PharmacyIntegrations
      future integrated mirror availability and freshness

    Prescriptions
      published version, active period, patient ownership,
      immutable item references and exposure/access event

    PharmacyOrganizations
      public-safe branch/location/payment projection

### Module services and external integrations

    MedicationSearchService
      search(term, filters, cursor)

    BranchAvailabilityService
      availableBranches(medication_ids, point, radius, freshness_policy)

    ActivePrescriptionService
      getPublishedActiveForPatient(actor_patient_id, prescription_id)

    PublicBranchProjectionService
    PrescriptionExposureAudit
    SearchCache

- Discovery composes public read services and never imports catalog, inventory, prescription, or branch models.
- Native and integrated availability adapters return the same public AvailabilityObservation shape: branch, medication, AVAILABLE/UNCERTAIN/UNAVAILABLE, observed_at, freshness class.
- The ranking policy is pure/deterministic and independently tested.
- Client-supplied patient, stock, branch-mode, freshness, prescription status, or rank values are ignored.

## Packages and runtime components

### Laravel/PHP

- PostgreSQL pg_trgm and GIN indexes for normalized medication/alias text.
- PostGIS ST_DWithin/distance queries through clickbar/laravel-magellan or reviewed parameterized SQL inside the owning adapter.
- Laravel cache/Redis for bounded public catalog/branch projections only; availability TTL is short and correctness language remains non-guaranteed.
- Laravel UUIDv7, audit/outbox, Prometheus, Laravel Telescope (local), Sentry, deptrac/deptrac, Larastan/PHPStan, Pest/PHPUnit, and Eris.

### Flutter patient app

- Riverpod, Dio, generated OpenAPI types, Freezed, geolocator permission adapter, url_launcher for Google Maps directions, secure storage, and localization.
- No Google navigation engine or direct PostGIS/Maps server credential in the app.
- Do not persist precise search coordinates beyond the active UI/request unless explicit consent/policy later allows it.

## Persistent schemas, invariants, and indexes

Discovery primarily reads owned tables. Add only bounded projections/audit:

    medication_search_terms
      medication_id UUID
      language char(2)
      normalized_term text
      source_type enum BRAND | GENERIC | INGREDIENT | ALIAS
      status enum ACTIVE | RETIRED
      primary key (medication_id, language, normalized_term, source_type)

    prescription_access_events
      id UUIDv7 primary key
      prescription_id / prescription_version_id / patient_profile_id UUID
      access_type enum PATIENT_VIEW | FIND_MEDICINES | PRINT | EXPORT
      occurred_at timestamptz
      actor_id / device_id / correlation_id UUID

Recommended indexes:

- GIN/trigram medication_search_terms(normalized_term gin_trgm_ops).
- Medication catalog barcode/normalized-name indexes from Phase 10.
- Native stock_balances(branch_id, medication_id) with positive-availability predicate/projection.
- GiST pharmacy_branches(location), plus active public branch/mode indexes.
- prescription access by prescription/version/time and patient/time.
- For integrated mirrors, Phase 15 supplies branch/medication/freshness indexes.

### Hard invariants

1. Manual search resolves only ACTIVE catalog medications.
2. A branch result is public only if branch/organization is active, payment/location projection is permitted, and the corresponding availability source is usable.
3. Native availability comes from positive eligible balance; integrated availability comes only from a fresh successful mirror generation.
4. Patient responses never include quantity, batch, cost, price, movement, supplier, or tenant-internal status.
5. Find My Medicines requires the authenticated patient's current published prescription and server-calculated active period.
6. Published prescriptions are immutable before search. FIND_MEDICINES adds an access event; it does not unlock/lock content.
7. Coverage count is distinct required medication items available per branch, bounded by prescription items.
8. Rank is coverage descending, distance ascending, then stable branch ID tie-break; no rating/price/paid placement.
9. Current patient coordinates are request-scoped, range validated, not logged, and not stored in search history.
10. Availability is an observation, not a reservation/guarantee.

## Detailed success, failure, concurrency, and data flows

### Manual medication search

1. Patient enters bounded Arabic/English name/ingredient/alias or scans an allowed barcode.
2. Laravel authenticates, rate-limits, normalizes the term by approved deterministic rules, and queries ACTIVE medication terms.
3. Patient selects a canonical medication; client optionally supplies current latitude/longitude after permission.
4. Server validates Egypt-relevant coordinate/radius bounds and calls `BranchAvailabilityService` once.
5. Query joins public active branch projection and availability source, applies PostGIS radius/distance, excludes unavailable/stale results according to policy, and sorts nearest first.
6. Response contains medication reference, branch display data, availability/uncertainty, distance, payment methods, and directions coordinates only.

### Find My Medicines

1. Client sends prescription ID and optional current point; it never submits medication list/patient ID/status.
2. Prescription policy loads the authenticated patient's latest published immutable version and validates active_until.
3. In one bounded query/request, resolve distinct medication IDs and query availability across all branches—not one query per item.
4. Aggregate branch coverage as distinct available required items.
5. Sort coverage descending, distance ascending, stable ID; expose full/partial coverage counts and per-item availability.
6. Record one idempotent FIND_MEDICINES access/audit event for the version. This has no prescription mutation.
7. Each per-medication drill-down uses the same scope/freshness/public projection.

### Concurrency and freshness

- Stock may change immediately after a read. The UI shows observed/freshness language and never promises reservation.
- Availability cache invalidation may lag; use a short TTL and filter source freshness at read time. DB truth wins on cache miss.
- A prescription amended between request start and response returns the latest current version or a version conflict; it never mixes versions.
- Integrated sync switching generations is atomic so a query never combines partially imported pages.

### Failure behavior

- Missing location permission: search still works without distance/radius ranking using a stable non-location order or prompts for location; it does not fabricate distance.
- PostGIS/query timeout: return a safe retriable search error, not unfiltered cross-tenant data.
- Inventory source unavailable/stale: omit or mark uncertain per approved policy; never mark available confidently.
- Redis unavailable: query authoritative stores at lower performance.
- No matches: return an empty safe result without leaking whether a hidden branch/medication exists.

## API, event, and job contracts

### Public patient API

    GET  /api/v1/medications/search?q=...&cursor=...
    POST /api/v1/medications/{medication_id}/available-branches/search
    POST /api/v1/prescriptions/{prescription_id}/medicine-availability/search
    GET  /api/v1/prescriptions/{prescription_id}/medications/{item_id}/available-branches?cursor=...

POST search bodies carry optional point/radius to keep coordinates out of URLs/proxy logs. Search is read-only except the idempotent access event.

Stable errors include MEDICATION_NOT_ACTIVE, SEARCH_QUERY_INVALID, LOCATION_INVALID, SEARCH_RATE_LIMITED, PRESCRIPTION_NOT_OWNED, PRESCRIPTION_NOT_CURRENT, PRESCRIPTION_VERSION_CHANGED, AVAILABILITY_TEMPORARILY_UNAVAILABLE, and AVAILABILITY_STALE.

### Events/jobs

- prescription.find_medicines_accessed.v1 contains prescription/version/patient pseudonymous IDs, timestamp, and correlation only.
- inventory.stock_movement_posted.v1, pharmacy.branch_mode_changed.v1, integration.mirror_generation_promoted.v1, and medication catalog events invalidate narrowly scoped caches.
- RebuildMedicationSearchProjection and WarmPublicDirectoryCache are Horizon jobs, idempotent and version-bound. Search correctness does not wait for cache warming.

## Client work

### Patient Flutter

- Debounced bounded search, canonical medication selection, no-results/error states, permission rationale, distance/payment/directions display, and clear availability freshness disclaimer.
- Find My Medicines displays N/N coverage first, then distance; full and partial results are visually distinct without implying reservation.
- Per-medication branch drill-down and Google Maps directions use server-returned public coordinates.
- Never show price, exact quantity, batch/expiry, or source-system internals.
- Clear precise location and results on logout; do not persist coordinates in analytics/crash reports.
- Arabic/English search input, RTL, transliteration-safe display, screen-reader result summaries, accessible permission denial, and keyboard navigation are required.

### Pharmacy Electron desktop (React + TypeScript)

No new stock mutation UI. Pharmacy may preview how its public branch projection appears, without patient identity/location, through a generated TypeScript API operation owned by main and exposed as a narrow typed preload capability. The renderer cannot supply patient/prescription identity, precise coordinates, branch scope overrides, raw URLs, or SQL.

### Admin browser React

Admin cannot inspect individual patient prescription searches. Any authorized aggregate operational projection remains a browser API contract and never reuses Electron IPC or exposes patient identity/location.

## Security and privacy controls

- Authorize prescription ownership/version and public branch projection at every query; apply branch/mode/status predicates server-side.
- Prevent BOLA through arbitrary prescription/item/patient IDs and prevent query parameter injection through parameterized SQL/ORM expressions.
- Bound query length, token count, result/radius/page limits, coordinates, and request rate; detect enumeration/scraping.
- Do not log URL/body coordinates, raw search terms tied to a user, prescription items, or result sets. Aggregate analytics must be de-identified and retention-approved.
- Use request bodies for precise coordinates, TLS, cache keys that exclude actor-sensitive data, and no PHI caching.
- Public availability adapter returns an allowlisted DTO; exact balance/cost/batch/source timestamps remain internal.
- Sanitize medication/branch display data and direction URLs; construct Google Maps links from validated coordinates, never arbitrary stored URLs.
- Audit prescription availability access without copying medication content into audit/logs.

## Test plan

### Unit/property tests

- Arabic/English normalization, barcode resolution, active/current prescription policy, published immutability, coverage distinctness, ranking/tie-break, freshness classifications, public DTO redaction, and coordinate validation.
- Property tests randomize prescriptions/branches/availability and assert coverage bounds, deterministic sorting, no hidden fields, and no rank effect from price/exact quantity.

### Integration tests

- Real PostgreSQL/PostGIS verifies GIN/trigram matching, aliases, ST_DWithin/distance, GiST use with EXPLAIN evidence, cursor stability, single bounded prescription query, native availability, and integrated generation cutover fixtures.
- Prescription amendment/version race never mixes items; access event is idempotent.
- Redis loss/stale cache cannot expose inactive/unauthorized/stale data.

### Contract tests

- Generated Dart patient-mobile client covers request-body point, public availability DTO, cursor, current-version conflict, and stable errors.
- Generated TypeScript pharmacy preview and typed preload/IPC contracts expose only the public branch projection and reject patient/prescription/location/scope-bearing or oversized arguments.
- Native and integrated `BranchAvailabilityService` implementations pass identical public/freshness/redaction contracts.
- Cache-invalidation event versions replay safely.

### End-to-end tests

- Manual search resolves Arabic/English alias and lists nearest available active branches without price/quantity.
- A five-item current prescription ranks 5/5 before 4/5 then distance, with correct per-item drill-down.
- Previous/other-patient/amended prescription attempts deny or refresh safely.
- Missing location works without fabricated distance; stale integrated and zero native stock never report confident availability.

### System, performance, and security tests

- Meet medicine text p95 at most 300 ms and geo search at most 500 ms under production-shaped catalog/branch/inventory data; stress expensive fuzzy/radius queries.
- Test BOLA, SQL/trigram wildcard abuse, cursor tampering, scraping/rate bypass, coordinate/log privacy, cache poisoning/key collision, cross-tenant branches, response-field leakage, XSS/URL injection, and stale-mirror deception.
- Failure injection covers PostgreSQL replica/cache/integration-source degradation without unsafe fallback.

## Observability, migration, and rollout

Metrics: bounded search type/status, latency, no-result rate, candidate/result counts, geo-query latency, cache hit/miss, freshness exclusions, coverage bucket, rate-limit, and dependency errors. No user, prescription, medication free text, precise point, or branch identifiers in metric labels.

Rollout:

1. Build normalized search/public branch projections and validate against approved catalog.
2. Enable manual search without location for internal users, then geo search.
3. Enable Find My Medicines for synthetic/current prescriptions after ownership/version/load tests.
4. Add native branches first; integrated branches remain excluded until Phase 15 promotion/freshness gates pass.
5. Monitor latency, empty/error/freshness rates, privacy canaries, and inventory support discrepancies.
6. Rollback disables discovery endpoints/caches without changing prescription/catalog/inventory truth.

## Acceptance and exit gate

- Manual and current-prescription discovery return correct, deterministic, public-safe results with no price/exact stock/private tenant fields.
- Five-item coverage aggregation uses one bounded availability operation and ranks coverage then distance exactly.
- Published prescriptions remain immutable; search only appends authorized access evidence.
- Other-patient/old/inactive/stale/cross-tenant/forged-location/cursor/cache abuse paths disclose zero unauthorized data.
- Text/geo p95, query-plan, load/stress, failure, privacy/security, generated-client, migration/rollback, accessibility/localization, dashboards/alerts/runbooks, and approval evidence passes.
- No reservation, alternative, purchase, payment, delivery, adherence, or other future feature is enabled.
