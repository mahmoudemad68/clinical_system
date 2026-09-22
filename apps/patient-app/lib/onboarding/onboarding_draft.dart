import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';

/// In-memory onboarding draft. National ID lives here only while the form
/// is active and is never written to disk.
class OnboardingDraft {
  OnboardingDraft();

  String nationalId = '';
  String fullName = '';
  String? gender;
  DateTime? dateOfBirth;
  String heightCm = '';
  String weightKg = '';
  String? maritalStatus;
  String? bloodType;
  int step = 0;

  void clearNationalId() {
    nationalId = '';
  }

  void reset() {
    nationalId = '';
    fullName = '';
    gender = null;
    dateOfBirth = null;
    heightCm = '';
    weightKg = '';
    maritalStatus = null;
    bloodType = null;
    step = 0;
  }

  String fingerprint() {
    return [
      nationalId,
      fullName,
      gender ?? '',
      dateOfBirth == null ? '' : isoDate(dateOfBirth!),
      heightCm,
      weightKg,
      maritalStatus ?? '',
      bloodType ?? '',
    ].join('\u{1e}');
  }
}

class FieldErrors {
  const FieldErrors(this.messages);

  final Map<String, String> messages;

  String? operator [](String field) => messages[field];

  bool get isEmpty => messages.isEmpty;
}

FieldErrors validateIdentityStep(OnboardingDraft draft, ClinicStrings strings) {
  final errors = <String, String>{};
  if (draft.nationalId.trim().isEmpty ||
      draft.nationalId.trim().length >
          PatientDemographicLimits.nationalIdMaxLength) {
    errors['national_id'] = strings.validationNationalId;
  }
  if (!_validName(draft.fullName)) {
    errors['full_name'] = strings.validationFullName;
  }
  return FieldErrors(errors);
}

FieldErrors validateDemographicsStep(
  OnboardingDraft draft,
  ClinicStrings strings,
) {
  final errors = <String, String>{};
  if (draft.gender == null ||
      !PatientDemographicLimits.genders.contains(draft.gender)) {
    errors['gender'] = strings.validationGender;
  }
  if (draft.dateOfBirth != null) {
    final iso = isoDate(draft.dateOfBirth!);
    if (iso.compareTo(PatientDemographicLimits.dateOfBirthMin) < 0) {
      errors['date_of_birth'] = strings.validationDate;
    }
    final today = DateTime.now().toUtc();
    final todayDate = DateTime.utc(today.year, today.month, today.day);
    final dob = DateTime.utc(
      draft.dateOfBirth!.year,
      draft.dateOfBirth!.month,
      draft.dateOfBirth!.day,
    );
    if (dob.isAfter(todayDate)) {
      errors['date_of_birth'] = strings.validationDate;
    }
  }
  if (draft.maritalStatus != null &&
      !PatientDemographicLimits.maritalStatuses.contains(draft.maritalStatus)) {
    errors['marital_status'] = strings.validationEnum;
  }
  return FieldErrors(errors);
}

FieldErrors validateMeasurementsStep(
  OnboardingDraft draft,
  ClinicStrings strings,
) {
  final errors = <String, String>{};
  final heightError = parseBoundedNumber(
    draft.heightCm,
    min: PatientDemographicLimits.heightMinCm,
    max: PatientDemographicLimits.heightMaxCm,
  );
  if (heightError != null) {
    errors['height_cm'] = strings.validationHeight;
  }
  final weightError = parseBoundedNumber(
    draft.weightKg,
    min: PatientDemographicLimits.weightMinKg,
    max: PatientDemographicLimits.weightMaxKg,
  );
  if (weightError != null) {
    errors['weight_kg'] = strings.validationWeight;
  }
  if (draft.bloodType != null &&
      !PatientDemographicLimits.bloodTypes.contains(draft.bloodType)) {
    errors['blood_type'] = strings.validationEnum;
  }
  return FieldErrors(errors);
}

FieldErrors validateOnboarding(OnboardingDraft draft, ClinicStrings strings) {
  return FieldErrors({
    ...validateIdentityStep(draft, strings).messages,
    ...validateDemographicsStep(draft, strings).messages,
    ...validateMeasurementsStep(draft, strings).messages,
  });
}

/// Returns a message key marker when invalid; null when empty or in range.
/// Out-of-range values are rejected, never clamped.
String? parseBoundedNumber(
  String raw, {
  required double min,
  required double max,
}) {
  final trimmed = raw.trim();
  if (trimmed.isEmpty) {
    return null;
  }
  final value = double.tryParse(trimmed);
  if (value == null || value <= 0 || value < min || value > max) {
    return 'out_of_range';
  }
  return null;
}

double? parseOptionalNumber(String raw) {
  final trimmed = raw.trim();
  if (trimmed.isEmpty) {
    return null;
  }
  return double.tryParse(trimmed);
}

String isoDate(DateTime date) {
  final y = date.year.toString().padLeft(4, '0');
  final m = date.month.toString().padLeft(2, '0');
  final d = date.day.toString().padLeft(2, '0');
  return '$y-$m-$d';
}

bool _validName(String raw) {
  final name = raw.trim();
  return name.isNotEmpty &&
      name.length <= PatientDemographicLimits.fullNameMaxLength;
}

FieldErrors validateDemographicPatch({
  required String fullName,
  required String? gender,
  DateTime? dateOfBirth,
  required String heightCm,
  required String weightKg,
  String? maritalStatus,
  String? bloodType,
  required ClinicStrings strings,
}) {
  final draft = OnboardingDraft()
    ..nationalId = 'placeholder-not-validated'
    ..fullName = fullName
    ..gender = gender
    ..dateOfBirth = dateOfBirth
    ..heightCm = heightCm
    ..weightKg = weightKg
    ..maritalStatus = maritalStatus
    ..bloodType = bloodType;
  return FieldErrors({
    ...validateDemographicsStep(draft, strings).messages,
    ...validateMeasurementsStep(draft, strings).messages,
    if (!_validName(fullName)) 'full_name': strings.validationFullName,
  });
}
