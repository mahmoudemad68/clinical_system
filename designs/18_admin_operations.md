# Admin operational screens

Admin is a browser persona over explicit least-privilege projections, not a superuser. These screens never expose medical records, prescriptions, labs/files, patient AI content, pharmacy stock, financial records, secrets, or raw infrastructure.

## Screen 1 — Verification queue

**Maturity:** Planned, Phase 02. **Route concept:** `/verification`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a responsive verification queue with persistent sidebar, bounded filters, summary cards, cursor-paginated table, and no clinical navigation.

- **Purpose:** Triage doctor and pharmacy verification cases without broad identity or clinical search.
- **Layout:** Summary counts/SLA, bounded type/status filters, cursor-paginated table with case ID, applicant type, safe display projection, submitted/age, requirements, assignee, and status.
- **Content/actions:** Claim/open case, refresh, sort allowed fields, and view overdue status. No unrestricted export.
- **States:** Loading, empty, pending, claimed, changes requested, decided, stale, permission denied, session step-up required, and service failure.
- **Privacy/accessibility:** No National ID, raw phone/address beyond approved verification need, clinical link, or patient search. Table has semantic headers, keyboard row actions, and clear status text/icons.

## Screen 2 — Verification case review

**Maturity:** Planned, Phase 02. **Route concept:** `/verification/:caseId`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a high-fidelity case-review screen with immutable applicant snapshot, safe document viewer, requirement checklist, decision history, and sticky guarded action panel.

- **Purpose:** Review the immutable submitted snapshot and make a reasoned approve/reject/request-changes decision.
- **Layout:** Case identity/status header, minimum professional/organization projection, requirement checklist, audited document viewer, decision history, and sticky decision panel.
- **Content/actions:** Claim, open short-lived sandboxed document, mark requirement review, choose configured reason, approve/reject/request changes with expected version and MFA/step-up.
- **States:** Unclaimed/claimed, document scanning/unavailable, stale case, concurrent decision, step-up, submitting, decided, and denied.
- **Safety:** Viewer shows access-audit indicator and no raw object key/unrestricted download. Internal private notes stay separated from applicant-visible reason and never contain clinical data.

## Screen 3 — Medication catalog

**Maturity:** Planned, Phase 10. **Route concept:** `/catalog/medications`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a responsive medication catalog with sidebar, bounded multilingual/barcode search, status/provenance filters, and accessible paginated table.

- **Purpose:** Browse draft, active, and retired canonical Egyptian medication references.
- **Layout:** Bounded Arabic/English/barcode search, status/provenance filters, paginated table with brand, ingredient, strength, form, package count, source/version, and status.
- **Content/actions:** Create draft, open, review/publish if capable, retire through detail, and resolve safe conflicts. No stock or prescription usage is shown.
- **States:** Empty, draft, approval required, active, retired, barcode conflict, stale version, denied, and loading failure.
- **Accessibility/security:** Search/display is injection-safe and formula-safe. Exact status and provenance are text; catalog capability remains separate from generic admin.

## Screen 4 — Medication and packaging editor

**Maturity:** Planned, Phase 10. **Route concept:** `/catalog/medications/:id`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a high-fidelity medication editor with sectioned form, visual integer packaging tree, provenance/audit rail, and guarded publish/retire actions.

- **Purpose:** Create/review versioned medication identity and an acyclic integer packaging conversion tree.
- **Layout:** Sections for names/aliases, ingredient/form/strength, manufacturer/barcodes, provenance, and visual parent-to-child packaging tree with smallest-unit marker; review/audit rail.
- **Content/actions:** Save draft, add/remove/reorder package nodes before publication, validate graph, submit review, step-up publish, retire with reason, and compare version conflict.
- **States:** Draft/unsaved, validation, cycle/noninteger/duplicate barcode, approval required, publishing, active/locked, conflict, and retired.
- **Rules:** Client prevents obvious cycles but server revalidates. Publish and retire never rewrite historical prescriptions/invoices/stock. No arbitrary conversion or floating values.

## Screen 5 — Review moderation queue

**Maturity:** Planned, Phase 08. **Route concept:** `/moderation/reviews`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a moderation work queue with safe review card, pseudonymous proof, policy cues, and reasoned decision panel; exclude patient and clinical links.

- **Purpose:** Moderate patient reviews using pseudonymous appointment proof and fixed reasoned decisions.
- **Layout:** Queue filters, safe plain-text review card, rating, doctor public identity, eligibility proof status, policy cues, and decision panel.
- **Content/actions:** Publish, hide/remove with reason, escalate privacy/abuse, and view moderation history. No patient contact or record link.
- **States:** Pending, published, hidden/removed, stale version, duplicate decision, suspicious content, denied, and service failure.
- **Privacy/accessibility:** Patient remains pseudonymous; review text is untrusted plain text with no HTML/links. Rating has accessible text and action focus returns to the next case.

## Screen 6 — Non-clinical document templates

**Maturity:** Planned, Phase 07 only if capability is approved. **Route concept:** `/templates/clinical-documents`.

**Stitch target:** Desktop web — React admin dashboard, 1440 × 1024 px. Generate a versioned template-management screen with language/status table, bounded editor, synthetic preview, and approval history.

- **Purpose:** Manage versioned report/sick-leave/referral template definitions without seeing any patient document.
- **Layout:** Template list by type/language/status/version and editor/preview using synthetic placeholders only; approval/history rail.
- **Content/actions:** Create draft version, edit bounded fields, preview Arabic/English, validate placeholders, submit/activate under policy, and retire future use.
- **States:** Draft, validation, missing translation, preview failure, active, retired, version conflict, and denied.
- **Safety:** No patient names, records, result values, files, real artifacts, arbitrary HTML/script, or production document search. Rendering uses typed escaped placeholders.

## Sources

Phases [02](../docs/phases/02_onboarding_verification_profiles_and_locations.md), [07](../docs/phases/07_labs_files_reports_and_referrals.md), [08](../docs/phases/08_patient_experience_discovery_reviews_and_localization.md), and [10](../docs/phases/10_medication_catalog_and_pharmacy_tenancy.md).
