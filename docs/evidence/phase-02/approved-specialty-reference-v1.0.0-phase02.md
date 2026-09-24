# Phase 02 approved specialty reference v1.0.0-phase02

Chunk-only evidence. This file does **not** mark Phase 02 complete and does
**not** close P02-AUDIT-001. Independent QA closes that finding.

This is **not** Chunk 18. It does not enable profile-claim, policy catalogues,
threat-model work, SF-001, or G-08-04.

## Classification

P02-AUDIT-001 — EXTERNAL_PRODUCT_DOMAIN_INPUT_REQUIRED

The required external input has been supplied and approved. This change only
installs that approved artifact. These are **product-approved specialty
labels**, not government or syndicate verification or certification claims.

## Approved source

| Field | Value |
| --- | --- |
| Version | `v1.0.0-phase02` |
| Release date | `2026-09-24` |
| Approver | Medical Operations & Clinical Informatics Governance Team |
| Row count | 30 |
| Artifact | `docs/evidence/phase-02/reference-data/approved-medical-specialties.v1.0.0-phase02.json` |
| SHA-256 | `e58a8a93aa938c9e270e6835b5867ec8a3c58e2429ac753413e8c1bd4fecf5fc` |
| UUID map (internal ids only) | `docs/evidence/phase-02/reference-data/approved-medical-specialties.v1.0.0-phase02.uuid-map.json` |
| Migration | `2026_09_24_055405_install_approved_specialty_catalogue_v1_0_0_phase02` |

The domain-approved JSON does **not** contain generated UUIDs. Persistence
identifiers are UUIDv7 values generated once and hard-coded in
`Modules/Doctors/app/Support/ApprovedSpecialtyCatalogueV1.php` plus the UUID
map. `code` remains the external stable machine identifier.

## Production catalogue

After this migration a clean production database is **no longer intentionally
empty**. The `specialties` table must contain exactly these 30 approved rows.

Installer `Modules/Doctors/app/Services/InstallApprovedSpecialtyCatalogue`:

- empty table → insert the 30 hard-coded rows
- table already exactly equal to this version (id, code, labels, active,
  sort_order) → no-op (idempotent)
- any other catalogue (wrong labels/active/sort_order, unexpected or
  synthetic codes, different ids) → **fail closed** with
  `ConflictingSpecialtyCatalogue`
- if conflicting rows are referenced by `doctor_profiles`, fail rather than
  rewrite a doctor's specialty

The installer does not overwrite, delete, activate, or convert test fixtures
into production data.

`2026_09_19_140000_create_doctor_profile_tables.php` is unchanged.

## Tests

Focused Pest coverage lives in:

- `apps/core-api/tests/Unit/Doctors/ApprovedSpecialtyCatalogueV1Test.php`
- `apps/core-api/tests/Feature/Doctors/ApprovedSpecialtyCatalogueTest.php`

Existing Doctor/Admin listing and empty-catalogue assertions were updated so
they observe the approved 30-row catalogue instead of an empty table.

## Phase 02 status

Phase 02: **NOT PASS**

P02-AUDIT-001: **READY_FOR_INDEPENDENT_QA** (not closed by this change)
