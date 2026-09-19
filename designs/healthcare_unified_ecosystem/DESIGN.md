---
name: Healthcare Unified Ecosystem
colors:
  surface: '#fdf7ff'
  surface-dim: '#ded8e0'
  surface-bright: '#fdf7ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f8f2fa'
  surface-container: '#f2ecf4'
  surface-container-high: '#ece6ee'
  surface-container-highest: '#e6e0e9'
  on-surface: '#1d1b20'
  on-surface-variant: '#494551'
  inverse-surface: '#322f35'
  inverse-on-surface: '#f5eff7'
  outline: '#7a7582'
  outline-variant: '#cbc4d2'
  surface-tint: '#6750a4'
  primary: '#4f378a'
  on-primary: '#ffffff'
  primary-container: '#6750a4'
  on-primary-container: '#e0d2ff'
  inverse-primary: '#cfbcff'
  secondary: '#63597c'
  on-secondary: '#ffffff'
  secondary-container: '#e1d4fd'
  on-secondary-container: '#645a7d'
  tertiary: '#765b00'
  on-tertiary: '#ffffff'
  tertiary-container: '#c9a74d'
  on-tertiary-container: '#503d00'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#e9ddff'
  primary-fixed-dim: '#cfbcff'
  on-primary-fixed: '#22005d'
  on-primary-fixed-variant: '#4f378a'
  secondary-fixed: '#e9ddff'
  secondary-fixed-dim: '#cdc0e9'
  on-secondary-fixed: '#1f1635'
  on-secondary-fixed-variant: '#4b4263'
  tertiary-fixed: '#ffdf93'
  tertiary-fixed-dim: '#e7c365'
  on-tertiary-fixed: '#241a00'
  on-tertiary-fixed-variant: '#594400'
  background: '#fdf7ff'
  on-background: '#1d1b20'
  surface-variant: '#e6e0e9'
typography:
  h1-desktop:
    fontSize: 40px
    fontWeight: '700'
    lineHeight: 48px
    letterSpacing: -0.02em
  h1-mobile:
    fontSize: 28px
    fontWeight: '700'
    lineHeight: 34px
    letterSpacing: -0.01em
  h2-desktop:
    fontSize: 32px
    fontWeight: '600'
    lineHeight: 40px
  body-lg:
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-sm:
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-caps:
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.05em
  mono-data:
    fontFamily: jetbrainsMono
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  unit: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  2xl: 48px
  gutter-desktop: 24px
  margin-mobile: 16px
---

## Brand & Style

The design system establishes a cohesive visual language for a multi-faceted healthcare ecosystem. It balances high-stakes utility for professionals with empathetic accessibility for patients. The style is **Corporate / Modern** with subtle **Minimalist** influences, focusing on clarity, trust, and rhythmic consistency.

### Product Personas
- **Patient (Mobile-First):** Focuses on "Soft Empathetic Tech." High whitespace, gentle transitions, and approachable touch targets.
- **Doctor (Efficiency-Driven):** A dense, professional environment that minimizes cognitive load and maximizes data visibility.
- **Pharmacy (High-Contrast Retail):** Optimized for fast-paced environments with clear distinction between inventory and prescription actions.
- **Admin (Data-Heavy):** A utilitarian "Dashboard-First" approach using tight grids and high-density information display.

### Visual Principles
- **Clarity over Decoration:** Every element serves a functional purpose.
- **Semantic Consistency:** Status colors and iconography remain constant across all product shells to reduce cross-role confusion.
- **Accessibility:** All color pairings and interactive elements meet WCAG AA standards as a baseline.

## Colors

The palette is segmented by user role to provide immediate environmental context (Product Shelling), while maintaining shared semantic colors for system-wide health and actions.

- **Patient Shell:** Utilizes soft teals and calming blues to reduce anxiety.
- **Doctor Shell:** Deep navy provides a stable, authoritative backdrop for clinical decision-making.
- **Pharmacy Shell:** Emerald green signifies growth and health, paired with charcoal for high-readability in retail lighting.
- **Admin Shell:** Neutral grays ensure the focus remains on data, with vibrant blue reserved strictly for primary actions.

**Functional Colors:**
- **Success:** Operational Green for completed tasks and healthy statuses.
- **Warning:** Degraded Amber for pending actions or system warnings.
- **Error:** Unavailable Red for critical failures or clinical contraindications.

## Typography

This design system utilizes **Inter** for its exceptional legibility and neutral tone, ensuring clarity across diverse screen types. 

### Implementation Guidelines
- **Mobile Scaling:** Large headlines scale down by ~30% on mobile devices to preserve screen real estate for content cards.
- **Data Tables:** For the Admin and Doctor workspaces, use the monospaced secondary font for numerical data and IDs to ensure vertical alignment and quick scanning.
- **Hierarchy:** Use `label-caps` for section headers in sidebars and small metadata fields to create structural distinction without increasing font size.

## Layout & Spacing

The system uses a **4px baseline grid** to ensure mathematical harmony. 

### Grid Logic
- **Mobile (Patient):** A single-column fluid layout with 16px side margins. Cards occupy the full width of the viewport.
- **Desktop (Workspace/Admin):** A 12-column fixed grid for main content areas, often flanked by a 240px fixed sidebar. Gutters are set at 24px to provide "breathing room" between dense data points.
- **Shell Structure:** Each product features a persistent top navigation (64px height) that houses the product-specific branding and universal system status indicators.

## Elevation & Depth

Visual hierarchy is managed through **Tonal Layers** and **Low-Contrast Outlines**. Shadows are used sparingly to signify interactivity or temporary overlays.

- **Level 0 (Background):** The base canvas color (e.g., `#F5F6F7`).
- **Level 1 (Cards/Surface):** White background with a 1px border (`#E0E0E0`). No shadow. Used for standard data grouping.
- **Level 2 (Interaction):** White background with a soft, diffused shadow (0px 4px 12px rgba(0,0,0,0.05)). Used for hovered cards and dropdowns.
- **Level 3 (Modals):** High-contrast shadow (0px 12px 32px rgba(0,0,0,0.12)). Used for critical patient alerts or prescription confirmations.

## Shapes

The shape language reflects the emotional intent of each shell while maintaining a shared foundation.

- **Standard (8px):** Applied to buttons, input fields, and standard cards across all products.
- **Soft (16px+):** Used exclusively in the **Patient Shell** for primary call-to-action cards and navigation elements to evoke a friendlier, non-institutional feel.
- **Sharp (0-4px):** Reserved for data-heavy tables and tabs in the **Admin Workspace** to maximize space efficiency.

## Components

### Product Shells
Each application is wrapped in a "Product Shell." While internal components are shared, the header background and logo change based on the role (e.g., Navy for Doctors, Teal for Patients).

### Accessible Form Fields
- Labels must always be visible (no floating labels that disappear).
- Error states must include both a red border and an icon for color-blind accessibility.
- Help text is positioned below the input field in `body-sm`.

### Stepper Components
Used for multi-step flows like "Prescription Renewal" or "New Patient Intake." 
- **Desktop:** Horizontal stepper with text labels.
- **Mobile:** Progress bar with "Step X of Y" text to save space.

### Cards
- **Patient Card:** High padding (24px), soft corners, and large icons.
- **Clinical Card:** Compact padding (12px), sharp borders, and high-density text for rapid review.

### Status Indicators
Small, circular "pills" or badges.
- **Operational:** Solid background with white text.
- **Degraded/Unavailable:** Outline style with colored text to emphasize the alert nature without overwhelming the UI.