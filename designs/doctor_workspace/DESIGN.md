---
name: Doctor Workspace
colors:
  surface: '#f9f9f9'
  surface-dim: '#dadada'
  surface-bright: '#f9f9f9'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f3f3'
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
  secondary-container: '#bca2fd'
  on-secondary-container: '#4c3386'
  tertiary: '#5c595f'
  on-tertiary: '#ffffff'
  tertiary-container: '#757177'
  on-tertiary-container: '#fdf7fe'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#93f2f2'
  primary-fixed-dim: '#76d6d5'
  on-primary-fixed: '#002020'
  on-primary-fixed-variant: '#004f4f'
  secondary-fixed: '#e9ddff'
  secondary-fixed-dim: '#d0bcff'
  on-secondary-fixed: '#22005c'
  on-secondary-fixed-variant: '#4f378a'
  tertiary-fixed: '#e6e1e8'
  tertiary-fixed-dim: '#cac5cc'
  on-tertiary-fixed: '#1d1b20'
  on-tertiary-fixed-variant: '#48464b'
  background: '#f9f9f9'
  on-background: '#1a1c1c'
  surface-variant: '#e2e2e2'
  clinic-teal: '#008080'
  clinical-bg: '#fdf7ff'
  requirement-border: '#cbc4d2'
  split-divider: '#ece6ee'
typography:
  headline-lg:
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
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-caps:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 16px
    letterSpacing: 0.06em
  data-mono:
    fontFamily: jetbrainsMono
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  unit: 4px
  gutter: 24px
  margin: 32px
  sidebar-width: 260px
  preview-pane-min: 400px
---

## Brand & Style

The design system for the Doctor Workspace extension focuses on **Clinical Precision** and **High-Fidelity Utility**. It is designed for an Electron-based desktop environment where cognitive load must be minimized to support critical decision-making. The aesthetic is **Corporate / Modern** with a lean toward **Minimalism**, prioritizing information density and functional clarity over decorative elements.

The target audience consists of healthcare professionals who require a "heads-up display" experience. The UI evokes a sense of reliability and sterile efficiency through a structured grid, high-contrast text, and a focused color palette that distinguishes the clinical environment from other ecosystem roles.

## Colors

The palette is anchored by **Clinic Teal (#008080)**, a professional and calming primary color that signifies the clinical workspace. This color is used for primary actions, active navigation states, and progress indicators. 

- **Primary:** Clinic Teal for clinical authority and primary workflows.
- **Secondary:** Retained from the broader ecosystem for cross-product consistency in system-level actions.
- **Neutral:** A range of cool grays and off-whites to manage density without causing eye strain during long shifts.
- **Semantic:** Success (Green), Warning (Amber), and Error (Red) colors are applied strictly to medical alerts and data validation.

## Typography

This design system utilizes **Inter** exclusively for UI text to ensure maximum legibility and a neutral, professional tone. A secondary monospaced font is used for clinical data points and laboratory values to facilitate rapid scanning and vertical alignment.

- **Scale:** Sizes are slightly tighter than the patient-facing shell to accommodate the higher information density required in a 1440x900px workspace.
- **Hierarchy:** `label-caps` is the primary tool for section headers within cards and sidebars, creating clear boundaries without requiring excessive vertical space.

## Layout & Spacing

The design system employs a **12-column fixed grid** optimized for the 1440px Electron shell. The layout is structured around a multi-pane architecture to support complex clinical workflows.

- **Multi-Pane Strategy:** A fixed left sidebar (260px) handles navigation, while the main content area utilizes a **Split-Screen Preview** model. The preview pane (minimum 400px) allows doctors to view patient history or reference material alongside their current documentation.
- **Rhythm:** An 8px/4px grid ensures consistency. Gutters are fixed at 24px to prevent data-heavy columns from visually merging.
- **Density:** Padding is reduced in clinical cards (12px to 16px) compared to the patient shell to maximize the "above the fold" information.

## Elevation & Depth

To maintain a utilitarian feel, the design system avoids heavy shadows, instead using **Tonal Layers** and **Crisp Outlines** to define hierarchy.

- **Surface Levels:** The main workspace uses the base surface. Content cards use the `surface-container-lowest` (white) with a 1px `outline-variant` border.
- **Split Screens:** The divider between the primary workspace and the preview pane is a solid 1px vertical line in `split-divider`. 
- **Active State:** Only active modals or floating tooltips receive a soft, low-opacity shadow to indicate they are "breaking" the 2D plane of the medical record.

## Shapes

The shape language is **Soft (0.25rem)**, reflecting clinical precision and professional efficiency. 

- **Buttons & Inputs:** Use the base 4px (0.25rem) radius.
- **Requirement Cards:** Larger container elements may use 8px (0.5rem) to distinguish them as major UI blocks.
- **Data Tables:** These remain sharp (0px) or utilize the minimum 4px radius on the outer container only to preserve alignment with monospaced data rows.

## Components

### Steppers
Clinical workflows (like diagnostic entry) use a **Horizontal Stepper** at the top of the workspace. Steps are represented by Clinic Teal circles with `label-caps` text. Completed steps use a checkmark icon to provide immediate visual confirmation of progress.

### Requirement Cards
Used to highlight missing patient information or mandatory clinical steps. These cards feature a 2px left-border accent in `Clinic Teal` (or `Error Red` if critical) and a subtle background tint to draw attention without obstructing the rest of the dashboard.

### Split-Screen Previews
A core navigation pattern where clicking a record opens a secondary viewing pane on the right. This pane includes its own internal header and scroll area, allowing the doctor to reference data while typing in the primary field.

### Clinical Input Fields
Inputs are compact with labels placed above the field. Monospaced font is used for numerical fields (e.g., Blood Pressure, Heart Rate) to ensure clarity. Active states use a 2px `Clinic Teal` border.

### Action Bar
A persistent footer or header within specific modules that houses primary actions (Save, Sign, Send). This bar is always anchored to ensure the doctor can finalize a record from any scroll position.