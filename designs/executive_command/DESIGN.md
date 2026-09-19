---
name: Executive Command
colors:
  surface: '#fbf8fa'
  surface-dim: '#dcd9db'
  surface-bright: '#fbf8fa'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f5f3f4'
  surface-container: '#f0edef'
  surface-container-high: '#eae7e9'
  surface-container-highest: '#e4e2e3'
  on-surface: '#1b1b1d'
  on-surface-variant: '#45474c'
  inverse-surface: '#303032'
  inverse-on-surface: '#f3f0f2'
  outline: '#75777d'
  outline-variant: '#c5c6cd'
  surface-tint: '#545f73'
  primary: '#091426'
  on-primary: '#ffffff'
  primary-container: '#1e293b'
  on-primary-container: '#8590a6'
  inverse-primary: '#bcc7de'
  secondary: '#515f74'
  on-secondary: '#ffffff'
  secondary-container: '#d5e3fd'
  on-secondary-container: '#57657b'
  tertiary: '#1e1200'
  on-tertiary: '#ffffff'
  tertiary-container: '#35260c'
  on-tertiary-container: '#a38c6a'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d8e3fb'
  primary-fixed-dim: '#bcc7de'
  on-primary-fixed: '#111c2d'
  on-primary-fixed-variant: '#3c475a'
  secondary-fixed: '#d5e3fd'
  secondary-fixed-dim: '#b9c7e0'
  on-secondary-fixed: '#0d1c2f'
  on-secondary-fixed-variant: '#3a485c'
  tertiary-fixed: '#fadfb8'
  tertiary-fixed-dim: '#ddc39d'
  on-tertiary-fixed: '#271902'
  on-tertiary-fixed-variant: '#564427'
  background: '#fbf8fa'
  on-background: '#1b1b1d'
  surface-variant: '#e4e2e3'
typography:
  headline-lg:
    fontFamily: Inter
    fontSize: 30px
    fontWeight: '600'
    lineHeight: 38px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.05em
  code-md:
    fontFamily: jetbrainsMono
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 20px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  sidebar_width: 260px
  header_height: 64px
  container_max_width: 1440px
  gutter: 16px
  margin_sm: 8px
  margin_md: 16px
  margin_lg: 24px
  stack_gap: 12px
---

## Brand & Style

The design system is engineered for high-stakes administrative environments where precision, density, and security are paramount. The brand personality is **Authoritative**, **Precise**, and **Secure**, prioritizing utility over ornamentation to facilitate rapid data processing and decision-making.

The visual style is **Corporate / Modern** with a focus on structural integrity. It utilizes a restrained aesthetic characterized by crisp edges, a cool-toned palette, and a clear hierarchy. Information density is high, but legibility is maintained through a disciplined application of whitespace and systematic alignment. The emotional response should be one of competence and total control.

## Colors

The color palette is anchored in **Deep Navy (#1e293b)** for primary structural elements and **Professional Slate (#334155)** for secondary actions and iconography. Surfaces utilize a clinical **Slate-50 (#f8fafc)** to minimize eye strain during long working sessions.

Semantic colors are strictly reserved for operational status:
- **Emerald:** Indicates successful verification, active status, or completed processes.
- **Amber:** Indicates pending moderation, warnings, or time-sensitive alerts.
- **Rose:** Indicates critical errors, security breaches, or high-priority deletions.

Neutrals should follow a slate scale to maintain a cool, professional temperature across all UI states.

## Typography

This design system utilizes **Inter** for all primary interface elements due to its exceptional legibility at small sizes and high x-height, which is critical for dense data tables. **JetBrains Mono** is utilized as a secondary font for technical identifiers, IDs, and raw data strings to distinguish them from editorial content.

- **Headlines:** Use semi-bold weights with tight letter spacing for a grounded, authoritative feel.
- **Data Tables:** Use `body-md` (14px) as the standard for cell content.
- **Labels:** Use `label-md` for form headers and table column headers to provide clear structural scaffolding.

## Layout & Spacing

The layout follows a **Fixed-Fluid Hybrid** model designed for administrative efficiency:
- **Persistent Sidebar:** A 260px left navigation pane remains docked, providing constant access to top-level operational modules.
- **Global Header:** A 64px top bar contains contextual breadcrumbs, global search, and profile/security settings.
- **Main Content Area:** A centralized canvas that uses a 12-column grid. On desktop, content is constrained to a 1440px max-width to prevent line lengths from becoming unreadable on ultra-wide monitors.

Spacing is tight and systematic, utilizing an **8px base unit**. Density is prioritized; vertical padding in data tables should be minimized to 8px or 12px to maximize "above the fold" information.

## Elevation & Depth

Visual hierarchy is conveyed through **Tonal Layers** and **Low-Contrast Outlines** rather than heavy shadows. This maintains a flat, professional profile.

- **Level 0 (Background):** Slate-50 (#f8fafc).
- **Level 1 (Cards/Tables):** White (#ffffff) with a 1px border of Slate-200 (#e2e8f0).
- **Level 2 (Popovers/Modals):** White (#ffffff) with a 1px border of Slate-300 and a subtle, highly-diffused ambient shadow (0px 4px 12px rgba(0,0,0,0.05)).

Avoid all skeuomorphic effects. Depth is used only to indicate functional overlays (like dropdowns) or to separate the primary workspace from the background.

## Shapes

The shape language is **Sharp and Professional**. A standard radius of **4px (0.25rem)** is applied to buttons, input fields, and cards. This provides just enough softening to prevent the UI from feeling aggressive while maintaining a rigid, grid-aligned corporate aesthetic. 

Larger components like modals should not exceed 8px radius. Selection indicators (like sidebar active states) should use a 0px radius on the leading edge to emphasize their attachment to the screen border.

## Components

### Buttons
Primary buttons use the Deep Navy (#1e293b) background with white text. Secondary buttons use a white background with a 1px Slate-200 border. Buttons should have a height of 36px for standard operations, ensuring density in toolbars.

### Data Grids
Tables are the core of this system. Headers must be "Sticky," using a Slate-100 background and `label-md` typography. Rows should have a subtle hover state (#f1f5f9) and 1px bottom borders. Zebra striping is discouraged; use borders for cleaner separation in high-density views.

### Input Fields
Inputs use a white background, 1px Slate-300 border, and a 4px border-radius. The focus state uses a 1px Navy (#1e293b) border with a subtle 2px Slate-100 outer glow.

### Status Chips
Chips are used for moderation status. They use a low-opacity background of the semantic color with high-contrast text (e.g., Success: Emerald-100 background with Emerald-800 text). Shapes are 4px rounded, not pill-shaped.

### Form Sections
Long forms must be divided into bordered sections with clear `headline-sm` titles and supporting descriptions to reduce cognitive load during data entry.