# Clinic product design specification

## What this folder is

This is a design handoff derived from the checked-in clients, shared design packages, `plan.md`, and the implementation contracts in `docs/phases`. It covers all four user-facing products:

- Patient mobile application — Flutter, Android and iOS.
- Doctor workspace — Electron, React, and TypeScript.
- Pharmacy workspace — Electron, React, and TypeScript.
- Admin dashboard — browser React and TypeScript.

The documents describe screens, hierarchy, interactions, states, accessibility, localization, and safety boundaries. They do not modify or prescribe backend authority, and they do not claim that roadmap screens already exist.

## Maturity labels

- **Current:** a recognizable implementation exists in the checked-in client.
- **Partially current:** part of the surface exists, but the complete roadmap journey does not.
- **Planned:** specified by `plan.md` and/or a phase contract but not represented as an implemented client screen.
- **Capability-gated:** visible only when the server returns the required capability and actor state.
- **Feature-gated:** hidden or unavailable until the owning phase and server flag are enabled.

At the time of this analysis, Core contains `Access`, `Audit`, `Auth`, `Identity`, and `Platform` modules. The clients are Phase 00/01 shells. Login, OTP/MFA handling, language switching, health, patient registration, and some desktop session handling are present; most domain screens below are planned.

## Existing visual language

The design system already establishes these foundations:

| Token | Value / rule |
| --- | --- |
| Brand seed | `#00696D`, a restrained teal |
| Operational | `#1B6C3A` |
| Degraded | `#8A5A00` |
| Unavailable/error | `#A4232B` |
| Spacing | 4, 8, 16, 24, 32 px |
| Radius | 4, 8, 16 px |
| React type | System UI with `Noto Sans Arabic` fallback; 12/14/18/24 px scale |
| Mobile theme | Material 3 light/dark schemes generated from the teal seed |
| Locales | Arabic and English; Arabic mirrors layout RTL |
| Minimum target | At least 24 CSS px in the current token; use platform-accessible larger touch targets on mobile and high-frequency POS controls |

Status must always combine text, icon/shape, and color. Dates display in the user's locale while preserving `Africa/Cairo` business intent. Money displays exact EGP values derived from integer minor units. Quantities show friendly package conversions without losing the exact smallest-unit value.

## How to use these files with Stitch

Google Stitch accepts natural-language UI descriptions, supports conversational refinement and variants, and can transfer a selected result to Figma or frontend code. Each `Screen` section in this folder is therefore a self-contained generation brief in this order:

1. **Stitch target** — the exact platform, product shell, and starting canvas.
2. **Purpose** — the user goal and entry context.
3. **Layout** — the visual composition and hierarchy.
4. **Content and actions** — the components and calls to action that must appear.
5. **States** — use these for follow-up variants after the primary screen is generated.
6. **Safety/accessibility/rules** — hard constraints Stitch must preserve during refinement.

Start a Stitch conversation with the base prompt below, then copy one complete `Screen` section at a time. Generate the primary/default state first, then ask Stitch for the named loading, empty, error, conflict, offline, or success variants without changing the information architecture. For a `Multi-platform set`, create the listed frames separately; do not ask Stitch to blend phone and desktop navigation into one responsive composition.

### Stitch base prompt

```text
Create a high-fidelity healthcare product UI for a clinic platform in Egypt. Use a restrained, trustworthy visual system based on teal #00696D, white and soft neutral surfaces, 4/8/16/24/32 px spacing, and 4/8/16 px corner radii. Use system UI typography with Noto Sans Arabic support. The product is bilingual English and Arabic: preserve the same information hierarchy and actions in an Arabic RTL variant, with correct bidi handling for phone numbers, IDs, dates, times, and codes. Use only realistic synthetic names and data. Status must always use text and icon/shape in addition to color: operational #1B6C3A, degraded #8A5A00, unavailable/error #A4232B. Meet accessible contrast, keyboard/focus, screen-reader, large-text, and touch-target requirements. Keep the interface calm, professional, medically trustworthy, and information-dense only where the target platform calls for it. Do not invent features, permissions, clinical facts, infrastructure controls, or data that are excluded by the supplied screen brief.
```

Use realistic Egyptian clinic sample copy, but only synthetic names and data. Start in English for predictable component sizing, then create an Arabic RTL variant with the same hierarchy and actions. Do not add features that are explicitly excluded by the screen constraints.

### Stitch canvas targets

| Target label | Starting frame | Product treatment |
| --- | --- | --- |
| Mobile — Flutter patient app | 390 × 844 px | Material 3, phone-first, top app bar and contextual bottom navigation |
| Desktop — Electron doctor workspace | 1440 × 900 px | Dense clinical shell, left navigation, top context bar, optional right rail |
| Desktop — Electron pharmacy workspace | 1440 × 900 px | Branch/mode/online context, keyboard/barcode-first controls, accessible tables |
| Desktop web — React admin dashboard | 1440 × 1024 px | Responsive sidebar, page header, bounded filters, cards/tables/charts |

## Product shells

### Patient mobile

Use a phone-first Material 3 shell. Primary destinations are Home, Appointments, Find, Records, and Profile; high-priority contextual actions can appear in the Home hero. A top app bar owns the page title and contextual actions. Sensitive content should disappear from app-switcher previews where platform controls permit. Network, permission, and sync state must be visible without blocking safe read-only navigation.

### Doctor desktop

Use a dense but calm clinical workspace: persistent side navigation, top context bar, main work canvas, and an optional right rail for current-patient context or AI. The current consultation and access state must be impossible to confuse with a browsing context. Keyboard navigation, large-text reflow, resizable regions, and explicit save/sync status are core behavior.

### Pharmacy desktop

Use a branch-bound, keyboard/barcode-first shell. Keep branch, operating mode, connectivity, and actor capability visible in the top bar. POS favors large targets and uninterrupted scanner focus; inventory and purchasing favor accessible tables with detail drawers. Native stock-changing actions disappear or become clearly read-only for integrated branches.

### Admin web

Use a responsive navigation rail/sidebar, page header, bounded filters, and content cards/tables. The browser presents approved projections only: no clinical content, raw infrastructure access, arbitrary queries, secrets, or unrestricted exports. Freshness, suppression, unknown, and denied states are first-class.

## Global interaction rules

- Server state is authoritative. Realtime events trigger refetch; they do not directly complete clinical, booking, stock, or financial actions.
- Every risky write has explicit confirmation, an in-progress state, an outcome state, and safe recovery for timeout/unknown outcome. Duplicate taps never create a new intent.
- Offline mode is honest. Doctor clinical drafts may be locally encrypted where allowed; pharmacy stock, receipts, sales, returns, and refunds require online confirmation.
- Loading, refreshing, empty, zero, stale, degraded, offline, denied/not-found, validation, conflict, rate-limited, cancelled, retryable failure, permanent failure, and session-expired states are visually distinct where applicable.
- All user-facing copy has Arabic and English keys. RTL mirrors structure but not inherently directional data such as phone numbers, codes, or timelines where mirroring would reduce comprehension.
- Do not expose tokens, raw paths/object keys, National ID, precise coordinates, clinical text, prompts/responses, payment terminal data, or sensitive identifiers in URLs, logs, analytics, crash reports, or generic error copy.

## Document index

| File | Platform | Screens | Product area |
| --- | --- | ---: | --- |
| [01 Shared access and system](01_shared_access_and_system.md) | Mobile + separate desktop variants | 6 | Authentication, sessions, health |
| [02 Doctor profile and practice](02_doctor_profile_and_practice.md) | Electron desktop | 6 | Onboarding, locations, public profile |
| [03 Doctor schedule and queue](03_doctor_schedule_and_queue.md) | Electron desktop | 6 | Availability, dashboard, clinic operations |
| [04 Doctor clinical workspace](04_doctor_clinical_workspace.md) | Electron desktop | 6 | Consultation, record, drafts, completion |
| [05 Doctor prescriptions and labs](05_doctor_prescriptions_and_labs.md) | Electron desktop | 6 | Prescriptions, printing, labs, documents |
| [06 Doctor AI and communication](06_doctor_ai_and_communication.md) | Electron desktop | 6 | AI, private knowledge, notifications, chat |
| [07 Pharmacy setup and inventory](07_pharmacy_setup_and_inventory.md) | Electron desktop | 6 | Onboarding, branches, stock |
| [08 Pharmacy alerts and purchasing](08_pharmacy_alerts_and_purchasing.md) | Electron desktop | 6 | Alerts, owner view, suppliers, purchase receipt |
| [09 Pharmacy POS and sales](09_pharmacy_pos_and_sales.md) | Electron desktop | 6 | Cart, payment, invoices, returns |
| [10 Pharmacy integrations and AI](10_pharmacy_integrations_and_ai.md) | Electron desktop | 6 | Catalog, public projection, connectors, AI |
| [11 Patient profile and home](11_patient_profile_and_home.md) | Flutter mobile | 6 | Onboarding, home, profile, appointments entry |
| [12 Patient discovery and booking](12_patient_discovery_and_booking.md) | Flutter mobile | 6 | Doctor search through booking confirmation |
| [13 Patient appointments and records](13_patient_appointments_and_records.md) | Flutter mobile | 6 | Appointment management, queue, clinical reads |
| [14 Patient prescriptions and medicines](14_patient_prescriptions_and_medicines.md) | Flutter mobile | 6 | Prescriptions, reminders, medicine availability |
| [15 Patient labs and documents](15_patient_labs_and_documents.md) | Flutter mobile | 6 | Lab lifecycle, uploads, reports, viewing |
| [16 Patient communication and reviews](16_patient_communication_and_reviews.md) | Flutter mobile | 6 | Notifications, chat, reviews, settings |
| [17 Patient AI triage](17_patient_ai_triage.md) | Flutter mobile | 6 | Consent, intake, safety stop, result, booking handoff |
| [18 Admin operations](18_admin_operations.md) | Desktop web | 6 | Verification, medication catalog, moderation, templates |
| [19 Admin knowledge and oversight](19_admin_knowledge_and_oversight.md) | Desktop web | 6 | Knowledge, analytics, health, unresolved work |

## Primary sources

- [`plan.md`](../plan.md)
- [`docs/phases/README.md`](../docs/phases/README.md)
- Phase files `00` through `20`, especially each `Client work` section
- [`packages/typescript/design_tokens/src/index.ts`](../packages/typescript/design_tokens/src/index.ts)
- [`packages/flutter/design_system/lib/src/clinic_theme.dart`](../packages/flutter/design_system/lib/src/clinic_theme.dart)
- Current renderers under `apps/doctor-desktop`, `apps/pharmacy-desktop`, `apps/patient-app`, and `apps/admin-web`
- [Google Developers Blog — Introducing Stitch](https://developers.googleblog.com/en/stitch-a-new-way-to-design-uis/)
