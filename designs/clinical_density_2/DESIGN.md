---
name: Clinical Density
colors:
  surface: '#f4faff'
  surface-dim: '#d5dbe0'
  surface-bright: '#f4faff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eef4f9'
  surface-container: '#e9eff4'
  surface-container-high: '#e3e9ee'
  surface-container-highest: '#dde3e8'
  on-surface: '#161c20'
  on-surface-variant: '#454652'
  inverse-surface: '#2b3135'
  inverse-on-surface: '#ebf1f6'
  outline: '#757684'
  outline-variant: '#c5c5d4'
  surface-tint: '#4355b9'
  primary: '#011d86'
  on-primary: '#ffffff'
  primary-container: '#24389c'
  on-primary-container: '#9dabff'
  inverse-primary: '#bac3ff'
  secondary: '#006a6a'
  on-secondary: '#ffffff'
  secondary-container: '#9deeed'
  on-secondary-container: '#0b6e6e'
  tertiary: '#222f34'
  on-tertiary: '#ffffff'
  tertiary-container: '#38454b'
  on-tertiary-container: '#a4b2b9'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dee0ff'
  primary-fixed-dim: '#bac3ff'
  on-primary-fixed: '#00105b'
  on-primary-fixed-variant: '#283ca0'
  secondary-fixed: '#a0f0f0'
  secondary-fixed-dim: '#84d4d3'
  on-secondary-fixed: '#002020'
  on-secondary-fixed-variant: '#004f4f'
  tertiary-fixed: '#d7e5ec'
  tertiary-fixed-dim: '#bbc9d0'
  on-tertiary-fixed: '#101d23'
  on-tertiary-fixed-variant: '#3c494f'
  background: '#f4faff'
  on-background: '#161c20'
  surface-variant: '#dde3e8'
  sync-fresh: '#1b5e20'
  sync-stale: '#757684'
  sync-running: '#3f51b5'
  sync-failed: '#ba1a1a'
  ai-knowledge: '#4355b9'
  ai-live: '#006a6a'
  confidence-high: '#1b5e20'
  confidence-medium: '#e65100'
  confidence-low: '#ba1a1a'
  surface-border: '#ddeaf2'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  title-sm:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  data-mono:
    fontFamily: JetBrains Mono
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
  label-caps:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 16px
    letterSpacing: 0.05em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  base-unit: 4px
  gutter: 16px
  margin: 24px
  row-dense: 32px
  row-standard: 40px
  sidebar-width: 260px
  sidebar-collapsed: 64px
---

## Brand & Style

This design system is a high-precision framework engineered for the rigorous demands of pharmacy operations, AI-assisted diagnostics, and medical administration. The brand personality is rooted in **Clinical Precision**: an aesthetic that prioritizes cognitive clarity, speed of data processing, and an unwavering sense of professional reliability.

The style is a hybrid of **Minimalism** and **Corporate Modern**, optimized for high-density desktop environments. It eschews decorative flourishes in favor of structural integrity. By utilizing a restrained "Clinical Indigo" palette and a strict 4px grid, the system minimizes visual noise, allowing clinicians to navigate complex medication workflows, AI-generated insights, and synchronization logs with absolute confidence and reduced fatigue. The visual tone is "Utility-First," where every element serves a functional purpose in a high-stakes medical environment.

## Colors

The color strategy is designed for long-duration focus and critical status communication within complex pharmacy modules.

- **Primary (Clinical Indigo):** Reserved for primary actions and global navigation, projecting stability and institutional trust.
- **Secondary (Teal):** Used for accenting positive clinical workflows and "Live Data" AI modules.
- **Neutral:** A cool-toned surface system that reduces eye strain by utilizing subtle blue tints (#f4faff) rather than pure greys.
- **Sync & Confidence Status:** A specialized set of tokens used to communicate the "health" of pharmacy data integration. "Fresh" uses success green, "Stale" uses neutral outline grey, "Running" uses a bright indigo, and "Failed" uses error red.
- **AI Response Cards:** Distinct color coding differentiates "Knowledge" (static medical literature, Primary-tinted) from "Live Data" (real-time pharmacy inventory or patient records, Secondary-tinted).

## Typography

**Inter** is the primary typeface, chosen for its exceptional legibility and clinical neutrality. To prevent life-critical errors in medication dispensing and data mapping, **JetBrains Mono** is introduced for all alphanumeric data strings (SKUs, batch numbers, confidence percentages, and dosages).

The typographic scale is intentionally compact to support high-density layouts. Vertical rhythm is strictly enforced via 4px increments. Large display sizes are used sparingly for dashboard KPIs, while `label-caps` is utilized for table headers to maximize vertical space. All mapping confidence levels and data synchronization timestamps must use `data-mono` to ensure every character is distinct.

## Layout & Spacing

This design system uses a **Fixed Grid** model for desktop environments, ensuring that dense data tables and side-by-side pharmacy records remain consistent and predictable.

- **Grid:** A 12-column grid system with 16px gutters and 24px page margins.
- **High-Density Mode:** Optimized for "always-on" desktop experiences. Row heights are reduced to 32px (dense) or 40px (standard) to maximize visible data.
- **Breakpoints:** The system targets 1440px (Standard) and 1280px (Compact). On compact screens, the sidebar collapses to icons to preserve the workspace for data-heavy tables.
- **Content Reflow:** Horizontal scrolling is preferred within data-grid components over column wrapping to preserve the structural integrity of medication lists and sync logs.

## Elevation & Depth

To maintain the clinical aesthetic, depth is achieved through **Tonal Layers** and **Low-Contrast Outlines** rather than aggressive shadows. This prevents the interface from feeling "heavy" in complex, nested AI modules.

- **Level 0 (Base):** The foundation canvas uses the neutral surface color.
- **Level 1 (Work Area):** Main content cards, AI response containers, and data grids use white surfaces with a 1px `surface-border` (#ddeaf2).
- **Level 2 (Interaction):** Floating elements like dropdowns or AI tooltips use a highly diffused 8% opacity shadow with a subtle indigo tint.
- **Focus States:** Active inputs and focused clinical fields are highlighted with a 2px solid `primary` ring with a 2px offset.

## Shapes

The design system adopts a **Soft (4px)** shape language. This provides a professional feel that maintains precision and suggests an authoritative, system-level architecture.

- **Sharp (2px):** Used for checkboxes, radio buttons, and mapping confidence tags.
- **Soft (4px / Default):** Standard buttons, input fields, and AI response cards.
- **Rounded-LG (8px):** Modal dialogs and major dashboard containers.
- **Pill:** Reserved exclusively for status indicators (e.g., Sync Status, Confidence Levels) to ensure they are visually distinct from interactive buttons.

## Components

### AI Response Cards
Two distinct card types for AI interactions:
- **Knowledge Cards:** Use a subtle `primary` (Indigo) left-accent border and light indigo background tint for medical literature or historical data.
- **Live Data Cards:** Use a `secondary` (Teal) left-accent border for real-time pharmacy inventory, patient vitals, or active sync data.

### Sync Status Indicators
Small pill-shaped indicators located in headers or table cells. They must include both an icon and a `data-mono` label:
- **Fresh:** Green icon/text.
- **Running:** Indigo spinning icon/text.
- **Stale/Failed:** Grey or Red icon/text respectively.

### Confidence Level Tags
Small tags displayed next to AI-mapped fields. They use a background fill and `data-mono` text.
- **High (>=90%):** Success green tint.
- **Medium (60-89%):** Warning orange tint.
- **Low (<60%):** Error red tint.

### High-Density Data Tables
Features include sticky headers, zebra-striping using `surface-container-low`, and horizontal-only dividers. Text alignment is strict: labels are left-aligned; numerical and dosage data are right-aligned using `data-mono`.

### Buttons
- **Primary:** Solid Clinical Indigo with white text.
- **Secondary:** Outline Teal for clinical secondary actions.
- **Ghost:** Minimal padding for utility actions in dense rows.