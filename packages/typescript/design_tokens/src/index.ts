/**
 * Design tokens shared across the React surfaces.
 *
 * **Values only — deliberately no components.** ADR 0010 is explicit that admin
 * React code cannot be copied wholesale into the desktops: admin uses browser
 * cookie/CSRF semantics while desktop uses device tokens behind IPC. A shared
 * *component* would eventually reach for a transport, a route, or a storage
 * API, and would then carry one application's security assumptions into the
 * other. A colour and a spacing scale cannot do that.
 */

export const palette = {
  seed: '#00696D',
  operational: '#1B6C3A',
  degraded: '#8A5A00',
  unavailable: '#A4232B',
} as const;

export const spacing = { xs: 4, sm: 8, md: 16, lg: 24, xl: 32 } as const;

export const radius = { sm: 4, md: 8, lg: 16 } as const;

export const typography = {
  fontFamily:
    "system-ui, -apple-system, 'Segoe UI', Roboto, 'Noto Sans Arabic', 'Helvetica Neue', Arial, sans-serif",
  size: { sm: 12, body: 14, title: 18, heading: 24 },
} as const;

/** Supported UI locales. Arabic is right-to-left. */
export const locales = ['ar', 'en'] as const;
export type Locale = (typeof locales)[number];

export const isRtl = (locale: string): boolean => locale.startsWith('ar');

/**
 * Minimum touch/click target, in CSS pixels.
 *
 * WCAG 2.2 target size. Stated as a token so a pharmacy POS button and an admin
 * table action cannot drift apart, and so nobody has to remember the number.
 */
export const minimumTargetSize = 24;
