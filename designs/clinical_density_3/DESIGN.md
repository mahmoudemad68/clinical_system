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
  status-success: '#1b5e20'
  status-warning: '#e65100'
  status-error: '#ba1a1a'
  scanner-active: '#3f51b5'
  data-fill: '#e9f6fd'
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
  unit: 4px
  gutter: 16px
  margin-desktop: 24px
  row-dense: 32px
  row-standard: 40px
  sidebar-width: 260px
  sidebar-collapsed: 64px
---

## Brand & Style
The design system is a high-precision framework engineered for the rigorous demands of pharmacy operations and medical administration. The brand personality is rooted in **Clinical Precision**: an aesthetic that prioritizes cognitive clarity, speed of data processing, and professional reliability.

The style is a hybrid of **Minimalism** and **Corporate Modern**, optimized for high-density desktop environments. It minimizes visual noise through structural integrity and a strict grid, allowing pharmacists to navigate complex medication workflows and inventory logs with absolute confidence. The visual tone is "Utility-First," where every element serves a functional purpose in a high-stakes medical environment.

## Colors
The color strategy is designed for long-duration focus and critical status communication.

- **Primary (Deep Indigo):** Used for primary actions and global navigation to project stability.
- **Secondary (Teal):** Accents positive clinical workflows, distinguishing them from administrative tasks.
- **Neutral:** A cool-toned surface system using subtle blue tints to reduce eye strain compared to stark greys.
- **Urgency Colors:** A high-visibility system for status indicators (Success, Warning, Error). These tokens are reserved strictly for clinical status, inventory safety, and financial alerts.
- **Scanner State:** A specific high-visibility blue (`scanner-active`) indicates when the system is actively polling for barcode input.

## Typography
**Inter** is the primary typeface, chosen for its exceptional legibility in administrative interfaces. To prevent life-critical errors in medication dispensing, **JetBrains Mono** is utilized for all alphanumeric data strings (SKUs, batch numbers, and dosages) to ensure clear character differentiation (e.g., distinguishing "1", "l", and "I").

The scale is compact to support high-density layouts. Use `label-caps` for table headers to maximize vertical space. All financial totals and quantity counts should default to `data-mono` for tabular alignment and clarity.

## Layout & Spacing
This design system uses a **Fixed Grid** model for desktop environments, ensuring that dense data tables and side-by-side patient records remain consistent.

- **Grid:** A 12-column system with 16px gutters.
- **High-Density Mode:** Optimized for "always-on" desktop usage. A "Dense" mode reduces row heights to 32px to maximize on-screen information.
- **Breakpoints:**
  - **Desktop (Standard):** 1440px. Full sidebar and 24px margins.
  - **Desktop (Compact):** 1280px. Sidebar collapses to icons.
- **Content Reflow:** In pharmaceutical workflows, horizontal scrolling is preferred over column wrapping to preserve the structural integrity of complex data tables.

## Elevation & Depth
Depth is achieved through **Tonal Layers** and **Low-Contrast Outlines** to maintain clinical clarity without visual clutter.

- **Level 0 (Base):** The `neutral` background color acts as the foundation canvas.
- **Level 1 (Work Area):** Primary content cards and data grids use white surfaces with a 1px `surface-border`.
- **Level 2 (Interaction):** Overlays like dropdowns or tooltips use a highly diffused 8% opacity shadow with a subtle indigo tint.
- **Focus States:** Active inputs are highlighted with a 2px solid `primary` ring with a 2px offset for high visibility during rapid keyboard entry.

## Shapes
The design system adopts a **Soft (0.25rem)** shape language. This provides a professional feel that maintains precision.

- **Small (2px):** Used for checkboxes, radio buttons, and utility tags.
- **Default (4px):** Standard buttons, input fields, and alert banners.
- **Large (8px):** Main content cards and modal dialogs.
- **Pill:** Reserved exclusively for status indicators (e.g., "In Stock") to distinguish them from interactive square-ish buttons.

## Components

### High-Density Data Tables
The core component. Features include sticky headers, zebra-striping using `surface-container-low`, and horizontal-only dividers. Text alignment is strict: labels are left-aligned; numerical, dosage, and financial data are right-aligned using `data-mono`.

### Status Chips
Use the "Pill" shape with a light background tint of the status color and high-saturation text for maximum legibility.

### Clinical Input Fields
Must include persistent labels. In error states, the border changes to `status-error` with a supporting error icon. Use 1px `surface-border` for default states.

### Buttons
- **Primary:** Solid indigo with white text for main path actions (e.g., "Fill Prescription").
- **Secondary/Outline:** Teal borders for clinical secondary actions.
- **Critical:** Solid red for destructive actions (e.g., "Void Record").

### Financial Summaries
Large-format totals at the bottom of the POS screen should use `headline-md` in `data-mono` to ensure every digit is distinct and readable from a distance.

### Alert Banners
Full-width and pinned to container tops. Use 4px high-saturation left-side borders to denote urgency without overwhelming the workspace with color.