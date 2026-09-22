import 'package:flutter/widgets.dart';

/// User-visible strings, resolved by locale.
///
/// Hand-written rather than generated from ARB. The interface is the same
/// shape a generated class would expose, so moving to codegen later does not
/// change any call site.
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
  String get submitAction => _ar ? 'إرسال' : 'Submit';
  String get saveAction => _ar ? 'حفظ' : 'Save';
  String get refreshAction => _ar ? 'تحديث' : 'Refresh';
  String get retryAction => _ar ? 'إعادة المحاولة' : 'Try again';
  String get editAction => _ar ? 'تعديل' : 'Edit';
  String get cancelAction => _ar ? 'إلغاء' : 'Cancel';
  String get loading => _ar ? 'جارٍ التحميل…' : 'Loading…';
  String get offlineSubmit => _ar
      ? 'تعذر الإرسال بدون اتصال. يبقى النموذج في الذاكرة فقط.'
      : 'Cannot submit while offline. Your form stays in memory only.';

  String get resolvingProfile =>
      _ar ? 'جارٍ التحقق من ملفك…' : 'Checking your profile…';

  String get onboardingTitle =>
      _ar ? 'إكمال الملف الشخصي' : 'Complete your profile';
  String get onboardingStepIdentity => _ar ? 'الهوية' : 'Identity';
  String get onboardingStepDemographics => _ar ? 'البيانات' : 'Demographics';
  String get onboardingStepMeasurements => _ar ? 'القياسات' : 'Measurements';
  String get onboardingStepReview => _ar ? 'المراجعة' : 'Review';
  String get onboardingIdentityHint => _ar
      ? 'أدخل اسمك الكامل والرقم القومي. لا يُحفظ الرقم القومي على هذا الجهاز.'
      : 'Enter your full name and National ID. National ID is not stored on this device.';
  String get onboardingDemographicsHint => _ar
      ? 'هذه بيانات تعريفية اختيارية باستثناء النوع.'
      : 'These are demographic details. Gender is required.';
  String get onboardingMeasurementsHint => _ar
      ? 'القياسات وفصيلة الدم اختيارية ومُبلغ عنها ذاتيًا.'
      : 'Measurements and blood type are optional and self-reported.';
  String get onboardingReviewHint => _ar
      ? 'راجع البيانات الآمنة أدناه ثم أرسل. لا تُعرض بيانات الهوية الحساسة هنا.'
      : 'Review the safe details below, then submit. Sensitive identity numbers are not shown here.';
  String get stepProgress =>
      _ar ? 'الخطوة {current} من {total}' : 'Step {current} of {total}';

  String get fieldFullName => _ar ? 'الاسم الكامل' : 'Full name';
  String get fieldGender => _ar ? 'النوع' : 'Gender';
  String get fieldDateOfBirth => _ar ? 'تاريخ الميلاد' : 'Date of birth';
  String get fieldMaritalStatus => _ar ? 'الحالة الاجتماعية' : 'Marital status';
  String get fieldHeight => _ar ? 'الطول (سم)' : 'Height (cm)';
  String get fieldWeight => _ar ? 'الوزن (كجم)' : 'Weight (kg)';
  String get fieldBloodType => _ar ? 'فصيلة الدم' : 'Blood type';
  String get selfReported => _ar ? 'مُبلغ عنه ذاتيًا' : 'Self-reported';
  String get selfReportedHint => _ar
      ? 'قيمة تُدخلها أنت. ليست نتيجة مختبر وليست نصيحة طبية.'
      : 'A value you enter yourself. Not a laboratory result and not medical advice.';
  String get bloodTypeSelfReportedHint => _ar
      ? 'فصيلة الدم مُبلغ عنها ذاتيًا وليست مؤكدة مخبريًا.'
      : 'Blood type is self-reported and is not laboratory verified.';
  String get boundsHint => _ar
      ? 'حدود الإدخال هي قواعد تخزين للمنتج وليست توصيات طبية.'
      : 'Input limits are product storage rules, not medical recommendations.';
  String get optionalField => _ar ? 'اختياري' : 'Optional';
  String get notProvided => _ar ? 'غير مذكور' : 'Not provided';

  String get genderMale => _ar ? 'ذكر' : 'Male';
  String get genderFemale => _ar ? 'أنثى' : 'Female';
  String get maritalSingle => _ar ? 'أعزب' : 'Single';
  String get maritalMarried => _ar ? 'متزوج' : 'Married';
  String get maritalDivorced => _ar ? 'مطلق' : 'Divorced';
  String get maritalWidowed => _ar ? 'أرمل' : 'Widowed';

  String get validationRequired =>
      _ar ? 'هذا الحقل مطلوب.' : 'This field is required.';
  String get validationNationalId =>
      _ar ? 'تحقق من بيانات الهوية.' : 'Check the identity details.';
  String get validationFullName =>
      _ar ? 'أدخل اسمًا كاملًا صالحًا.' : 'Enter a valid full name.';
  String get validationGender =>
      _ar ? 'اختر نوعًا مدعومًا.' : 'Choose a supported gender.';
  String get validationDate => _ar
      ? 'استخدم تاريخًا بصيغة يوم-شهر-سنة صالحًا.'
      : 'Use a valid date in YYYY-MM-DD format.';
  String get validationHeight => _ar
      ? 'أدخل طولًا ضمن حدود التخزين المسموحة، أو اترك الحقل فارغًا.'
      : 'Enter a height within the allowed storage range, or leave it blank.';
  String get validationWeight => _ar
      ? 'أدخل وزنًا ضمن حدود التخزين المسموحة، أو اترك الحقل فارغًا.'
      : 'Enter a weight within the allowed storage range, or leave it blank.';
  String get validationEnum =>
      _ar ? 'اختر قيمة مدعومة.' : 'Choose a supported value.';

  String get reviewPendingTitle =>
      _ar ? 'المراجعة قيد المعالجة' : 'Review in progress';
  String get reviewPendingBody => _ar
      ? 'نراجع معلومات حسابك. لا يلزم إجراء آخر الآن. حدّث الحالة لاحقًا أو سجّل الخروج.'
      : 'We are reviewing your account information. No further action is needed right now. Refresh later or sign out.';
  String get reviewPendingSafe => _ar
      ? 'تظهر الرسالة نفسها لأي حالة مراجعة. لا تُعرض تفاصيل المطابقة.'
      : 'This same message is shown for any review state. Match details are never shown.';

  String get profileTitle => _ar ? 'الملف الشخصي' : 'Profile';
  String get profileStatus => _ar ? 'حالة الملف' : 'Profile status';
  String get profileVersion => _ar ? 'إصدار الملف' : 'Profile version';
  String get profileUpdated => _ar ? 'آخر تحديث' : 'Last updated';
  String get profileReadOnly => _ar
      ? 'هذا الملف للقراءة فقط حتى تتغير حالته على الخادم.'
      : 'This profile is read-only until the server changes its status.';
  String get profilePatientIdLabel => _ar ? 'مرجع الملف' : 'Profile reference';
  String get editDemographics => _ar ? 'تعديل البيانات' : 'Edit demographics';
  String get platformStatus => _ar ? 'حالة المنصة' : 'Platform status';

  String get statusActive => _ar ? 'نشط' : 'Active';
  String get statusDisputed => _ar ? 'قيد النزاع' : 'Disputed';
  String get statusMerged => _ar ? 'مدمج' : 'Merged';
  String get statusRestricted => _ar ? 'مقيّد' : 'Restricted';
  String get statusArchived => _ar ? 'مؤرشف' : 'Archived';
  String get statusUnknown => _ar ? 'غير معروفة' : 'Unknown';

  String get versionConflictTitle =>
      _ar ? 'تم تحديث الملف' : 'Profile was updated';
  String get versionConflictBody => _ar
      ? 'حُفظت نسخة أحدث على الخادم. حدّث لعرض القيم الحالية ثم عدّل واحفظ مرة أخرى إن رغبت.'
      : 'A newer version was saved on the server. Refresh to review the latest values, then edit and save again if you still want to change them.';
  String get versionConflictRefresh =>
      _ar ? 'عرض القيم الحالية' : 'Show latest values';

  String get unsupportedAccountTitle =>
      _ar ? 'هذا التطبيق للمرضى' : 'This app is for patients';
  String get unsupportedAccountBody => _ar
      ? 'نوع الحساب الحالي لا يستخدم ملف المريض في هذا التطبيق. سجّل الخروج للعودة.'
      : 'The current account type cannot use the patient profile in this app. Sign out to return.';

  String get sessionExpired => _ar
      ? 'انتهت الجلسة. سجّل الدخول مرة أخرى.'
      : 'Your session ended. Sign in again.';
  String get profileLoadFailed =>
      _ar ? 'تعذر تحميل الملف الشخصي.' : 'The profile could not be loaded.';
  String get onboardingFailed =>
      _ar ? 'تعذر إكمال إنشاء الملف.' : 'Profile setup could not be completed.';
  String get demographicsSaveFailed =>
      _ar ? 'تعذر حفظ البيانات.' : 'Demographics could not be saved.';
  String get signingOut => _ar ? 'جارٍ تسجيل الخروج…' : 'Signing out…';

  String formatStep(int current, int total) {
    return stepProgress
        .replaceAll('{current}', '$current')
        .replaceAll('{total}', '$total');
  }

  String genderLabel(String wire) {
    return switch (wire) {
      'male' => genderMale,
      'female' => genderFemale,
      _ => wire,
    };
  }

  String maritalLabel(String? wire) {
    return switch (wire) {
      'single' => maritalSingle,
      'married' => maritalMarried,
      'divorced' => maritalDivorced,
      'widowed' => maritalWidowed,
      null || '' => notProvided,
      _ => wire,
    };
  }

  String profileStatusLabel(String wire) {
    return switch (wire) {
      'active' => statusActive,
      'disputed' => statusDisputed,
      'merged' => statusMerged,
      'restricted' => statusRestricted,
      'archived' => statusArchived,
      _ => statusUnknown,
    };
  }
}
