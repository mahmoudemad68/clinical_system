import 'package:flutter/widgets.dart';

/// User-visible strings, resolved by locale.
///
/// Hand-written rather than generated from ARB for Phase 00, which carries a
/// handful of strings. The interface is the same shape a generated class would
/// expose, so moving to `flutter_localizations` codegen later does not change
/// any call site.
class ClinicStrings {
  const ClinicStrings(this.locale);

  final Locale locale;

  static ClinicStrings of(BuildContext context) =>
      ClinicStrings(Localizations.localeOf(context));

  bool get _ar => locale.languageCode == 'ar';

  String get appTitlePatient => _ar ? 'عيادتي' : 'My Clinic';
  String get appTitleDoctor => _ar ? 'تطبيق الطبيب' : 'Doctor Workspace';
  String get appTitlePharmacy => _ar ? 'تطبيق الصيدلية' : 'Pharmacy Workspace';

  String get healthTitle => _ar ? 'حالة المنصة' : 'Platform health';
  String get healthLoading =>
      _ar ? 'جارٍ التحقق من حالة المنصة…' : 'Checking platform health…';
  String get healthUnreachable =>
      _ar ? 'تعذر الوصول إلى المنصة.' : 'The platform could not be reached.';
  String get healthRetry => _ar ? 'إعادة المحاولة' : 'Retry';

  String get version => _ar ? 'الإصدار' : 'Version';
  String get serverTime => _ar ? 'توقيت الخادم' : 'Server time';
  String get requestId => _ar ? 'معرف الطلب' : 'Request ID';

  String get componentCore => _ar ? 'الأساسي' : 'Core';
  String get componentRealtime => _ar ? 'الزمن الفعلي' : 'Realtime';
  String get componentAi => _ar ? 'الذكاء الاصطناعي' : 'AI';

  String get statusOperational => _ar ? 'تعمل' : 'Operational';
  String get statusDegraded => _ar ? 'مُتدهورة' : 'Degraded';
  String get statusUnavailable => _ar ? 'غير متاحة' : 'Unavailable';

  String get language => _ar ? 'اللغة' : 'Language';

  String get signIn => _ar ? 'دخول' : 'Sign in';
  String get signOut => _ar ? 'خروج' : 'Sign out';
  String get phone => _ar ? 'رقم الجوال' : 'Mobile number';
  String get password => _ar ? 'كلمة المرور' : 'Password';
  String get otpCode => _ar ? 'رمز التحقق' : 'Verification code';
  String get register => _ar ? 'إنشاء حساب' : 'Create account';
  String get name => _ar ? 'الاسم' : 'Name';
  String get nationalId => _ar ? 'الرقم القومي' : 'National ID';
  String get sessions => _ar ? 'الجلسات' : 'Sessions';
  String get revoke => _ar ? 'إلغاء' : 'Revoke';
  String get authFailed =>
      _ar ? 'تعذر إكمال تسجيل الدخول.' : 'Sign-in could not be completed.';
}
