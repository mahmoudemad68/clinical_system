/**
 * Shared locale negotiation and the string catalogue common to every React
 * surface.
 *
 * Pure functions and data. No hard-coded strings may appear in a component
 * (plan.md section 148): a string in a component is a string that will never be
 * translated.
 */

export const SUPPORTED_LOCALES = ['ar', 'en'] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

export const DEFAULT_LOCALE: Locale = 'en';

export const isRtl = (locale: string): boolean => locale.startsWith('ar');

export const direction = (locale: string): 'rtl' | 'ltr' => (isRtl(locale) ? 'rtl' : 'ltr');

/**
 * Resolve an untrusted locale tag to a supported one.
 *
 * Matches on the primary subtag so `ar-EG` resolves to `ar` rather than falling
 * back to English. An unsupported, oversized, or malformed value falls back
 * silently: locale is presentation, and failing a request over it would turn a
 * cosmetic client bug into an outage.
 */
export function resolveLocale(requested: string | null | undefined, fallback: Locale = DEFAULT_LOCALE): Locale {
  if (typeof requested !== 'string' || requested === '' || requested.length > 64) {
    return fallback;
  }

  const primary = requested.toLowerCase().split('-')[0];

  return (SUPPORTED_LOCALES as readonly string[]).includes(primary ?? '')
    ? (primary as Locale)
    : fallback;
}

/** Strings common to every React surface. Application-specific strings stay local. */
export const sharedStrings = {
  en: {
    health: {
      title: 'Platform health',
      loading: 'Checking platform health…',
      unreachable: 'The platform could not be reached.',
      retry: 'Retry',
      version: 'Version',
      serverTime: 'Server time',
      requestId: 'Request ID',
      components: { core: 'Core', realtime: 'Realtime', ai: 'AI' },
      status: { operational: 'Operational', degraded: 'Degraded', unavailable: 'Unavailable' },
    },
    common: { language: 'Language', offline: 'Offline', retry: 'Retry' },
  },
  ar: {
    health: {
      title: 'حالة المنصة',
      loading: 'جارٍ التحقق من حالة المنصة…',
      unreachable: 'تعذر الوصول إلى المنصة.',
      retry: 'إعادة المحاولة',
      version: 'الإصدار',
      serverTime: 'توقيت الخادم',
      requestId: 'معرف الطلب',
      components: { core: 'الأساسي', realtime: 'الزمن الفعلي', ai: 'الذكاء الاصطناعي' },
      status: { operational: 'تعمل', degraded: 'مُتدهورة', unavailable: 'غير متاحة' },
    },
    common: { language: 'اللغة', offline: 'غير متصل', retry: 'إعادة المحاولة' },
  },
} as const;

export type SharedStrings = (typeof sharedStrings)[Locale];
