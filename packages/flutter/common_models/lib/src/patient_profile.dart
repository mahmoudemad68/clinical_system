import 'package:meta/meta.dart';

/// ENGINEERING_DEFAULT closed vocabulary. Not a clinical or legal sex class.
enum PatientGender {
  male,
  female;

  static const List<PatientGender> supported = [male, female];

  String get wire => name;

  static PatientGender? tryParse(String? value) => switch (value) {
    'male' => PatientGender.male,
    'female' => PatientGender.female,
    _ => null,
  };
}

/// Self-reported ABO/Rh label. Not laboratory verified.
enum PatientBloodType {
  aPositive('A+'),
  aNegative('A-'),
  bPositive('B+'),
  bNegative('B-'),
  abPositive('AB+'),
  abNegative('AB-'),
  oPositive('O+'),
  oNegative('O-');

  const PatientBloodType(this.wire);

  final String wire;

  static const List<PatientBloodType> supported = PatientBloodType.values;

  static PatientBloodType? tryParse(String? value) {
    for (final item in values) {
      if (item.wire == value) {
        return item;
      }
    }
    return null;
  }
}

/// ENGINEERING_DEFAULT closed vocabulary. Not a civil-status legal taxonomy.
enum PatientMaritalStatus {
  single,
  married,
  divorced,
  widowed;

  static const List<PatientMaritalStatus> supported =
      PatientMaritalStatus.values;

  String get wire => name;

  static PatientMaritalStatus? tryParse(String? value) => switch (value) {
    'single' => PatientMaritalStatus.single,
    'married' => PatientMaritalStatus.married,
    'divorced' => PatientMaritalStatus.divorced,
    'widowed' => PatientMaritalStatus.widowed,
    _ => null,
  };
}

/// Server-owned profile status. The client never assigns or transitions it.
enum PatientProfileStatus {
  active,
  disputed,
  merged,
  restricted,
  archived,
  unknown;

  String get wire => name;

  /// Only [active] is the Phase 02 editable demographic surface.
  bool get isNormallyEditable => this == PatientProfileStatus.active;

  static PatientProfileStatus fromWire(String? value) => switch (value) {
    'active' => PatientProfileStatus.active,
    'disputed' => PatientProfileStatus.disputed,
    'merged' => PatientProfileStatus.merged,
    'restricted' => PatientProfileStatus.restricted,
    'archived' => PatientProfileStatus.archived,
    _ => PatientProfileStatus.unknown,
  };
}

/// Compact onboarding outcome. GET own-profile is the canonical projection.
enum PatientOnboardingStatus {
  profileReady,
  manualReviewRequired;

  String get wire => switch (this) {
    PatientOnboardingStatus.profileReady => 'profile_ready',
    PatientOnboardingStatus.manualReviewRequired => 'manual_review_required',
  };

  static PatientOnboardingStatus? tryParse(String? value) => switch (value) {
    'profile_ready' => PatientOnboardingStatus.profileReady,
    'manual_review_required' => PatientOnboardingStatus.manualReviewRequired,
    _ => null,
  };
}

/// Storage/product bounds from the Patients contract. Not clinical advice.
abstract final class PatientDemographicLimits {
  static const double heightCmMin = 30;
  static const double heightCmMax = 300;
  static const double weightKgMin = 1;
  static const double weightKgMax = 700;
  static const int fullNameMaxLength = 200;
  static const int nationalIdMaxLength = 32;
  static const String dateOfBirthMin = '1850-01-01';
}

/// Own demographic projection. National ID is intentionally absent.
@immutable
class PatientProfile {
  const PatientProfile({
    required this.patientId,
    required this.fullName,
    required this.gender,
    required this.dateOfBirth,
    required this.heightCm,
    required this.weightKg,
    required this.maritalStatus,
    required this.bloodType,
    required this.status,
    required this.version,
    required this.createdAt,
    required this.updatedAt,
  });

  final String patientId;
  final String fullName;
  final String gender;
  final String? dateOfBirth;
  final String? heightCm;
  final String? weightKg;
  final String? maritalStatus;
  final String? bloodType;
  final PatientProfileStatus status;
  final int version;
  final DateTime createdAt;
  final DateTime updatedAt;

  bool get canEditDemographics => status.isNormallyEditable;

  factory PatientProfile.fromWire(Map<String, dynamic> data) {
    return PatientProfile(
      patientId: (data['patient_id'] as String?) ?? '',
      fullName: (data['full_name'] as String?) ?? '',
      gender: (data['gender'] as String?) ?? '',
      dateOfBirth: _nullableString(data['date_of_birth']),
      heightCm: _nullableString(data['height_cm']),
      weightKg: _nullableString(data['weight_kg']),
      maritalStatus: _nullableString(data['marital_status']),
      bloodType: _nullableString(data['blood_type']),
      status: PatientProfileStatus.fromWire(data['status'] as String?),
      version: _asInt(data['version']) ?? 0,
      createdAt:
          DateTime.tryParse((data['created_at'] as String?) ?? '')?.toUtc() ??
          DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
      updatedAt:
          DateTime.tryParse((data['updated_at'] as String?) ?? '')?.toUtc() ??
          DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
    );
  }

  @override
  String toString() =>
      'PatientProfile(status: ${status.wire}, version: $version)';

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PatientProfile &&
          other.patientId == patientId &&
          other.fullName == fullName &&
          other.gender == gender &&
          other.dateOfBirth == dateOfBirth &&
          other.heightCm == heightCm &&
          other.weightKg == weightKg &&
          other.maritalStatus == maritalStatus &&
          other.bloodType == bloodType &&
          other.status == status &&
          other.version == version &&
          other.createdAt == createdAt &&
          other.updatedAt == updatedAt;

  @override
  int get hashCode => Object.hash(
    patientId,
    fullName,
    gender,
    dateOfBirth,
    heightCm,
    weightKg,
    maritalStatus,
    bloodType,
    status,
    version,
    createdAt,
    updatedAt,
  );
}

@immutable
class PatientOnboardingResult {
  const PatientOnboardingResult({
    required this.status,
    this.patientId,
    this.version,
  });

  final PatientOnboardingStatus status;
  final String? patientId;
  final int? version;

  bool get isProfileReady => status == PatientOnboardingStatus.profileReady;

  bool get isManualReviewRequired =>
      status == PatientOnboardingStatus.manualReviewRequired;

  factory PatientOnboardingResult.fromWire(Map<String, dynamic> data) {
    final parsed = PatientOnboardingStatus.tryParse(data['status'] as String?);
    if (parsed == null) {
      throw const FormatException('unknown onboarding status');
    }
    if (parsed == PatientOnboardingStatus.manualReviewRequired) {
      return const PatientOnboardingResult(
        status: PatientOnboardingStatus.manualReviewRequired,
      );
    }
    return PatientOnboardingResult(
      status: parsed,
      patientId: _nullableString(data['patient_id']),
      version: _asInt(data['version']),
    );
  }

  @override
  String toString() => 'PatientOnboardingResult(${status.wire})';
}

/// Allowlisted onboarding body. [nationalId] is write-only and must not be
/// copied into logs, routes, or persisted stores.
@immutable
class PatientOnboardingRequest {
  const PatientOnboardingRequest({
    required this.nationalId,
    required this.fullName,
    required this.gender,
    this.dateOfBirth,
    this.heightCm,
    this.weightKg,
    this.maritalStatus,
    this.bloodType,
  });

  final String nationalId;
  final String fullName;
  final String gender;
  final String? dateOfBirth;
  final double? heightCm;
  final double? weightKg;
  final String? maritalStatus;
  final String? bloodType;

  /// Wire JSON. Callers must not print this map.
  Map<String, dynamic> toWire() {
    return <String, dynamic>{
      'national_id': nationalId,
      'full_name': fullName,
      'gender': gender,
      if (dateOfBirth != null && dateOfBirth!.isNotEmpty)
        'date_of_birth': dateOfBirth,
      if (heightCm != null) 'height_cm': heightCm,
      if (weightKg != null) 'weight_kg': weightKg,
      if (maritalStatus != null && maritalStatus!.isNotEmpty)
        'marital_status': maritalStatus,
      if (bloodType != null && bloodType!.isNotEmpty) 'blood_type': bloodType,
    };
  }

  /// In-memory fingerprint for idempotency. Does not retain National ID.
  int get intentFingerprint => Object.hash(
    nationalId,
    fullName,
    gender,
    dateOfBirth,
    heightCm,
    weightKg,
    maritalStatus,
    bloodType,
  );

  @override
  String toString() =>
      'PatientOnboardingRequest(fullName length: ${fullName.length})';
}

@immutable
class PatientDemographicsPatch {
  const PatientDemographicsPatch({
    required this.version,
    this.fullName,
    this.gender,
    this.dateOfBirth,
    this.clearDateOfBirth = false,
    this.heightCm,
    this.clearHeightCm = false,
    this.weightKg,
    this.clearWeightKg = false,
    this.maritalStatus,
    this.clearMaritalStatus = false,
    this.bloodType,
    this.clearBloodType = false,
  });

  final int version;
  final String? fullName;
  final String? gender;
  final String? dateOfBirth;
  final bool clearDateOfBirth;
  final double? heightCm;
  final bool clearHeightCm;
  final double? weightKg;
  final bool clearWeightKg;
  final String? maritalStatus;
  final bool clearMaritalStatus;
  final String? bloodType;
  final bool clearBloodType;

  Map<String, dynamic> toWire() {
    return <String, dynamic>{
      'version': version,
      if (fullName != null) 'full_name': fullName,
      if (gender != null) 'gender': gender,
      if (clearDateOfBirth)
        'date_of_birth': null
      else if (dateOfBirth != null)
        'date_of_birth': dateOfBirth,
      if (clearHeightCm)
        'height_cm': null
      else if (heightCm != null)
        'height_cm': heightCm,
      if (clearWeightKg)
        'weight_kg': null
      else if (weightKg != null)
        'weight_kg': weightKg,
      if (clearMaritalStatus)
        'marital_status': null
      else if (maritalStatus != null)
        'marital_status': maritalStatus,
      if (clearBloodType)
        'blood_type': null
      else if (bloodType != null)
        'blood_type': bloodType,
    };
  }

  @override
  String toString() => 'PatientDemographicsPatch(version: $version)';
}

String? _nullableString(Object? value) {
  if (value == null) {
    return null;
  }
  if (value is String) {
    return value;
  }
  if (value is num) {
    return value.toString();
  }
  return value.toString();
}

int? _asInt(Object? value) {
  if (value is int) {
    return value;
  }
  if (value is num) {
    return value.toInt();
  }
  if (value is String) {
    return int.tryParse(value);
  }
  return null;
}
