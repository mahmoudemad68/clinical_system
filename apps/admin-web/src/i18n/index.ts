import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { ar } from './ar';
import { en } from './en';

/**
 * Arabic and English (plan.md section 148). No hard-coded strings anywhere in
 * the component tree.
 *
 * Arabic is right-to-left, and direction is a document-level concern, so the
 * language change handler sets `dir` on <html>. Getting that wrong is the most
 * visible possible localization bug.
 */
export const SUPPORTED_LOCALES = ['en', 'ar'] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

export function isRtl(locale: string): boolean {
  return locale.startsWith('ar');
}

export function applyDocumentDirection(locale: string): void {
  const dir = isRtl(locale) ? 'rtl' : 'ltr';
  document.documentElement.setAttribute('dir', dir);
  document.documentElement.setAttribute('lang', locale);
}

void i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, ar: { translation: ar } },
  lng: 'en',
  fallbackLng: 'en',
  supportedLngs: [...SUPPORTED_LOCALES],
  interpolation: {
    // React already escapes interpolated values; double-escaping mangles them.
    escapeValue: false,
  },
});

i18n.on('languageChanged', applyDocumentDirection);

export default i18n;
