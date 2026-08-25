import 'package:flutter/widgets.dart';

/// Locales the clinic clients support.
///
/// Arabic first because it is the primary language of the patient base; the
/// doctor client defaults to English but is switchable (plan.md section 148).
class ClinicLocales {
  const ClinicLocales._();

  static const Locale arabic = Locale('ar');
  static const Locale english = Locale('en');

  static const List<Locale> supported = [arabic, english];

  /// Arabic is right-to-left. Flutter derives direction from the locale, but
  /// anything that lays out manually must ask rather than assume.
  static bool isRtl(Locale locale) => locale.languageCode == 'ar';

  /// Resolve a device locale to one we support.
  ///
  /// Matches on language code only, so `ar-EG`, `ar-SA`, and `ar` all resolve
  /// to Arabic rather than falling back to English.
  static Locale resolve(Locale? deviceLocale, {Locale fallback = english}) {
    if (deviceLocale == null) {
      return fallback;
    }

    for (final locale in supported) {
      if (locale.languageCode == deviceLocale.languageCode) {
        return locale;
      }
    }

    return fallback;
  }
}
