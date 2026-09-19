---
name: Clinic Density
colors:
  surface: '#f9f9f9'
  surface-dim: '#dadada'
  surface-bright: '#f9f9f9'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f4f3f3'
  surface-container: '#eeeeee'
  surface-container-high: '#e8e8e8'
  surface-container-highest: '#e2e2e2'
  on-surface: '#1a1c1c'
  on-surface-variant: '#3e4949'
  inverse-surface: '#2f3131'
  inverse-on-surface: '#f1f1f1'
  outline: '#6e7979'
  outline-variant: '#bdc9c8'
  surface-tint: '#006a6a'
  primary: '#006565'
  on-primary: '#ffffff'
  primary-container: '#008080'
  on-primary-container: '#e3fffe'
  inverse-primary: '#76d6d5'
  secondary: '#6750a4'
  on-secondary: '#ffffff'
  secondary-container: '#bba2fd'
  on-secondary-container: '#4b3486'
  tertiary: '#8b4823'
  on-tertiary: '#ffffff'
  tertiary-container: '#a96039'
  on-tertiary-container: '#fff9f7'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#93f2f2'
  primary-fixed-dim: '#76d6d5'
  on-primary-fixed: '#002020'
  on-primary-fixed-variant: '#004f4f'
  secondary-fixed: '#e9ddff'
  secondary-fixed-dim: '#cfbcff'
  on-secondary-fixed: '#22005d'
  on-secondary-fixed-variant: '#4f378a'
  tertiary-fixed: '#ffdbcb'
  tertiary-fixed-dim: '#ffb692'
  on-tertiary-fixed: '#341100'
  on-tertiary-fixed-variant: '#733512'
  background: '#f9f9f9'
  on-background: '#1a1c1c'
  surface-variant: '#e2e2e2'
  status-waiting: '#f59e0b'
  status-consulting: '#008080'
  status-completed: '#6b7280'
  status-emergency: '#dc2626'
  calendar-exception: '#fef2f2'
  calendar-overlap: '#fff7ed'
  privacy-mask: '#e5e7eb'
typography:
  headline-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 15px
    fontWeight: '400'
    lineHeight: 22px
  body-md:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  body-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
  label-caps:
    fontFamily: Inter
    fontSize: 10px
    fontWeight: '700'
    lineHeight: 14px
    letterSpacing: 0.06em
  data-mono-compact:
    fontFamily: jetbrainsMono
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
  calendar-time:
    fontFamily: jetbrainsMono
    fontSize: 11px
    fontWeight: '500'
    lineHeight: 12px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  table-row-height: 32px
  calendar-interval-15: 40px
  calendar-interval-30: 80px
  grid-gutter: 12px
  hero-card-padding: 20px
  drawer-width: 380px
---

## Brand & Style

The design system is extended for **High-Density Operational Utility**, catering specifically to the fast-paced, information-heavy environment of a clinical workspace. The aesthetic is **Corporate / Modern** with a strict **Minimalist** discipline, emphasizing data throughput and rapid cognitive processing. 

The visual language transitions from general clinical authority to **Operational Precision**. Every pixel is prioritized for utility, ensuring that doctors can manage complex schedules and patient queues without visual noise. The emotional response is one of controlled efficiency, reliability, and extreme clarity, where the interface acts as a transparent layer over critical medical data.

## Colors

The color palette is expanded with a **Semantic Queue Palette** to provide instant status recognition in high-density environments. 

- **Primary (Clinic Teal):** Remains the anchor for clinical authority and "In Consultation" states.
- **Queue Statuses:** 
  - `status-waiting` (Amber) for pending patients.
  - `status-completed` (Gray) for post-consultation records.
  - `status-emergency` (Red) for no-shows or urgent triage.
- **Operational Backgrounds:** Subtle tints are introduced for calendar exceptions and overlap detection to prevent scheduling errors without the need for heavy iconography.
- **Privacy Mode:** A neutral `privacy-mask` gray is used for redaction overlays and restricted data states.

## Typography

Typography is optimized for **Vertical Density**. Font sizes are stepped down slightly from the standard workspace to maximize the amount of visible data in schedules and tables.

- **Data Alignment:** `data-mono-compact` is the default for tabular numbers, ensuring decimal points and values align for quick vertical scanning.
- **Calendar Scales:** `calendar-time` is utilized for time-axis increments (e.g., 15-min intervals), providing a technical, precise feel.
- **Headlines:** Scaled down to prevent "layout shift" when viewing large data grids on smaller laptop screens.

## Layout & Spacing

This design system introduces a **Density-First Layout Model** designed for operational screens.

- **Calendar Grid:** Uses a fixed-height interval system. A 15-minute slot defaults to 40px, providing enough touch/click target area while maintaining a daily overview. 
- **High-Density Tables:** Row heights are fixed at 32px. This "Compact" mode is mandatory for the Clinic Desk view.
- **Clinic Desk (Split-View):** Employs a 40/60 split between the queue management (left) and the Patient Hero/Documentation area (right).
- **Sticky Side Drawers:** All editing occurs in a 380px sticky right drawer to maintain the context of the main grid or calendar.

## Elevation & Depth

The system uses **High-Contrast Layering** rather than traditional shadows to maintain a clean, clinical feel.

- **The "Today" Indicator:** On the calendar, "Today" is indicated by a 1px solid `Clinic Teal` horizontal line with a small circular node at the start. 
- **Operational Layers:** Sticky side drawers use a 1px border (`outline-variant`) and a very subtle 8% opacity shadow to provide depth without obscuring background content.
- **Data Table Focus:** Keyboard navigation is highlighted using a 2px interior border in `Clinic Teal` on the active cell/row, ensuring high visibility for "no-mouse" workflows.

## Shapes

The design moves to a **Soft (0.25rem)** profile to maximize internal grid space. 

- **Calendar Blocks:** Use `rounded-sm` (2px) to ensure that back-to-back appointments don't lose time-axis precision due to excessive corner rounding.
- **Hero Cards:** Use `rounded-lg` (8px) to visually distinguish the "Current Patient" as the primary focus of the screen.
- **Status Indicators:** Pills and chips use `full` rounding (9999px) to stand out as interactive or status-bearing elements within a square-heavy grid.

## Components

### Hero Cards (Current Patient)
The "Hero" card is the single most important component on the Clinic Desk. It features an enlarged name display, prominent status chip, and a 4px left-accent border in `Clinic Teal`.

### High-Density Tables
- **Hover State:** Rows use a subtle `surface-container` background on hover.
- **Column Headers:** Use `label-caps` with a 1px bottom border.
- **Navigation:** Optimized for keyboard; arrow-key focus moves a distinct teal outline across cells.

### Calendar Intervals
- **Active Blocks:** Solid `primary-container` with white text.
- **Overlap States:** A `calendar-overlap` tint with a dashed border to signal scheduling conflicts.
- **Exceptions:** A striped background pattern using `calendar-exception` for clinic closures or holidays.

### Privacy & Masking
- **Masked Data:** Sensitive values are replaced with a series of rounded rectangles (pill shapes) in `privacy-mask` color.
- **Access Restricted:** Components or fields with withheld data use a "Low-Opactiy + Lock Icon" treatment to signal presence without revealing content.

### Operational Drawers
Slide-in from the right, pushing the main content rather than overlaying it where possible. They contain a persistent "Commit/Save" button at the bottom and a "Close/Dismiss" at the top right.