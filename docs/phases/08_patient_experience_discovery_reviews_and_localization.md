# Phase 08 — Patient Experience, Discovery, Reviews, and Localization

## Objective

Deliver the patient home experience, manual doctor discovery, deterministic specialty-result ranking, public doctor/location/offering projections, privacy-preserving distance search and directions, completed-appointment review eligibility, and complete Arabic/English localization for all Phase 01-08 client journeys.

The observable outcome is that a patient sees the most relevant current event, can find an approved doctor by name/specialty and compare availability/rating/location/type/price/distance, can review only the patient's own completed appointment once, and can use location search without the platform retaining current location. All security and business decisions remain server-authoritative and all Arabic/English/RTL/accessibility checks pass.

## Plan traceability

- Section 4-5, lines 153-268: Flutter patient-mobile, Electron React desktop, and React browser-admin architecture; localization packages; server-authoritative rules; and admin session safety.
- Sections 18-23, lines 728-913: doctor dashboard/public location, offering, availability, and booking inputs used by patient discovery.
- Sections 43-46, lines 1482-1567: patient home, manual doctor search, availability/rating ranking, and completed-appointment reviews.
- Sections 67-69, lines 2113-2193: later medicine discovery/current prescription separation; only navigation placeholders are permitted here.
- Sections 90-93, lines 2650-2763: patient AI/AI booking arrive later; Phase 08 supplies deterministic doctor/slot ports only.
- Sections 100-101, lines 2898-2947: later notifications feed patient-home freshness.
- Sections 105-106, lines 3029-3077: API contracts, cursor pagination, UTC, and Cairo display.
- Sections 111, 115, and 119-123, lines 3229-3254, 3305-3319, and 3386-3493: indexes/cache, rates, audit, redaction, and privacy.
- Sections 132-133, lines 3640-3690: profile/availability performance and capacity targets.
- Sections 145 and 148-152, lines 3936-3959 and 4004-4109: safe analytics, localization, Egypt-only configuration, ephemeral patient location, directions, and API failures.
- Sections 156-157 and 160-162, lines 4182-4236 and 4268-4327: client/E2E/authorization/load tests.
- Sections 170-176, lines 4484-4714: feature flags, explicit V1 exclusions, ownership, consistency, sequence, and final gate.

## Entry criteria and dependencies

- Phase 01-02 authenticated patient/doctor profiles, language preference, approved public doctor/location projections, and policy foundations pass.
- Phase 03 schedule/offering/availability/appointment APIs and Phase 04 completed appointment state are available.
- Phase 05-07 provide authorized read models for appointments, encounter follow-up, prescriptions/reminders, labs, and clinical documents used by home cards.
- Product approves home-card priority/tie-breaking, review rating/comment/moderation policy, doctor search matching, price/display wording, and location permission UX.
- Privacy/legal approves map-provider use, location non-retention, review content/privacy rules, and public doctor-profile fields.

## Non-goals

- No patient AI intake, diagnosis, urgency decision, specialty inference, or AI booking; Phase 19 owns them.
- No medicine availability/search/reservation/alternatives; Phase 14 owns discovery and reservation/alternatives remain excluded.
- No notification delivery or chat; Phase 09 owns them.
- No online payment, multi-country, navigation engine, background location tracking, stored patient route history, or distance-based doctor ranking.
- No fake “Coming Soon” endpoint that performs excluded behavior. Disabled cards may be metadata-only and server-feature-flagged.

## Module ownership and SOLID boundaries

### `PatientExperience`

Owns home-card selection/ordering and the patient-safe aggregate read model. It does not own appointment, prescription, lab, clinical, or notification truth.

```text
GetPatientHome
SelectHeroCard
GetPatientNavigationCapabilities
```

It consumes narrow read ports that return classified summaries. One failing optional card degrades that card with a safe retry state; it must not turn a core home response into a data leak or fabricate stale clinical facts.

### `DoctorDiscovery`

Owns public doctor search, normalized name/specialty matching, directory projection, deterministic ranking, safe distance calculation, and discovery cursors.

```text
SearchDoctors
GetPublicDoctorProfile
RankDoctorCandidates
BuildDirectionsLink
```

It consumes public projections from Doctors/Clinics and safe next-availability summaries from Scheduling. Booking still calls Phase 03 and revalidates authoritative state.

### `Reviews`

Owns appointment eligibility, rating/comment, one-review constraint, moderation/publication state, aggregate rating, correction/removal policy, and public projections.

```text
CreateAppointmentReview
GetDoctorReviews
ModerateReview
RecalculateDoctorRating
```

Review moderation is a fixed capability, not a complex admin-role system. Moderators see review/public appointment proof only, not clinical encounter content.

### `Localization`

Owns locale identifiers, translation-key catalogs, formatting conventions, fallback policy, RTL metadata, and translation completeness tooling. Domain/API errors retain stable codes; clients localize them.

### Boundary rules

- Patient home/discovery are query modules and may not write source-domain tables.
- Public projections are explicit allowlists. Doctor verification documents, personal contacts, patient counts, queue details, and clinical content never enter them.
- Search/ranking consumes only server data. Client-supplied rating, availability, distance, doctor status, or price is ignored.
- Current patient location is a sensitive transient request value, excluded from persistence, cache keys, events, logs, traces, analytics, and provider telemetry controlled by the platform.

## Packages and platform capabilities

- PostgreSQL `pg_trgm`, normalized search columns, GIN/GiST as measured, and PostGIS `geography` with `ST_DWithin`/distance ordering.
- Laravel query/application services, cursor pagination, cache for public directory summaries, outbox projection consumers, policies, and rate limiting.
- Flutter patient mobile uses `intl`, localization generation, Riverpod, Dio/generated Dart client, `geolocator` (permission/current-position only), `url_launcher`, and Google Maps integration only where the approved UX needs an embedded map.
- Electron doctor desktop uses React, TypeScript, TanStack Query, MUI, i18next, the generated TypeScript client, React Testing Library, WebdriverIO with `@wdio/electron-service` for packaged-app E2E/screenshots, and axe-core for its public-profile preview.
- React browser admin uses `i18next`, MUI RTL support, React Testing Library, Playwright, and axe-core for the review-moderation/admin-safe surface.
- Flutter patient-mobile widget/golden/integration tests, WebdriverIO packaged Electron screenshot/E2E tests, and browser-admin Playwright tests cover Arabic/English, RTL/LTR, text scaling, keyboard/focus, accessibility, and relevant permissions.
- Pest/PHPUnit, PostGIS query-plan tests, k6 doctor-search/availability scenarios, and content-security tests.

## Data model and migrations

### `doctor_directory_entries`

Derived/read projection:

```text
doctor_id UUID PK
public_display_name
normalized_search_name
specialty_id / specialty_name_ar / specialty_name_en
verification_public_status
rating_average numeric(3,2)
rating_count bigint
next_available_at timestamptz nullable
projection_version bigint
updated_at
```

- Only approved/listed doctors appear.
- GIN/trigram index on normalized name after measured threshold; B-tree `(specialty_id, verification_public_status, next_available_at)`.
- Rating uses exact aggregate sum/count or a transactional/repairable projection, never rounded values as truth.

### `doctor_location_directory_entries`

```text
doctor_id / clinic_location_id
public_location_name
public_address
geography_point geography(Point,4326)
active_offering_summary
public_payment_summary if applicable
projection_version / updated_at
```

- Unique doctor/location; GiST geography; only active, public, approved location/offering fields.
- Prices use integer minor units/currency in structured summaries.

### `doctor_reviews`

```text
id UUIDv7 PK
appointment_id UUID unique
patient_id UUID
doctor_id UUID
rating smallint check 1..5
comment text nullable
status enum(pending_moderation,published,hidden,removed_with_reason)
moderation_reason_code nullable
version bigint
created_at / updated_at / published_at nullable
```

- Unique appointment enforces one review for one completed patient appointment.
- The patient may edit only under an explicitly approved short policy and before/through append-only revision/audit; destructive replacement is prohibited if already published. If editing is not approved, omit the endpoint.
- Index `(doctor_id, status, published_at desc)` and `(patient_id, created_at desc)`.

### `review_revisions` and `review_moderation_events`

Append-only old/new rating/comment hashes or protected text refs, actor, reason, state transition, time, request ID. Moderation does not reveal clinical appointment/encounter data.

### `doctor_rating_aggregates`

```text
doctor_id PK
published_rating_sum bigint
published_rating_count bigint
average numeric(3,2)
version bigint
updated_at
```

Transactional or event-driven with reconciliation; only published eligible reviews count. Zero-count displays “No reviews”, never `0.0` as a clinical-quality statement.

### `user_locale_preferences`

Use `users.language` from Phase 01; optionally store client-specific display locale/timezone preference only if product requires it. V1 supported locales are `ar` and `en`; timezone remains `Africa/Cairo` for business display.

### Patient home

Prefer an application query assembled from bounded source-domain projections. If a `patient_home_projection` is introduced after measurement, it stores only identifiers, card types, safe timestamps/status codes, version, and expiry—not diagnosis, prescription text, lab content, National ID, phone, or current location.

## Core invariants

1. Home shows only the authenticated patient's data through domain-owned safe summary ports.
2. Hero selection is deterministic using product-approved priority, due time, status, and stable tie-break; it never uses AI.
3. Discovery returns only approved/listed doctors and active public locations/offerings.
4. Ranking is `earliest availability ascending`, then `rating descending`, then a documented stable tie-break. Distance is display/filter only and never affects ranking in V1.
5. Availability/rating/directory projections may be eventually consistent, but booking always revalidates Phase 03 truth.
6. Patient current location is processed in memory for the request and is not persisted, cached, emitted, logged, traced, or analyzed.
7. Review creation requires `appointment.status=completed` and `appointment.patient_id=current_patient`; one appointment creates at most one review.
8. Cancelled/no-show/other-patient appointments cannot be reviewed. Doctor/admin/secretary cannot create a patient review.
9. Public review text is bounded plain text, safely rendered, checked/moderated for personal/clinical disclosures and abuse, and never treated as medical fact.
10. All user-visible strings are translation keys with Arabic/English values; RTL/LTR, pluralization, dates, numbers, currency, and accessibility are tested.
11. Server stores instants UTC; UI converts with `Africa/Cairo`. Currency is EGP and phone/National-ID validation remains Egypt-specific without hard-wiring schemas against future country support.
12. Directions open an approved Google Maps deep link/app boundary; the platform does not build or store route/navigation history.

## Detailed workflows

### Patient home aggregation

1. Authenticate patient and resolve patient profile server-side.
2. In one bounded application query, ask ports for upcoming appointment, next doctor-confirmed medication reminder, pending lab, new/current prescription, and follow-up summary.
3. Each port returns a safe card candidate with source ID, type, status code, due time, updated/version, and route capability—not raw clinical content.
4. Filter stale/ineligible cards, apply approved priority such as upcoming dose, appointment, pending lab, new prescription, follow-up, then due time/stable key.
5. Return one hero plus bounded secondary sections and `as_of` metadata.
6. If one optional source fails, return a typed degraded section and telemetry. Do not silently show a stale medical instruction as current.

Home card actions re-fetch authoritative source state. A card never authorizes booking, clinical access, file download, or prescription mutation.

### Manual doctor search

1. Validate bounded name/specialty query, locale, cursor, optional radius, and optional current point.
2. Normalize Arabic/English search text with an approved search-specific function; preserve original display text and avoid confusable/over-normalization collisions.
3. Query only approved directory entries and active locations. Apply specialty/name and optional `ST_DWithin` filter.
4. Load safe offering/next-availability summaries without N+1 calls; use a projection/batched port.
5. Calculate distance in PostgreSQL for display when a point is supplied, then discard the point after response construction.
6. Rank by earliest availability then rating, stable tie-break. Distance remains display-only.
7. Return opaque actor/filter/order-bound cursor and `availability_as_of`. Selecting a slot calls Phase 03.

Failure behavior:

- Denied location permission omits distance but preserves name/specialty search.
- Stale availability is labeled/refreshed; booking conflict uses Phase 03 reselection behavior.
- Invalid/extreme coordinates, huge radii, expensive wildcards, and unbounded pages are rejected/rate-limited.

### Location permission and directions

1. Ask OS permission only after the patient chooses nearby search/directions and explain purpose.
2. Request current position once at the least precision compatible with the feature; do not start background tracking.
3. Send point only to the platform search endpoint over TLS; networking/logging layers mark the fields redacted.
4. Discard application/server value after request. No analytics event includes coordinates.
5. Directions action opens a validated Google Maps HTTPS/app link containing the public destination. If current-location routing is delegated to Google Maps, disclose that boundary in UX/privacy notice.

### Create review

1. Authenticated patient submits appointment ID, rating, optional bounded comment, expected policy version, and idempotency key.
2. Server ignores any patient/doctor IDs in payload, loads appointment, and validates completed status and ownership.
3. Lock appointment/review key; unique constraint prevents a second review.
4. Normalize comment as plain text, reject control characters/links or content classes prohibited by policy, and warn users not to disclose medical/personal information.
5. Insert review/audit and status `pending_moderation` or `published` according to approved moderation policy; update/outbox aggregate only for published reviews.
6. Commit and return safe status. Duplicate same-intent retry returns the same review; changed payload conflicts.

### Moderation and rating reconciliation

- Fixed-capability moderator can publish/hide/remove with reason; cannot see medical record, encounter notes, or patient identity beyond a pseudonymous review reference.
- Rating aggregate changes atomically or through idempotent event consumer, with a scheduled reconciliation from published reviews.
- Removing/hiding a review preserves revisions/moderation audit and updates aggregate; it never deletes the appointment.

### Localization workflow

1. Every feature adds stable semantic keys, Arabic/English translations, interpolation schema, plural rules, and platform-appropriate Flutter patient-mobile golden or React/Electron screenshot coverage.
2. CI rejects missing/orphaned/duplicate keys, invalid interpolation variables, and hard-coded user-visible strings except approved technical/legal constants.
3. API returns stable codes and structured values; clients localize. Server-generated PDF/document templates have separately approved Arabic/English versions.
4. Layout tests cover RTL mirroring exceptions, long text, 200% text scale, screen-reader labels/order, contrast, focus/keyboard on desktop/web, and Arabic/English numeral/date/currency policy.
5. Language switch updates UI without corrupting in-flight form/idempotency state.

## API contracts

```text
GET  /patients/me/home
GET  /doctor-directory
GET  /doctor-directory/{doctor_id}
GET  /doctor-directory/{doctor_id}/locations
GET  /doctor-directory/{doctor_id}/locations/{location_id}/offerings
GET  /doctor-directory/{doctor_id}/locations/{location_id}/availability
POST /appointments/{appointment_id}/review
GET  /doctor-directory/{doctor_id}/reviews
GET  /patients/me/reviews
POST /admin/reviews/{review_id}/moderation-decisions
PUT  /me/preferences/language
```

- Directory filters include bounded `query`, `specialty_id`, optional latitude/longitude/radius, locale, and opaque cursor.
- Coordinate fields are marked sensitive/no-log in OpenAPI/middleware. Responses may return distance rounded for display but never echo current coordinates.
- Public doctor profile includes rating, locations, appointment types, prices, availability/as-of, distance if requested, and approved professional fields only.
- Review create requires idempotency; no client-supplied patient/doctor/status/published flag.

## Events and jobs

```text
DoctorDirectoryEntryChanged.v1 {doctor_id, projection_version, change_type}
DoctorLocationDirectoryChanged.v1 {doctor_id, location_id, projection_version, change_type}
DoctorNextAvailabilityChanged.v1 {doctor_id, location_id, next_available_at, as_of}
AppointmentReviewCreated.v1 {review_id, appointment_id, patient_id, doctor_id, status}
AppointmentReviewModerated.v1 {review_id, old_status, new_status, reason_code}
DoctorRatingChanged.v1 {doctor_id, average, count, aggregate_version}
PatientLanguageChanged.v1 {user_id, language}
```

Review events omit comment and patient identity details beyond internal IDs needed by authorized consumers; public projections never expose patient ID.

Jobs:

- Directory/next-availability projection updates from approved doctor/location/schedule events, idempotent by source event/version.
- Rating aggregate reconciliation and drift alert.
- Translation-catalog validation runs in CI, not production background work.
- Optional home-projection repair/expiry if measurement justifies persistence.
- Review moderation SLA reminder and privacy/abuse escalation without automatic clinical interpretation.

## Client work

### Patient Flutter

- Home hero/sections with safe freshness/degraded states and navigation to appointments, prescriptions, record, labs/reports, and later feature-flagged AI/medicine/chat destinations.
- Doctor search by name/specialty, filters, listing/profile, location/type/price/availability/distance, refresh on stale availability, and booking handoff.
- Just-in-time location permission, denied/restricted/temporarily unavailable states, no background tracking, and Google Maps directions disclosure.
- Review form only for eligible completed appointments; rating/comment guidance, moderation status, duplicate conflict recovery.
- Full Arabic/English, RTL/LTR, text scaling, screen reader, dynamic color/contrast, and offline/error UX.

### Doctor Electron desktop

- Preview own public directory projection and rating/review summaries; profile edits still go through Phase 02/03 authoritative flows.
- No patient identity from public reviews and no response/contact feature unless later approved.
- The sandboxed React renderer receives only the generated public projection through a typed preload capability backed by main-owned TypeScript transport; it performs no authenticated HTTP directly. No credential, raw review proof, patient identifier, Node, filesystem, shell, or generic IPC capability is exposed.

### React browser admin

- Fixed review-moderation queue with pseudonymous proof, safe plain-text renderer, reasoned decision, and no clinical link.
- Translation/system configuration view only if needed operationally; no arbitrary runtime translation HTML/script.

## Security, privacy, fairness, and accessibility controls

- **Location surveillance/leakage:** just-in-time permission, one-shot point, no persistence/cache/event/trace/analytics, bounded precision/radius, processor disclosure, no background tracking.
- **Directory scraping/enumeration:** only intended public fields, cursor/page/rate limits, bot/abuse monitoring, no personal contact/verification docs/patient counts, stable safe errors.
- **Search injection/DoS:** parameterized queries, bounded query/radius, normalized plain text, indexed patterns, wildcard controls, statement timeout, and load shedding.
- **Ranking manipulation/unfairness:** deterministic published rule, server data, rating eligibility/aggregate reconciliation, stable tie-break, no distance ranking, version/as-of telemetry, no hidden sponsored ordering.
- **Fake/review abuse:** completed-owned appointment proof, unique constraint, moderation, no client publish flag, plain-text escaping, privacy warning, audit, rate/anomaly limits.
- **Home cross-patient leak:** per-port server patient binding, explicit card DTO allowlists, no PHI cache, alternating-user Octane tests.
- **Map/deep-link injection:** fixed approved host/scheme, encoded numeric destination from server, no arbitrary URL from API/provider/client.
- **Localization/accessibility safety:** no truncation of medication/lab/status meaning, semantic keys, correct RTL exceptions, accessible labels/focus, translation review for clinical wording.

## Test plan

### Unit tests

- Hero eligibility/priority/tie-break/freshness/degraded-source logic.
- Doctor search normalization for Arabic/English, ranking earliest availability → rating → stable key, and explicit proof that distance does not affect ordering.
- Review eligibility, one-per-appointment, rating bounds, comment policy, moderation, aggregate arithmetic/reconciliation.
- Locale selection/fallback, interpolation/pluralization, Cairo date/time and EGP formatting, route/deep-link validation, and location redaction.

### Integration tests

- Real PostgreSQL `pg_trgm`/PostGIS search/filter/distance correctness and representative `EXPLAIN` index plans.
- Projection idempotency/out-of-order events, stale availability, cache loss/invalidation, review unique race, aggregate publish/hide/remove.
- Patient-home read ports with partial dependency failure and no cross-patient/PHI caching.
- Map deep-link and location-permission adapters through recorded/synthetic fixtures only.

### Contract tests

- OpenAPI/generated Dart patient-mobile and TypeScript Electron/admin clients for home/directory/location/offerings/availability/reviews/language, including coordinate no-log classification.
- Directory/profile/scheduling/home-source/map-link/moderation ports pass owned contracts.
- Event schemas and projection compatibility; review comments and current coordinates are forbidden from events.

### End-to-end tests

- Patient home selects each card type under controlled time/state and routes to correct authorized screen.
- Arabic/English search by name/specialty → compare listing → location/type/price/availability/distance → Phase 03 booking.
- Location denied still allows search; granted shows distance; server/telemetry store no point; directions opens approved map target.
- Completed own appointment permits one review; cancelled/no-show/other-patient/duplicate fail; moderation updates public rating.
- Language switch across onboarding, booking, queue, clinical read, prescription, labs/files, home/review preserves form state and correct RTL/LTR.
- Packaged doctor Electron builds render the same approved public projection in Arabic/English/RTL, preserve keyboard/focus/accessibility behavior, and expose no patient identity, credential, or privileged preload operation.

### System tests

- k6 doctor search/profile/availability scenarios at sustained/burst capacity meet p95 targets and recover after cache loss/DB pressure.
- Large directory/review dataset preserves pagination stability, query bounds, aggregate consistency, and no memory exhaustion.
- Redis/projection worker/map-provider outage degrades safely; booking and core patient access remain operational.
- Rolling locale/projection schema change supports old/new clients and opaque cursor versioning.

### Security tests

- BOLA/BFLA/mass assignment on home/reviews/moderation/language, forged completed status/patient/doctor/rating aggregate, duplicate/replay, cursor tampering, XSS/Unicode/control/link payloads.
- SQL/search wildcard/resource fuzz, coordinate extremes/NaN/precision/radius abuse, deep-link scheme/host injection, directory scraping/rate bypass.
- Alternate patients through one Octane worker and inspect home/directory caches/events/telemetry for cross-user or location leakage.
- Seed patient/clinical/location/review canaries and verify only approved public review text/doctor fields appear; no current coordinates in logs/traces/Sentry/analytics.
- Electron tests attempt stored review XSS-to-IPC/Node/navigation escalation, forged preload sender/schema, token access, and hidden patient-proof fields; all fail closed.

### Accessibility and localization tests

- CI translation completeness and hard-coded-string scan for Flutter patient-mobile, Electron/React TypeScript, browser React, and PDF templates.
- Flutter patient-mobile widget/golden, WebdriverIO packaged Electron screenshots, and browser-admin Playwright screenshots cover Arabic/English, RTL/LTR, smallest/largest supported viewport or window/DPI, 200% text, long translations, light/dark/high contrast.
- Screen-reader semantics/order, keyboard/focus, error announcement, touch target, and locale-aware plural/date/number/currency tests.
- Human Arabic/English review of clinical/status/legal wording; machine translation alone is not acceptance evidence.

## Observability and runbooks

```text
patient_home_requests_total{result,degraded_source}
patient_home_latency_seconds
doctor_search_requests_total{result,filter_class}
doctor_search_latency_seconds
directory_projection_lag_seconds{projection_type}
availability_freshness_seconds
review_transitions_total{transition,result}
review_aggregate_drift_total{result}
location_permission_outcomes_total{result}       # client aggregate; no coordinates
localization_missing_key_total{client,locale}
```

- No query text, patient/doctor/location IDs, review comments, coordinates, route, or clinical/card content in metric labels.
- Alert on projection lag/drift, unexpected unpublished doctor, review abuse/moderation backlog, search latency/error/rate spikes, home cross-source failures, and redaction canaries.
- Runbooks cover wrong public doctor/location data, ranking regression, review privacy/abuse complaint, rating drift, leaked location suspicion, map-provider outage, translation clinical-safety issue, and stale home card.

## Migration and rollout

1. Add review/aggregate and public directory projection schemas; backfill only approved public fields from Phase 02-03 using resumable jobs.
2. Validate projection parity and PostGIS/search plans in shadow endpoints; keep public directory disabled until privacy review passes.
3. Enable patient home with source freshness metadata, then doctor search without location, then one-shot distance behind separate flags.
4. Enable directions after deep-link/provider/privacy review; no location field is added to persistence/telemetry.
5. Enable review creation for a cohort, moderation/aggregate reconciliation, then public ratings after abuse/privacy tests.
6. Ship Arabic/English/RTL only when translation/accessibility gates cover all Phase 01-08 screens; missing critical keys fail build rather than silently show unsafe text.
7. Rollback disables public writes/search features while preserving reviews/audit and source truth; derived directory/home/rating projections are rebuildable.

## Measurable exit gate

- Search ranking is exactly earliest availability, rating descending, stable tie-break; automated tests prove distance never changes order.
- Only approved doctors/locations/offerings and allowlisted public fields appear in directory responses.
- Current patient coordinates are absent from database, Redis, events, logs, traces, Sentry, analytics, and support artifacts in canary tests.
- Own completed appointment creates one review; cancelled/no-show/cross-patient/duplicate/publish-forgery and XSS tests pass.
- Patient home cross-user isolation, source freshness/degradation, and deterministic hero cases pass.
- Doctor profile/search p95 is at or below 250 ms and availability at or below 300 ms on the agreed representative dataset, or an approved measured exception exists.
- Arabic/English translation completeness is 100% for Phase 01-08 user-visible keys; RTL/LTR, 200% text, screen-reader, keyboard/focus, and human clinical-language reviews pass.
- Product/privacy/security/legal approve public fields, review/moderation, map/location, ranking, and translation policies.
- No Critical or unaccepted exploitable High finding remains.

## Deliverables

- `PatientExperience`, `DoctorDiscovery`, `Reviews`, and `Localization` modules/read models, schemas, APIs, events, jobs, and policies.
- Patient home/search/location/directions/review workflows, doctor public-preview surface, and safe admin moderation.
- Arabic/English catalogs, RTL/accessibility evidence, query/load/security tests, dashboards, alerts, and runbooks.
