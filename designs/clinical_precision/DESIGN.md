---
name: Clinical Precision
colors:
  surface: '#f4faff'
  surface-dim: '#cfdce4'
  surface-bright: '#f4faff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#e9f6fd'
  surface-container: '#e3f0f8'
  surface-container-high: '#ddeaf2'
  surface-container-highest: '#d7e4ec'
  on-surface: '#111d23'
  on-surface-variant: '#454652'
  inverse-surface: '#263238'
  inverse-on-surface: '#e6f3fb'
  outline: '#757684'
  outline-variant: '#c5c5d4'
  surface-tint: '#4355b9'
  primary: '#24389c'
  on-primary: '#ffffff'
  primary-container: '#3f51b5'
  on-primary-container: '#cacfff'
  inverse-primary: '#bac3ff'
  secondary: '#5b5f61'
  on-secondary: '#ffffff'
  secondary-container: '#e0e3e6'
  on-secondary-container: '#626567'
  tertiary: '#3f434c'
  on-tertiary: '#ffffff'
  tertiary-container: '#575a64'
  on-tertiary-container: '#cfd2dd'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dee0ff'
  primary-fixed-dim: '#bac3ff'
  on-primary-fixed: '#00105c'
  on-primary-fixed-variant: '#293ca0'
  secondary-fixed: '#e0e3e6'
  secondary-fixed-dim: '#c4c7ca'
  on-secondary-fixed: '#191c1e'
  on-secondary-fixed-variant: '#44474a'
  tertiary-fixed: '#e0e2ee'
  tertiary-fixed-dim: '#c4c6d2'
  on-tertiary-fixed: '#181b24'
  on-tertiary-fixed-variant: '#434750'
  background: '#f4faff'
  on-background: '#111d23'
  surface-variant: '#d7e4ec'
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
    fontSize: 18px
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
  container-padding: 24px
  gutter: 16px
  row-height-dense: 32px
  row-height-standard: 48px
  sidebar-width: 260px
---

## Brand & Style

The design system is engineered for high-stakes pharmacy environments where precision, reliability, and security are paramount. The brand personality is authoritative yet approachable, minimizing cognitive load for pharmacists managing complex medication workflows. 

The aesthetic follows a **Corporate / Modern** direction with a focus on high-density information architecture. It utilizes a structured grid, subtle tonal layering, and purposeful whitespace to ensure that critical medical data remains the focal point. Every interaction is designed to feel deliberate and stable, reinforcing the user's trust in the system's accuracy.

## Colors

The palette is centered around **Clinical Indigo (#3F51B5)**, a color chosen to project stability and professionalism while differentiating from traditional medical teals. 

- **Primary:** Used for key actions, active navigation states, and primary buttons.
- **Secondary/Surface:** A cool-toned neutral used for page backgrounds and subtle section containers to reduce eye strain during long shifts.
- **Status Colors:** High-contrast tokens specifically for pharmacy inventory:
  - **Success:** Fulfilled orders and adequate stock.
  - **Warning:** Expiring meds or low stock thresholds.
  - **Error:** Out of stock, expired items, or contraindication alerts.

## Typography

The design system utilizes **Inter** for its exceptional legibility in dense interfaces. A vertical rhythm is maintained to ensure that even small font sizes remain readable under fluorescent pharmacy lighting.

- **Headlines:** Reserved for page titles and high-level dashboard metrics.
- **Body:** The primary workhorse for patient records and prescription details. 
- **Data Mono:** We use **JetBrains Mono** for drug SKUs, batch numbers, and dosage quantities to prevent character confusion (e.g., 1 vs l, 0 vs O).
- **Label Caps:** Used for table headers and small metadata tags to provide clear hierarchy without taking up excessive vertical space.

## Layout & Spacing

The design system employs a **Fixed Grid** philosophy for desktop-first operational efficiency. The layout is structured around a persistent left-hand navigation rail and a flexible content area that utilizes a 12-column grid.

- **Density:** To accommodate large datasets, the system supports a "Dense" mode where row heights are reduced to 32px.
- **Breakpoints:**
  - **Desktop (1440px+):** Full sidebar, 12 columns, 24px margins.
  - **Tablet (768px - 1439px):** Collapsed icon-only sidebar, 8 columns, 16px margins.
  - **Mobile (Below 768px):** Bottom navigation, 4 columns, 16px margins (Focus on emergency lookups only).

## Elevation & Depth

To maintain a "Clinical" feel, elevation is primarily conveyed through **Tonal Layers** rather than heavy shadows. This keeps the interface clean and reduces visual clutter.

- **Level 0 (Background):** Secondary color (#F5F7FA) for the main application canvas.
- **Level 1 (Cards/Tables):** White (#FFFFFF) surfaces with a subtle 1px border (#E0E4E8).
- **Level 2 (Modals/Popovers):** White surfaces with a soft, neutral-tinted shadow (0px 4px 12px rgba(38, 50, 56, 0.08)).
- **Focus States:** High-contrast 2px indigo rings to assist with keyboard navigation in fast-paced environments.

## Shapes

The design system uses a **Soft (0.25rem)** roundedness level. This provides a modern look while maintaining a sense of structural rigidity and professional discipline. 

- **Small (4px):** Used for input fields, checkboxes, and buttons.
- **Medium (8px):** Used for cards, prescription containers, and modal dialogs.
- **Pill:** Reserved exclusively for status badges (Stock, Status) to distinguish them from interactive buttons.

## Components

### High-Density Tables
Tables are the core of the pharmacy workspace. They must feature sticky headers, sortable columns, and row hover states. Use horizontal borders only to emphasize the linear flow of data.

### Status Badges
Status badges use the "Pill" shape and low-saturation background fills with high-saturation text:
- **Low Stock:** Orange text on pale orange background.
- **Expired:** Red text on pale red background.
- **Active:** Green text on pale green background.

### Stepper-Based Forms
For medication intake or order fulfillment, use a vertical stepper on the left of the form container. This ensures the pharmacist always knows their progress in multi-step clinical workflows.

### Document Upload Cards
Use a dashed border (#C5CAE9) for drag-and-drop zones. Once uploaded, documents should be displayed as small preview cards with metadata (file size, timestamp, uploader) and a secure "Verified" checkmark icon.

### Buttons
- **Primary:** Solid Clinical Indigo with white text.
- **Secondary:** Clinical Indigo border with transparent background.
- **Critical:** Solid Red for "Cancel Order" or "Delete Record" actions.