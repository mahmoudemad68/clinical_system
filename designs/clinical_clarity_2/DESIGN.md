---
name: Clinical Clarity
colors:
  surface: '#f9f9ff'
  surface-dim: '#d8dae1'
  surface-bright: '#f9f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f2f3fb'
  surface-container: '#ebeef0'
  surface-container-high: '#e7e8ef'
  surface-container-highest: '#e1e2e9'
  on-surface: '#191c21'
  on-surface-variant: '#414752'
  inverse-surface: '#2e3036'
  inverse-on-surface: '#eff0f8'
  outline: '#727783'
  outline-variant: '#c1c6d3'
  surface-tint: '#065fae'
  primary: '#004583'
  on-primary: '#ffffff'
  primary-container: '#005dac'
  on-primary-container: '#bfd7ff'
  inverse-primary: '#a6c8ff'
  secondary: '#006b5f'
  on-secondary: '#ffffff'
  secondary-container: '#9cefdf'
  on-secondary-container: '#0b6f63'
  tertiary: '#2e4854'
  on-tertiary: '#ffffff'
  tertiary-container: '#46606c'
  on-tertiary-container: '#bedae8'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d4e3ff'
  primary-fixed-dim: '#a6c8ff'
  on-primary-fixed: '#001c3a'
  on-primary-fixed-variant: '#004786'
  secondary-fixed: '#9ff2e2'
  secondary-fixed-dim: '#83d5c6'
  on-secondary-fixed: '#00201c'
  on-secondary-fixed-variant: '#005047'
  tertiary-fixed: '#cbe7f5'
  tertiary-fixed-dim: '#afcbd9'
  on-tertiary-fixed: '#011f29'
  on-tertiary-fixed-variant: '#304a56'
  background: '#f9f9ff'
  on-background: '#191c21'
  surface-variant: '#e1e2e9'
  status-corrected: '#1976d2'
  status-expired: '#ba1a1a'
  status-active: '#006b5f'
  status-uncertain: '#46606c'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 40px
    fontWeight: '700'
    lineHeight: 48px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '600'
    lineHeight: 40px
    letterSpacing: 0px
  headline-lg-mobile:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: '600'
    lineHeight: 36px
    letterSpacing: 0px
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  title-lg:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '500'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  label-lg:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.1px
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
    letterSpacing: 0.5px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  touch-target: 48px
  margin-mobile: 16px
  gutter: 16px
---

## Brand & Style

This design system is engineered for **Modern Medical Professionalism**, emphasizing high-trust interactions within the pharmacy and prescription ecosystem. The visual narrative is defined by clinical precision, reliability, and a calm user experience intended to alleviate the stress of managing medications.

The design style is **Corporate / Modern** with a strict adherence to **Material 3** principles. It utilizes a structured "Surface-Container" architecture to organize complex medical data—such as drug interactions and dosage schedules—into digestible, card-based modules. The interface remains objective and utilitarian, prioritizing information density and legibility over decorative trends, ensuring it serves as a dependable tool for both patients and healthcare providers.

## Colors

The color strategy uses a **medical-grade palette** to communicate status and reliability. The primary blue is utilized for navigational elements and "Corrected" states, signifying systemic updates. The secondary teal is reserved for "Active" health states, providing a positive reinforcement for current medications.

**Semantic Status Indicators:**
- **Active:** Secondary Green/Teal (#006b5f) — Indicates a valid, current prescription.
- **Expired:** Error Red (#ba1a1a) — Clear warning for lapsed medication.
- **Corrected:** Primary Blue (#1976d2) — Indicates an updated dosage or instruction.
- **Uncertain Availability:** Tertiary Grey/Slate (#46606c) — Neutral tone for inventory ambiguity.

The palette maintains WCAG AA contrast ratios across all surface levels, ensuring accessibility for users with varying visual acuity.

## Typography

The design system uses **Inter** as a singular, robust typeface to handle the high-density requirements of medical instructions. It provides excellent legibility for complex chemical names and multi-line dosage instructions.

**Bilingual Support (EN/AR):**
- **Alignment:** Typography must strictly follow the directionality of the locale (LTR for English, RTL for Arabic).
- **Line Height:** For Arabic script, increase `lineHeight` by 1.15x across all levels to prevent the overlapping of diacritics.
- **Hierarchy:** Use `title-lg` for medicine names and `body-md` for standard dosage instructions. Use `label-sm` in all-caps (English only) or bold (Arabic) for metadata like "Refill Date" or "Pharmacy Distance."

## Layout & Spacing

The layout follows a **Fluid Grid** model optimized for Flutter's `LayoutBuilder`. On mobile, we use a 4-column system with a 16px gutter. 

**Prescription-Specific Layout:**
- **RTL Logic:** In Arabic mode, the layout is mirrored. Leading icons (e.g., medicine icons) move to the right, and trailing chevron icons move to the left and are horizontally flipped.
- **Touch Targets:** All interactive elements, specifically "Refill" buttons and pharmacy contact icons, must maintain a minimum `touch-target` of 48px to accommodate users with limited dexterity.
- **Vertical Rhythm:** A consistent 16px (`md`) gap is maintained between card items in a list to prevent visual crowding.

## Elevation & Depth

Hierarchy is established via **Tonal Layers** rather than heavy shadows, following Material 3 guidelines.

- **Level 0 (Background):** Surface color (#f7fafc).
- **Level 1 (Default Card):** `surface-container-lowest` (#ffffff) with a 1px `outline-variant` (#c1c6d4). No shadow.
- **Level 2 (Active Item):** Subtle shadow (Blur: 8px, Offset-Y: 4px, Opacity: 0.04) and a 1px `primary` border to indicate focus or selection.
- **Overlays:** Full-screen dialogs and bottom sheets use a `scrim` (40% opacity on-surface) to isolate medication search or filter tasks.

## Shapes

The shape language is **Rounded (0.5rem / 8px)**, striking a balance between clinical efficiency and approachable patient care.

- **Prescription Cards:** Use `rounded-lg` (16px) to define distinct medicine modules.
- **Search Bars & Input Fields:** Use `rounded-lg` (16px) for a modern, soft appearance.
- **Status Chips:** Use `rounded-full` (9999px) to clearly distinguish them from buttons and other square-format data containers.
- **Bottom Sheets:** Apply `rounded-xl` (24px) to top-left and top-right corners only.

## Components

**Prescription Cards**
- **Container:** `surface-container-lowest` with 16px padding.
- **Header:** Medicine name in `title-lg`, leading status icon.
- **Content:** Dosage instructions in `body-md`. 
- **Footer:** Status chips and primary action (e.g., "Find in Pharmacy").

**Pharmacy Branch Cards**
- **Layout:** Vertical stack on mobile. Leading section contains branch name and "Distance" label. 
- **Actions:** Prominent "Call" and "Navigate" buttons using `secondary-container` backgrounds to indicate high-utility health actions.

**Status Chips (Semantic)**
- **Active:** Teal background (10% opacity) + Teal text + Checkmark icon.
- **Expired:** Red background (10% opacity) + Red text + Alert icon.
- **Uncertain:** Slate background (10% opacity) + Slate text + Help/Question icon.

**Medicine Discovery Search**
- Outlined input field with leading search icon and trailing filter icon.
- Suggestions appear in a `surface-container-high` dropdown with 8px vertical spacing between items.

**Interaction States**
- **Hover/Pressed:** 8% overlay of `on-surface` for neutral elements; 10% overlay of `on-primary` for primary buttons.