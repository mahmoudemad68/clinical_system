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

  String get continueAction => _ar ? 'متابعة' : 'Continue';
  String get backAction => _ar ? 'رجوع' : 'Back';
  String get refreshAction => _ar ? 'تحديث الحالة' : 'Refresh status';
  String get retryAction => _ar ? 'إعادة المحاولة' : 'Try again';
  String get saveAction => _ar ? 'حفظ' : 'Save';
  String get cancelAction => _ar ? 'إلغاء' : 'Cancel';

  String get onboardingTitle =>
      _ar ? 'إنشاء الملف الشخصي' : 'Create your profile';
  String get onboardingStepIdentity => _ar ? 'الهوية' : 'Identity';
  String get onboardingStepDemographics =>
      _ar ? 'البيانات الشخصية' : 'Demographics';
  String get onboardingStepMeasurements =>
      _ar ? 'قياسات مبلغ عنها ذاتياً' : 'Self-reported measurements';
  String get onboardingStepReview => _ar ? 'مراجعة' : 'Review';
  String get onboardingSubmit => _ar ? 'إرسال الملف الشخصي' : 'Submit profile';
  String get onboardingIntro => _ar
      ? 'أدخل بياناتك الشخصية. الرقم القومي يُستخدم للتحقق فقط ولا يُعرض لاحقاً.'
      : 'Enter your details. National ID is used for verification only and is not shown later.';
  String get fullName => _ar ? 'الاسم الكامل' : 'Full name';
  String get gender => _ar ? 'النوع' : 'Gender';
  String get genderMale => _ar ? 'ذكر' : 'Male';
  String get genderFemale => _ar ? 'أنثى' : 'Female';
  String get dateOfBirth => _ar ? 'تاريخ الميلاد' : 'Date of birth';
  String get maritalStatus => _ar ? 'الحالة الاجتماعية' : 'Marital status';
  String get maritalSingle => _ar ? 'أعزب/عزباء' : 'Single';
  String get maritalMarried => _ar ? 'متزوج/متزوجة' : 'Married';
  String get maritalDivorced => _ar ? 'مطلق/مطلقة' : 'Divorced';
  String get maritalWidowed => _ar ? 'أرمل/أرملة' : 'Widowed';
  String get optionalField => _ar ? 'اختياري' : 'Optional';
  String get heightCm => _ar ? 'الطول (سم)' : 'Height (cm)';
  String get weightKg => _ar ? 'الوزن (كجم)' : 'Weight (kg)';
  String get bloodType => _ar ? 'فصيلة الدم' : 'Blood type';
  String get selfReportedLabel => _ar ? 'مبلغ عنه ذاتياً' : 'Self-reported';
  String get selfReportedHint => _ar
      ? 'الطول والوزن وفصيلة الدم مبلغ عنها ذاتياً. فصيلة الدم ليست نتيجة مختبر.'
      : 'Height, weight, and blood type are self-reported. Blood type is not laboratory verified.';
  String get storageBoundsHint => _ar
      ? 'القيم خارج نطاق التخزين المسموح تُرفض. هذا ليس توصية طبية.'
      : 'Values outside the allowed storage range are rejected. This is not medical advice.';
  String get reviewHeading =>
      _ar ? 'راجع بياناتك قبل الإرسال' : 'Review before submitting';
  String get nationalIdNotShown => _ar
      ? 'الرقم القومي لا يظهر هنا ولا يُحفظ على هذا الجهاز.'
      : 'National ID is not shown here and is not stored on this device.';
  String get notProvided => _ar ? 'غير مُدخل' : 'Not provided';

  String get manualReviewTitle =>
      _ar ? 'مراجعة الحساب قيد التنفيذ' : 'Account review in progress';
  String get manualReviewBody => _ar
      ? 'نستكمل مراجعة هذا الحساب. لا يلزم اتخاذ إجراء الآن. يمكنك تحديث الحالة أو تسجيل الخروج.'
      : 'We are completing a review of this account. No further action is needed right now. You can refresh status or sign out.';
  String get manualReviewNext => _ar
      ? 'سنخطرك عبر القنوات المتاحة عندما تتوفر الخطوة التالية.'
      : 'We will use your existing account channels when a next step is available.';

  String get profileTitle => _ar ? 'الملف الشخصي' : 'Profile';
  String get profileReference => _ar ? 'مرجع الملف' : 'Profile reference';
  String get profileStatus => _ar ? 'حالة الملف' : 'Profile status';
  String get statusActive => _ar ? 'نشط' : 'Active';
  String get statusDisputed => _ar ? 'قيد النزاع' : 'Disputed';
  String get statusMerged => _ar ? 'مدمج' : 'Merged';
  String get statusRestricted => _ar ? 'مقيّد' : 'Restricted';
  String get statusArchived => _ar ? 'مؤرشف' : 'Archived';
  String get statusUnknown => _ar ? 'غير متاح' : 'Unavailable';
  String get profileReadOnly => _ar
      ? 'هذا الملف للقراءة فقط حالياً. لا يمكن تعديل البيانات من هذا التطبيق.'
      : 'This profile is read-only right now. Demographics cannot be edited in this app.';
  String get editDemographics =>
      _ar ? 'تعديل البيانات الشخصية' : 'Edit demographics';
  String get editDemographicsTitle =>
      _ar ? 'تعديل البيانات الشخصية' : 'Edit demographics';
  String get lastUpdated => _ar ? 'آخر تحديث' : 'Last updated';
  String get createdAt => _ar ? 'تاريخ الإنشاء' : 'Created';

  String get versionConflictTitle =>
      _ar ? 'تم تحديث الملف من جهة أخرى' : 'This profile was updated elsewhere';
  String get versionConflictBody => _ar
      ? 'لم يُحفظ تغييرك. حدّث لعرض أحدث البيانات، ثم راجعها وعدّلها واحفظ مرة أخرى إذا رغبت.'
      : 'Your change was not saved. Refresh to load the latest values, review them, then edit and save again if you still want to change them.';

  String get validationRequired =>
      _ar ? 'هذا الحقل مطلوب.' : 'This field is required.';
  String get validationFullName =>
      _ar ? 'أدخل اسماً كاملاً صالحاً.' : 'Enter a valid full name.';
  String get validationGender =>
      _ar ? 'اختر قيمة مدعومة.' : 'Choose a supported value.';
  String get validationDate =>
      _ar ? 'أدخل تاريخاً صالحاً بالصيغة المطلوبة.' : 'Enter a valid date.';
  String get validationHeight => _ar
      ? 'أدخل طولاً ضمن النطاق المسموح للتخزين، أو اترك الحقل فارغاً.'
      : 'Enter a height within the allowed storage range, or leave it blank.';
  String get validationWeight => _ar
      ? 'أدخل وزناً ضمن النطاق المسموح للتخزين، أو اترك الحقل فارغاً.'
      : 'Enter a weight within the allowed storage range, or leave it blank.';
  String get validationEnum =>
      _ar ? 'اختر قيمة مدعومة.' : 'Choose a supported value.';
  String get validationNationalId =>
      _ar ? 'أدخل الرقم القومي.' : 'Enter your National ID.';

  String get accountNotPatient => _ar
      ? 'هذا التطبيق لحسابات المرضى فقط. سجّل الخروج للعودة.'
      : 'This app is for patient accounts only. Sign out to return.';
  String get sessionExpired => _ar
      ? 'انتهت صلاحية الجلسة. سجّل الدخول مرة أخرى.'
      : 'Your session ended. Sign in again.';
  String get offlineCannotSubmit => _ar
      ? 'لا يمكن الإرسال بدون اتصال. تحقق من الشبكة ثم أعد المحاولة.'
      : 'You cannot submit while offline. Check your connection and try again.';
  String get profileUnreachable =>
      _ar ? 'تعذر تحميل الملف الشخصي.' : 'The profile could not be loaded.';
  String get submitting => _ar ? 'جارٍ الإرسال…' : 'Submitting…';
  String get loadingProfile =>
      _ar ? 'جارٍ تحميل الملف الشخصي…' : 'Loading profile…';
  String get signedOut => _ar ? 'تم تسجيل الخروج.' : 'You are signed out.';

  String genderLabel(String wire) => switch (wire) {
    'male' => genderMale,
    'female' => genderFemale,
    _ => wire,
  };

  String maritalLabel(String? wire) {
    if (wire == null || wire.isEmpty) {
      return notProvided;
    }
    return switch (wire) {
      'single' => maritalSingle,
      'married' => maritalMarried,
      'divorced' => maritalDivorced,
      'widowed' => maritalWidowed,
      _ => wire,
    };
  }

  String profileStatusLabel(String wire) => switch (wire) {
    'active' => statusActive,
    'disputed' => statusDisputed,
    'merged' => statusMerged,
    'restricted' => statusRestricted,
    'archived' => statusArchived,
    _ => statusUnknown,
  };
}
