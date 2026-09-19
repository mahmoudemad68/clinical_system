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
  primary: '#24389c'
  on-primary: '#ffffff'
  primary-container: '#3f51b5'
  on-primary-container: '#cacfff'
  inverse-primary: '#bac3ff'
  secondary: '#006a6a'
  on-secondary: '#ffffff'
  secondary-container: '#90efef'
  on-secondary-container: '#006e6e'
  tertiary: '#38454b'
  on-tertiary: '#ffffff'
  tertiary-container: '#505c63'
  on-tertiary-container: '#c7d4dc'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dee0ff'
  primary-fixed-dim: '#bac3ff'
  on-primary-fixed: '#00105c'
  on-primary-fixed-variant: '#293ca0'
  secondary-fixed: '#93f2f2'
  secondary-fixed-dim: '#76d6d5'
  on-secondary-fixed: '#002020'
  on-secondary-fixed-variant: '#004f4f'
  tertiary-fixed: '#d7e4ed'
  tertiary-fixed-dim: '#bcc8d1'
  on-tertiary-fixed: '#111d23'
  on-tertiary-fixed-variant: '#3c484f'
  background: '#f4faff'
  on-background: '#161c20'
  surface-variant: '#dde3e8'
  status-success: '#1b5e20'
  status-warning: '#e65100'
  status-error: '#ba1a1a'
  surface-border: '#ddeaf2'
  data-fill: '#e9f6fd'
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

The design system is a high-precision framework engineered specifically for the rigorous demands of pharmacy operations and medical administration. The brand personality is rooted in **Clinical Precision**: an aesthetic that prioritizes cognitive clarity, speed of data processing, and an unwavering sense of professional reliability. 

The style is a hybrid of **Minimalism** and **Corporate Modern**, optimized for high-density desktop environments. It eschews decorative flourishes in favor of structural integrity. By utilizing a restrained color palette and a strict 4px grid, the system minimizes visual noise, allowing pharmacists to navigate complex medication workflows, contraindication alerts, and inventory logs with absolute confidence and reduced fatigue.

## Colors

The color strategy is designed for long-duration focus and critical status communication. 

- **Primary (Deep Indigo):** Used for primary actions and global navigation, projecting stability and institutional trust.
- **Secondary (Teal):** Used for accenting positive workflows and specialized clinical modules to provide visual distinction from administrative tasks.
- **Neutral:** A cool-toned "Surface" system that reduces eye strain by utilizing subtle blue tints rather than pure greys.
- **Urgency Colors:** A high-visibility system for status indicators. These colors must maintain a high contrast ratio against the light surface colors to ensure alerts are never missed. Success, Warning, and Error tokens are reserved strictly for clinical status and inventory safety.

## Typography

**Inter** serves as the primary typeface due to its exceptional legibility and neutral tone, which is critical for administrative interfaces. To prevent life-critical errors in medication dispensing, **JetBrains Mono** is introduced for all alphanumeric data strings (SKUs, batch numbers, and dosages) to ensure clear character differentiation (e.g., distinguishing "1", "l", and "I").

The typographic scale is intentionally compact to support high-density layouts. Vertical rhythm is strictly enforced via 4px increments to ensure that even the smallest body text maintains readability under standard pharmacy lighting conditions. Large display sizes are used sparingly for dashboard KPIs, while `label-caps` is utilized for table headers to maximize vertical space.

## Layout & Spacing

This design system uses a **Fixed Grid** model for desktop environments, ensuring that dense data tables and side-by-side patient records remain consistent and predictable.

- **Grid:** A 12-column grid system with 16px gutters.
- **High-Density Mode:** The layout is optimized for an "always-on" desktop experience. A "Dense" mode is available for complex inventory tables, reducing row heights to 32px to maximize the amount of information visible on-screen.
- **Breakpoints:**
  - **Desktop (Standard):** 1440px. Full sidebar and 24px page margins.
  - **Desktop (Compact):** 1280px. Sidebar collapses to icons; gutters remain at 16px.
- **Content Reflow:** In pharmaceutical workflows, columns should never drop below their minimum readable width; instead, horizontal scrolling is permitted within data-grid components to preserve table structure.

## Elevation & Depth

To maintain the clinical aesthetic, depth is achieved through **Tonal Layers** and **Low-Contrast Outlines** rather than aggressive shadows. This prevents the interface from feeling "heavy" and ensures clarity in complex, nested layouts.

- **Level 0 (Base):** The `neutral` color (#f4faff) acts as the foundation for the application canvas.
- **Level 1 (Work Area):** Main content cards and tables use white (#FFFFFF) with a 1px border (#ddeaf2).
- **Level 2 (Interaction):** Floating elements like dropdowns or tooltips use a high-diffused, 8% opacity shadow with a subtle indigo tint to suggest "lift" without creating visual clutter.
- **Focus States:** Active inputs and focused elements are highlighted with a 2px solid `primary` ring with a 2px offset to ensure accessibility in fast-paced operational environments.

## Shapes

The design system adopts a **Soft (0.25rem)** shape language. This provides a clean, contemporary feel that suggests approachability while the low radius maintain a sense of precision and professional discipline.

- **0.125rem (Small):** Checkboxes, radio buttons, and small utility tags.
- **0.25rem (Default):** Standard buttons, input fields, and alert banners.
- **0.5rem (Large):** Main content cards, prescription containers, and modal dialogs.
- **Full (Pill):** Used exclusively for status indicators (e.g., "In Stock", "High Priority") to ensure they are visually distinct from interactive square-ish buttons.

## Components

### High-Density Data Tables
The centerpiece of the system. Tables must include sticky headers, zebra-striping using `surface-container-low`, and horizontal-only dividers to emphasize the data row. Text alignment must be strict: labels are left-aligned, and all numerical/dosage data is right-aligned using `data-mono`.

### Status Chips
Status chips use a "Pill" shape. They employ a light background tint of the status color with high-saturation text for maximum legibility. 
- *Example:* "Urgent" uses a pale red fill with `#ba1a1a` text.

### Clinical Input Fields
Inputs must include a clear, persistent label. In error states, the border changes to `status-error` with a supporting error icon. Use a 1px `surface-border` for default states to keep the UI light.

### Buttons
- **Primary:** Solid indigo with white text for main path actions (e.g., "Fill Prescription").
- **Secondary/Outline:** Teal borders for secondary clinical actions.
- **Critical:** Solid red used only for destructive actions (e.g., "Void Record").

### Alert Banners
Banners are full-width and pinned to the top of containers. They use high-saturation left-side borders (4px) to denote urgency without overwhelming the content area with color.