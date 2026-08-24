# Source Plan Coverage Matrix

## Purpose

This matrix proves that the phase library has a primary implementation owner for every numbered section of [`plan.md`](../../plan.md). Phase files deliberately repeat cross-cutting requirements, but this table assigns one primary owner so omissions are visible. The line references use the current 4,742-line source and must be refreshed if `plan.md` changes.

| Primary phase | Source sections | Source lines | Primary responsibility |
| --- | --- | ---: | --- |
| 00 | 1-5 | 1-270 | System shape, modular-monolith/AI split, stack, Flutter and React architecture |
| 00 | 102-115 | 2949-3321 | Queue lanes, Horizon, outbox, API/data contracts, idempotency, database/IDs/indexes, Redis/cache |
| 00 | 152 | 4085-4111 | Shared client/API failure and retry contract |
| 00 | 165-175 | 4367-4690 | Environments, Docker, CI/CD, migrations, secrets, flags, exclusions, ownership, consistency, execution order |
| 01 | 6-9 | 271-428 | Identity/profile separation, protected national ID, patient registration, safe existing-profile linkage |
| 01 | 12, 14 | 492-509, 571-598 | Base access model and secretary clinical-data denial |
| 01 | 116, 118-119 | 3322-3345, 3368-3402 | Sanctum/session/device authentication, MFA, and abuse rate limits |
| 02 | 10-11 | 429-490 | Doctor and pharmacy registration/verification |
| 02 | 19 | 770-793 | Doctor clinic-location ownership |
| 03 | 20-25 | 794-959 | Schedules, types, availability, booking, pay-at-clinic fields, and walk-ins |
| 03 | 46 | 1551-1568 | Review eligibility and uniqueness |
| 03 | 146 | 3962-3985 | No-show and unresolved appointment handling |
| 04 | 17 | 687-727 | Consultation transition orchestration and access grant/revoke hooks |
| 04 | 26-28 | 960-1045 | Queue ordering, delay projection, and realtime delivery |
| 04 | 99 | 2879-2897 | Private realtime channel model |
| 05 | 13, 15-16, 18 | 510-570, 599-769 | Contextual medical-record access, record/encounter model, doctor dashboard |
| 05 | 29, 153, 155 | 1046-1077, 4112-4143, 4158-4181 | Encrypted local clinical drafts/outbox and transient-offline semantics |
| 06 | 30-37 | 1078-1330 | Prescription structure, reminders, active period, immutability, amendments, audit, printing |
| 06 | 158 | 4240-4253 | Mandatory prescription regression suite |
| 07 | 38-42 | 1331-1481 | Lab states, private medical files, scanning/OCR, AI-readable textual reports |
| 07 | 147 | 3986-4003 | Encounter-scoped medical reports, sick leave, and referrals |
| 08 | 43-44 | 1482-1531 | Patient-home prioritization and manual doctor discovery |
| 08 | 148-151 | 4004-4084 | Localization, Egypt configuration, ephemeral location, and map directions |
| 09 | 47 | 1569-1606 | Encounter-scoped 48-hour text chat |
| 09 | 100-101 | 2898-2948 | Push/SMS intent and delivery lifecycle |
| 10 | 48-50, 61 | 1607-1696, 1966-1988 | Pharmacy organizations/branches/owner and governed medication/packaging master |
| 10 | 70 | 2195-2213 | PostgreSQL medication text-search implementation |
| 11 | 51-55 | 1697-1824 | Batches, FEFO, immutable ledger, low-stock and expiry alerts |
| 11 | 159 | 4254-4267 | Mandatory pharmacy inventory regression suite |
| 12 | 56-57 | 1825-1892 | Purchase orders and partial/idempotent goods receipt |
| 13 | 58-60 | 1893-1965 | POS, invoice cancellation, returns, and refunds |
| 13 | 154 | 4144-4157 | Online-authoritative pharmacy behavior |
| 14 | 67-69 | 2113-2194 | Medicine geo search, whole-prescription coverage, active/previous behavior |
| 15 | 62-66 | 1989-2112 | Native/integrated branch modes, adapters, mappings, sync safety, freshness |
| 16 | 71-72, 74 | 2214-2272, 2297-2312 | Qdrant collection/payload/tenant topology |
| 16 | 78-86 | 2387-2577 | Clinical document index, hybrid retrieval, embeddings/reranking/chunking, versioned ingestion |
| 16 | 94-98 | 2765-2878 | AI isolation, provider abstraction, traceability, injection controls, conversation storage |
| 16 | 124, 140, 163 | 3497-3514, 3835-3856, 4331-4358 | Qdrant boundary, inference worker topology, and shared AI evaluation harness |
| 17 | 73, 87-89 | 2273-2296, 2578-2649 | Doctor KB isolation, visit context, bounded clinical capabilities, no autonomous writes |
| 18 | 76-77 | 2336-2386 | Pharmacy knowledge and least-privilege inventory tools |
| 19 | 45, 75, 90-93 | 1532-1550, 2313-2335, 2650-2764 | Patient-safe KB, triage/red flags/output, doctor ranking, confirmed booking tools |
| 20 | 144-145 | 3916-3961 | Admin-safe health view and derived analytics |
| 21 | 125, 132-139, 141-143 | 3515-3535, 3640-3834, 3857-3915 | Qdrant/core scaling, performance/capacity/SLOs, topology, availability, health and monitoring |
| 21 | 160-162 | 4268-4330 | k6 load acceptance, stress/breaking-point and recovery tests |
| 22 | 117, 120-123 | 3346-3367, 3403-3496 | Security baseline, audit integrity, telemetry redaction, and privacy/AI controls |
| 22 | 156-157, 164 | 4182-4239, 4359-4366 | Layered test standard, critical authorization tests, and clinical validation governance |
| 23 | 126-131 | 3536-3639 | S3/PostgreSQL/Qdrant backup, 3-2-1 recovery, restore drills, and AI rebuild |
| 23 | 176 | 4691-4717 | Final production definition of done |
| 00 and 23 | Unnumbered final decision | 4718-4742 | Architecture baseline in 00 and production proof in 23 |

## Coverage audit result

- Numbered source sections expected: **176**.
- Numbered source sections assigned a primary phase: **176**.
- Missing numbered sections: **none**.
- V1-excluded capabilities remain requirements for disabled feature flags/compatible boundaries only; they are not implementation deliverables.

When the source changes, update this table and the affected phase traceability before implementation continues. A change to a clinical, authorization, financial, inventory, retention, or AI-safety invariant also requires an ADR and new regression tests.
