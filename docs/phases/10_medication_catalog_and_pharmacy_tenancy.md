# Phase 10 — Medication Catalog and Pharmacy Tenancy

## Objective

Deliver the authoritative Egyptian medication catalog and the pharmacy organization/branch tenancy foundation. The catalog represents each sellable drug variation as a stable medication/SKU with explicit packaging conversions. Pharmacy authorization is branch-scoped and capability-based even if V1 presents a simplified account experience.

This phase establishes strict branch operating modes:

- NATIVE: this platform owns inventory, purchasing, POS, and stock truth.
- INTEGRATED: an approved external pharmacy system owns stock truth and this platform exposes a read-only mirror; native stock-changing commands are denied.

Phase 15 implements connector synchronization, but the mode and authorization invariants are enforced from this phase onward.

## Plan traceability

- Sections 11-12, lines 462-509: pharmacy organization/branch registration and role boundaries; admin privilege does not imply clinical access.
- Sections 48-50, lines 1607-1696: organization/branch model, admin-owned medication master, distinct variations/SKUs, and box/strip/tablet/bottle/piece packaging.
- Section 61, lines 1966-1987: owner visibility across branches and branch transfer deferred.
- Section 62, lines 1989-2017: NATIVE versus INTEGRATED source-of-truth modes.
- Section 67, lines 2113-2142: branch location/payment data later exposed through medicine search.
- Section 70, lines 2195-2211: PostgreSQL GIN, pg_trgm, normalized search fields, and aliases instead of Elasticsearch.
- Sections 108-111, lines 3109-3255: PostgreSQL ownership, table set, UUIDs, and indexes.
- Sections 117-120, lines 3346-3434: network security, privileged MFA, rate limiting, and audit.
- Sections 149-151, lines 4027-4083: Egypt configuration, PostGIS location, and Google Maps directions.
- Sections 159 and 171-174, lines 4254-4266 and 4503-4622: pharmacy correctness tests, V1 exclusions, source-of-truth, consistency, and background work.

## Entry criteria and dependencies

- Phase 01 provides authenticated users, MFA, device sessions, policy infrastructure, and non-clinical admin capability separation.
- Phase 02 provides approved pharmacy organizations, branches, locations, and verification state.
- Phase 00 provides module enforcement, PostgreSQL/PostGIS, audit, OpenAPI, outbox, feature flags, and test infrastructure.
- A product/clinical governance owner approves the source, licensing, provenance, update cadence, and retirement rules for the Egyptian medication catalog.
- Pharmacy stakeholders approve the V1 role-to-capability matrix before branch workflows are enabled.

## Non-goals

- No medication alternatives, therapeutic substitution, reservation, branch transfer, online payment, supplier API, controlled-drug workflow, or multi-country catalog.
- No pharmacy inventory, purchasing, or POS implementation; those begin in Phases 11-13.
- No external synchronization implementation; Phase 15 supplies adapters and mirrors.
- No price display to patients and no catalog editing by pharmacy users.
- No free-form package fractions or floating-point conversion.
- No Elasticsearch/OpenSearch.

## Architecture, ownership, and SOLID boundaries

### Module ownership

    MedicationCatalog
      medications, ingredients, aliases, forms, packaging,
      provenance, lifecycle, admin approval, search projection

    PharmacyOrganizations
      organization, branch, location, operating mode,
      memberships, roles, capabilities, payment-method settings

    Admin application facade
      authenticated catalog approval and pharmacy verification commands;
      owns no catalog/branch tables

    Future consumers
      Inventory, Purchasing, POS, Search, Integration, and AI
      use stable read/command ports and never edit catalog tables directly

### Owned ports

    MedicationReferenceQuery
      getActive(medication_id)
      resolveByBarcode(barcode)
      resolveByNormalizedTerm(term, cursor)

    PackagingQuery
      smallestUnit(medication_id)
      conversionToSmallest(medication_id, packaging_id)

    CatalogAdministration
      createDraft(command)
      publish(command)
      retire(command)

    BranchAuthorization
      requireCapability(actor_id, branch_id, capability)

    BranchOperatingModeQuery
      getMode(branch_id)
      requireNativeWrite(branch_id, operation)

    PharmacyMembershipRepository
    MedicationRepository

- Catalog DTOs expose stable IDs and approved reference data, never Eloquent models.
- The medication catalog has no dependency on inventory quantity, sales, prescriptions, or AI.
- Branch authorization derives organization, branch, membership, capability, verification, and status from server state.
- A branch-mode strategy selects NativeWritePolicy or IntegratedMirrorPolicy; callers cannot bypass it with a client flag.

### V1 capability matrix

At minimum define OWNER, PHARMACIST, CASHIER, INVENTORY, PURCHASING, and CONNECTOR_SERVICE capabilities internally:

| Capability | Owner | Pharmacist | Cashier | Inventory | Purchasing | Connector service |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| View branch catalog/stock | yes | yes | yes | yes | yes | scoped |
| Configure branch/payment methods | yes | no | no | no | no | no |
| Sell/return | policy | yes | yes | no | no | no |
| Adjust/receive stock | policy | policy | no | yes | receipt only | no |
| Create/receive purchase orders | policy | policy | no | policy | yes | no |
| Write integrated mirror | no | no | no | no | no | yes |
| Edit master medication | no | no | no | no | no | no |

The exact grants are configuration approved in an ADR. Unlisted actions deny. Admin medication approval is a separate privileged capability and never grants pharmacy or clinical access.

## Packages and runtime components

Versions are locked under Phase 00.

### Laravel/PHP

- Laravel 13, Sanctum, native Gates/Policies, PostgreSQL, Redis cache, outbox, and audit foundations.
- clickbar/laravel-magellan for PostGIS geography points and distance-ready branch locations.
- brick/money for any catalog/package monetary metadata introduced later; no float values.
- Laravel UUIDv7/Symfony UID support.
- deptrac/deptrac and Larastan/PHPStan for boundary enforcement.
- Pest/PHPUnit and Eris/Hypothesis-style property generation for packaging/search normalization invariants.

Use native explicit enums/value objects for medication lifecycle, packaging unit, branch mode, and capabilities. Do not add a generic state-machine or broad role package merely to encode these rules.

### Clients

- Pharmacy Flutter uses Riverpod, Dio, generated OpenAPI DTOs, Freezed mappings, secure storage, localization, and the shared design system.
- Admin React uses TanStack Query, React Hook Form, Zod, MUI, generated OpenAPI types/client, i18next, Vitest, MSW, and Playwright.
- Barcode input is an adapter abstraction so keyboard-wedge scanners and camera/native plugins do not enter catalog domain code.

## Persistent schemas, invariants, and indexes

### Catalog schemas

    medications
      id UUIDv7 primary key
      country_code char(2) not null default EG
      brand_name string not null
      generic_name string nullable
      strength_value decimal/string per approved representation
      strength_unit string nullable
      dosage_form_id UUID not null
      manufacturer_id UUID nullable
      barcode_normalized string nullable
      package_description string nullable
      status enum DRAFT | ACTIVE | RETIRED
      provenance_source_id UUID not null
      catalog_version bigint not null
      published_at / retired_at timestamptz nullable
      created_by / approved_by UUID
      created_at / updated_at timestamptz

    active_ingredients
      id UUIDv7 primary key
      canonical_name string not null
      normalized_name string not null
      status enum ACTIVE | RETIRED

    medication_active_ingredients
      medication_id / active_ingredient_id UUID
      amount_value / amount_unit nullable
      primary key (medication_id, active_ingredient_id)

    medication_aliases
      id UUIDv7 primary key
      medication_id UUID not null
      language char(2) not null
      alias string not null
      normalized_alias string not null
      alias_type enum BRAND | GENERIC | TRANSLITERATION | SEARCH
      status enum ACTIVE | RETIRED

    medication_packaging
      id UUIDv7 primary key
      medication_id UUID not null
      unit_code enum BOX | STRIP | TABLET | BOTTLE | PIECE
      parent_packaging_id UUID nullable
      units_per_parent integer nullable
      units_to_smallest integer not null
      is_smallest_tracked boolean not null
      status enum ACTIVE | RETIRED

    catalog_sources
      id UUIDv7 primary key
      name / license_reference / version / obtained_at
      checksum / approval_status / approved_by

### Tenancy schemas

    pharmacy_organizations
      id UUIDv7 primary key
      legal_name / status / country_code

    pharmacy_branches
      id UUIDv7 primary key
      organization_id UUID not null
      name / address
      location geography(Point, 4326)
      operating_mode enum NATIVE | INTEGRATED
      mode_version bigint not null
      status enum PENDING | ACTIVE | SUSPENDED
      freshness_threshold_seconds integer nullable
      created_at / updated_at

    pharmacy_memberships
      id UUIDv7 primary key
      user_id / organization_id UUID not null
      status enum ACTIVE | SUSPENDED | REVOKED
      created_at / revoked_at

    pharmacy_branch_roles
      membership_id / branch_id / role_code
      primary key (membership_id, branch_id, role_code)

    branch_payment_methods
      branch_id UUID
      method enum CASH | CARD
      enabled boolean
      primary key (branch_id, method)

Indexes and constraints:

- Unique active barcode_normalized where barcode is not null; duplicates require an explicit governed exception model, not silent overwrite.
- Trigram/GIN indexes on normalized brand, generic, ingredient, and alias fields.
- Unique active normalized alias per medication/language/type.
- One and only one smallest package per active medication.
- units_per_parent and units_to_smallest are positive integers; parent relationships are acyclic.
- pharmacy_branches(organization_id, status), GiST(location), and membership/user/branch indexes.
- Unique active membership per user/organization; foreign keys prevent cross-organization branch roles.

### Hard invariants

1. Every distinct strength/form/package variation is a separate medication ID.
2. An ACTIVE medication has approved provenance, at least one name, one dosage form, and exactly one smallest tracked unit.
3. Packaging conversions are exact positive integers to the smallest unit; 2.5 boxes is never stored.
4. Referenced medications/packaging are retired, never deleted or repurposed.
5. Only the catalog-admin capability may publish/retire master entries, using MFA and step-up authorization.
6. Every branch operation is scoped to an active membership and explicit branch capability.
7. NATIVE is the only mode that permits native inventory/purchasing/POS stock mutation.
8. INTEGRATED permits connector-owned mirror writes only; pharmacy UI writes cannot masquerade as connector writes.
9. Mode changes are privileged, audited transitions with compare-and-set mode_version and never a client-side setting toggle.

## Detailed success, failure, concurrency, and data flows

### Create and publish medication

1. Admin submits a draft with source/provenance, names, form, ingredient, barcode, aliases, and packaging tree.
2. Request validation normalizes search fields without changing display values.
3. Domain validates exact conversion graph, one smallest unit, no cycles, positive ratios, and barcode rules.
4. Draft is inserted without becoming visible to prescribing/inventory/search.
5. A different or explicitly authorized approver performs step-up authentication and reviews provenance.
6. Publish transaction locks the draft, revalidates uniqueness/version, sets ACTIVE, writes audit and catalog-medication-published outbox event, and commits.
7. Consumers invalidate catalog/search caches after commit.

Concurrent duplicate barcode or alias publication resolves through database uniqueness; one succeeds and the other receives a safe conflict.

### Retire medication/package

Retirement never rewrites prescriptions, invoices, or stock history. The transaction marks the reference retired with reason/time/actor and emits an event. Existing records continue to display a versioned snapshot/reference; new purchase/POS/search selection is denied unless the consuming phase explicitly permits disposal/correction.

### Authorize a branch action

1. Authenticate session/device and resolve actor.
2. Load active organization membership and exact branch role/capability.
3. Verify branch/organization active status.
4. For stock-changing operations, require operating_mode NATIVE and the operation capability.
5. Pass an immutable BranchAuthorizationContext to the target application command.
6. Target module rechecks the context/version in its transaction.

### Change branch mode

Mode transition is disabled until Phase 15 supplies its reconciliation workflow. When enabled, a coordinator must block new writes, ensure no in-flight sale/receipt/sync, reconcile the selected source, atomically change mode/version, rotate connector credentials as needed, and audit. Direct PATCH of operating_mode is prohibited.

## API, event, and job contracts

### Admin/catalog API

    POST /api/v1/admin/medications
    PUT  /api/v1/admin/medications/{id}/draft
    POST /api/v1/admin/medications/{id}/publish
    POST /api/v1/admin/medications/{id}/retire
    GET  /api/v1/admin/medications?cursor=...
    GET  /api/v1/medication-reference/{id}

### Pharmacy tenancy API

    GET  /api/v1/pharmacy/organizations/{organization_id}/branches
    GET  /api/v1/pharmacy/branches/{branch_id}
    GET  /api/v1/pharmacy/branches/{branch_id}/memberships
    PUT  /api/v1/pharmacy/branches/{branch_id}/member-roles/{membership_id}
    PUT  /api/v1/pharmacy/branches/{branch_id}/payment-methods

Server responses expose capability decisions needed for UI rendering but never treat them as reusable authorization tokens.

Stable errors include MEDICATION_NOT_ACTIVE, CATALOG_VERSION_CONFLICT, BARCODE_CONFLICT, PACKAGING_INVALID, PACKAGING_CYCLE, CATALOG_APPROVAL_REQUIRED, BRANCH_ACCESS_DENIED, BRANCH_SUSPENDED, BRANCH_MODE_READ_ONLY, and MODE_TRANSITION_REQUIRES_RECONCILIATION.

### Events and jobs

- medication_catalog.medication_published.v1, medication_retired.v1, and packaging_changed.v1 carry IDs/version/search-invalidating facts only.
- pharmacy.branch_capabilities_changed.v1 and branch_mode_changed.v1 carry organization/branch IDs, old/new version/mode, and actor reference.
- Catalog import/validation may use bounded Laravel jobs, but activation remains an explicit reviewed transaction. Jobs are Horizon-owned, idempotent, and never auto-publish.

## Client work

### Admin React

- Draft/review/publish/retire workflows with provenance, validation errors, optimistic-version conflicts, step-up MFA, and an immutable audit summary.
- Packaging editor visualizes integer parent-to-child conversions and smallest tracked unit; it prevents cycles client-side while relying on server validation.
- No clinical record or pharmacy stock content is exposed.

### Pharmacy Flutter desktop

- Branch selector is server-scoped; switching clears branch-specific repositories/caches and refetches capabilities.
- UI hides unauthorized actions and visibly marks INTEGRATED branches read-only for native stock workflows, while server denial remains authoritative.
- Barcode scanner input maps to the catalog reference API and never constructs SQL/search expressions.
- Arabic/English, keyboard/scanner workflows, screen-reader labels, large touch targets, and deterministic decimal/text display are required.

## Security and privacy controls

- Enforce object- and function-level authorization on every organization, branch, membership, role, payment-method, and catalog-admin operation.
- Prevent actor-supplied organization/branch IDs from widening scope; repository queries always include authorized branch predicates.
- Require MFA/step-up and audit for catalog publish/retire, role changes, payment-method changes, and future mode transitions.
- Validate/search-normalize Unicode safely; bound aliases/package depth/count and reject control characters or spreadsheet-formula injection in exports.
- Catalog imports remain quarantined and untrusted until type, size, malware, schema, provenance, and human approval succeed.
- Logs/events/metrics contain stable IDs and statuses, not legal documents, secrets, or raw membership/provider data.
- Connector-service identity is non-human, least privilege, branch-bound, rotatable, and incapable of native stock/POS/catalog administration.
- Protect against mass assignment: operating mode, organization ID, approval actor, status, provenance approval, and capabilities are never client-writable generic model fields.

## Test plan

### Unit and property tests

- Medication lifecycle, distinct variation identity, publish/retire rules, provenance requirement, barcode normalization, alias normalization, and capability decisions.
- Packaging conversion/cycle/one-smallest-unit rules, including property tests over random valid/invalid trees and overflow boundaries.
- Branch mode strategies deny every unlisted or wrong-mode operation.

### Integration tests

- Real PostgreSQL/PostGIS verifies partial unique indexes, GIN/trigram queries, conversion constraints, concurrent publish conflicts, mode-version compare-and-set, cross-organization foreign keys, and GiST location storage.
- Cache invalidation/rebuild never changes truth.
- Audit/outbox rows commit atomically with publish, retirement, capability, and payment-method changes.

### Contract tests

- Generated React/Dart clients cover lifecycle, packaging, cursor, money/reference, and stable denial/conflict shapes.
- MedicationReferenceQuery and BranchAuthorization fakes/adapters pass substitutability suites.
- Event current/previous schema replay does not require display names or sensitive membership data.

### End-to-end tests

- Catalog admin creates and publishes three Panadol variations as distinct medications; pharmacy resolves them by name/alias/barcode.
- Pharmacy owner sees permitted branches; a cashier cannot administer roles/catalog; one branch cannot access another organization.
- INTEGRATED branch native stock/POS commands are denied before those later workflows execute.
- Retired medication remains readable in historical references but is unavailable for new selection.

### System, performance, and security tests

- Medication prefix/fuzzy/Arabic-English search meets the Phase 21 p95 target on production-shaped catalog data.
- Test BOLA/BFLA, UUID enumeration, role escalation, mass assignment, forged connector identity, cross-tenant cache keys, Unicode confusables, alias/package bombs, CSV/formula injection, and audit-log redaction.
- Backup/restore preserves IDs, provenance, conversions, memberships, modes, and audit ordering.

## Observability, migration, and rollout

Metrics include active/draft/retired catalog counts, publish failures by bounded reason, search latency/no-result rate, branch/membership counts, authorization denials, mode-denied write attempts, and cache rebuild/invalidation outcomes. Never label metrics by free-text medicine, user, organization, or branch ID.

Rollout:

1. Expand catalog/tenancy schemas and seed roles/capabilities disabled.
2. Import a licensed synthetic/approved staging catalog through quarantine and validation.
3. Run reconciliation/search/package/security suites and secure clinical/pharmacy review.
4. Enable read-only catalog reference APIs, then allowlisted catalog administration.
5. Enable branch capability enforcement before Inventory/POS code lands.
6. Rollback disables new publication/administration while preserving stable references and branch access; no destructive down migration.

## Acceptance and exit gate

- Approved catalog entries have stable IDs, provenance, exact integer packaging, and deterministic Arabic/English/barcode search.
- Referenced entries retire without deletion or historical mutation.
- Cross-organization and cross-branch access tests disclose or mutate zero unauthorized records.
- Every stock-changing port denies INTEGRATED mode and every connector write denies NATIVE mode.
- Role/capability, catalog approval, mode-version, audit, outbox, migration, generated-client, accessibility, and observability evidence passes.
- Production-shaped catalog search and branch queries meet agreed Phase 21 targets.
- No alternatives, reservation, branch transfer, supplier API, multi-country behavior, or other future feature is enabled.
