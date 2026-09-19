---
name: HealthPath Modern Clinical
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
  background: '#f7fafc'
  on-background: '#181c1e'
  surface-variant: '#e0e3e5'
  surface-lowest: '#ffffff'
  error-red: '#ba1a1a'
  success-teal: '#006b5f'
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
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  margin-mobile: 16px
  margin-tablet: 32px
  gutter: 16px
---

## Brand & Style
HealthPath embodies a **Corporate Modern** aesthetic tailored for the healthcare sector. The brand personality is professional, reliable, and calm, prioritizing clarity and trust. 

The visual style utilizes a systematic approach inspired by Material 3 principles but refined with a softer, clinical color palette. It balances high-functionality with high-legibility, using generous whitespace and subtle tonal shifts to reduce cognitive load for patients managing their health data. The interface feels "clean" rather than "clinical," substituting cold medical aesthetics for warm, accessible interactions.

## Colors
The palette is rooted in a "Fidelity" color logic, where the primary blue (`#005dac`) signifies authority and trust. 

- **Primary:** Used for key actions and branding.
- **Secondary:** A calming teal reserved for status indicators (e.g., "Confirmed") and wellness-related icons.
- **Neutral/Background:** A very cool gray-blue (`#f7fafc`) that provides a soft, non-reflective base for long-form reading.
- **Surface Tiers:** Uses a multi-layered surface system where `surface-lowest` (white) is used for elevated cards to create maximum contrast against the background.

## Typography
The system uses **Inter** exclusively to maintain a utilitarian and systematic feel. 

- **Headlines:** Use semi-bold weights with tight tracking to anchor sections. On mobile, the `headline-lg` scales down to 28px to ensure no awkward wrapping of patient names.
- **Body:** Set at a comfortable 16px or 18px to ensure accessibility for users with varying visual acuity.
- **Labels:** Used for metadata and button text, often utilizing `uppercase` or `letter-spacing` for high-density information like timestamps or category headers.

## Layout & Spacing
The layout follows a **Fluid Grid** model with a specific focus on vertical rhythm for mobile-first views.

- **Margins:** A standard 16px (`margin-mobile`) side margin is used to maximize horizontal space on narrow devices.
- **Grid:** Quick actions are arranged in a 4-column grid on mobile, reflowing to 6 or 8 columns on tablet.
- **Padding:** Internal card padding is strictly 16px (`md`) to maintain a consistent density across different content types (Hero cards vs Vitals cards).
- **Vertical Rhythm:** Sections are separated by `lg` (24px) spacing, while related items within a section use `xs` or `sm` (4-8px).

## Elevation & Depth
Depth is achieved through **Tonal Layers** and **Low-Contrast Outlines** rather than heavy shadows.

- **Surface Levels:** The background sits at the lowest level. Primary cards (like the "Today" hero) are white with a subtle `shadow-sm` and a 1px border of `surface-variant`.
- **Active States:** Buttons use subtle scale transforms (`active:scale-95`) rather than deep inner shadows to indicate physical interaction.
- **Separation:** A light 1px border (`#c1c6d4`) is the primary method of separating components, keeping the UI flat and modern. 
- **Top/Bottom Bars:** These use `shadow-sm` and `shadow-lg` respectively to indicate they float above the scrollable main content area.

## Shapes
The shape language is **Soft** and approachable.

- **Standard Containers:** Cards and large sections use `rounded-xl` (12px or 0.75rem).
- **Buttons & Inputs:** Use a standard `rounded-lg` (8px or 0.5rem) for a professional look.
- **Icon Buttons/Avatars:** These are strictly `full` (circular) to distinguish them from actionable data containers.
- **Special Accents:** Use a 4px top-accent bar on hero cards to tie the component to the brand color without overwhelming the interior content.

## Components
- **Buttons:** Primary buttons are solid `primary` color with `on-primary` text. Secondary/Icon buttons use `outline` styles with 1px borders.
- **Action Chips (Grid):** Circular background for icons (`surface-container-high`) with labels centered below. Use `active:scale-95` for tactile feedback.
- **Hero Cards:** Feature a top accent-line (4px) in the primary color. They include a header area with a status badge (e.g., "Confirmed") that uses a low-opacity background of the status color.
- **Vitals Micro-Cards:** A horizontal layout with a high-contrast icon container (circular) and a two-line text stack (label + value).
- **Bottom Navigation:** Uses a `surface-container` background with an "active pill" indicator around the current icon/label pair to ensure the active state is unmistakable.
- **Badges:** Small notification pips (error-red) are placed on the top-right of icons for high visibility.