---
name: Clinical Clarity
colors:
  surface: '#f7fafc'
  surface-dim: '#d7dadc'
  surface-bright: '#f7fafc'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f1f4f6'
  surface-container: '#ebeef0'
  surface-container-high: '#e5e9eb'
  surface-container-highest: '#e0e3e5'
  on-surface: '#181c1e'
  on-surface-variant: '#414752'
  inverse-surface: '#2d3133'
  inverse-on-surface: '#eef1f3'
  outline: '#717783'
  outline-variant: '#c1c6d4'
  surface-tint: '#005faf'
  primary: '#005dac'
  on-primary: '#ffffff'
  primary-container: '#1976d2'
  on-primary-container: '#fffdff'
  inverse-primary: '#a5c8ff'
  secondary: '#006b5f'
  on-secondary: '#ffffff'
  secondary-container: '#9cefdf'
  on-secondary-container: '#0b6f63'
  tertiary: '#46606c'
  on-tertiary: '#ffffff'
  tertiary-container: '#5f7986'
  on-tertiary-container: '#fdfeff'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d4e3ff'
  primary-fixed-dim: '#a5c8ff'
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
  background: '#f7fafc'
  on-background: '#181c1e'
  surface-variant: '#e0e3e5'
  success: '#2e7d32'
  warning: '#f57c00'
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
  headline-lg-mobile:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: '600'
    lineHeight: 36px
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
  margin-mobile: 16px
  gutter: 16px
---

## Brand & Style

This design system is engineered for **Modern Medical Professionalism**, focusing on patient trust and cognitive ease. The aesthetic is a fusion of **Corporate/Modern** reliability and **Minimalist** efficiency, utilizing Material 3 principles to create a high-trust environment for doctor discovery and healthcare management.

The visual narrative is objective and calm. By leveraging generous whitespace and a structured information hierarchy, the system ensures that critical data—such as specialist credentials, clinic locations, and appointment slots—is immediately legible and reduces the anxiety often associated with medical booking.

## Colors

The palette is anchored by **Primary Indigo**, a color synonymous with institutional stability and clinical competence. **Soft Teal** is utilized as a secondary accent specifically for health-promoting or proactive wellness actions to distinguish them from administrative tasks.

Surface colors follow the Material 3 tonal container model. Use `surface-container-lowest` (#ffffff) for card backgrounds to pop against the `surface` (#f7fafc) background. Status colors (Success, Warning, Error) must be used with high-contrast text and low-opacity backgrounds (10-15%) for chips and alerts to ensure they are informative without being alarming.

## Typography

**Inter** is the sole typeface, chosen for its clinical neutrality and high legibility at small sizes. 

- **Hierarchy:** Use `title-lg` for doctor names and section headers. `body-md` is the default for descriptions and clinic addresses.
- **Labels:** Use `label-sm` in all-caps or medium weight for metadata (e.g., "SPECIALTY", "EXPERIENCE") to create a clear data-key relationship.
- **Readability:** For long-form text like doctor biographies, maintain `body-lg` to ensure accessibility for older patients or those with visual impairment.

## Layout & Spacing

The system uses a **Fluid Grid** model with an 8px rhythmic scale. For mobile devices, a 4-column grid is standard with 16px side margins.

- **Touch Targets:** All interactive elements (buttons, date pickers, chips) must maintain a minimum hit area of 48x48dp.
- **Container Padding:** Standardize on `md` (16px) for internal padding of doctor profile cards. Use `lg` (24px) for vertical spacing between logical groups (e.g., separating "About" from "Location").
- **Reflow:** On tablets, the grid expands to 8 columns; ensure profile cards do not exceed 600px in width to maintain readable line lengths.

## Elevation & Depth

This design system uses **Tonal Layers** to establish hierarchy, minimizing heavy shadows to maintain a clean, clinical look.

- **Level 0 (App Background):** #f7fafc.
- **Level 1 (Cards & Content Blocks):** #ffffff with a 1px soft stroke (`outline-variant`) and a very subtle ambient shadow (Blur: 4px, Opacity: 0.05).
- **Level 2 (Navigation & Persistent Bars):** Bottom navigation and top app bars use a subtle Primary Indigo tint (5% opacity) to distinguish them from scrolling content.
- **Overlays:** Use backdrop blurs (12px) for modals to focus the patient on specific booking tasks or confirmation prompts.

## Shapes

The shape language is **Rounded**, providing an approachable and modern feel while remaining structured.

- **Primary Containers:** 8px (`rounded`) for input fields and standard buttons.
- **Large Components:** 16px (`rounded-lg`) for doctor profile cards and specialty chips.
- **Bottom Sheets:** 24px (`rounded-xl`) on the top corners only to suggest a "drawer" metaphor.

## Components

**Doctor Profile Cards (Material 3)**
Profile cards use the `elevated` style. Internal padding is `md` (16px). The doctor’s photo should be a rounded square (8px) or circle depending on clinic branding. Use `title-lg` for the name and `label-lg` for the specialty.

**Specialty Chips**
Chips are rounded (16px) with a 1px border. Use the secondary color tint for the background and the secondary color for the text when selected. Always include a leading icon relevant to the specialty (e.g., a heart icon for Cardiology).

**Horizontal Date Picker**
A scrollable row of cards representing days. The active state uses a Primary Indigo background with white text, while inactive days use a `surface-container` background with `on-surface-variant` text.

**Slot Buttons**
High-contrast buttons for time slots. Use a white background with a Primary Indigo border for available slots, and a full Primary Indigo fill for the selected slot. Disabled slots should be `surface-dim` with a strike-through or reduced opacity.

**Inputs**
Standard Material 3 outlined text fields. The label must float to the top border on focus. Error states must trigger a red border change and include a supporting error icon for accessibility.