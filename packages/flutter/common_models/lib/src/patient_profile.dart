import 'package:meta/meta.dart';

/// ENGINEERING_DEFAULT storage bounds. Not a clinical protocol.
abstract final class PatientDemographicLimits {
  static const double heightMinCm = 30;
  static const double heightMaxCm = 300;
  static const double weightMinKg = 1;
  static const double weightMaxKg = 700;
  static const String dateOfBirthMin = '1850-01-01';
  static const int fullNameMaxLength = 200;
  static const int nationalIdMaxLength = 32;

  static const List<String> genders = ['male', 'female'];
  static const List<String> maritalStatuses = [
    'single',
    'married',
    'divorced',
    'widowed',
  ];
  static const List<String> bloodTypes = [
    'A+',
    'A-',
    'B+',
    'B-',
    'AB+',
    'AB-',
    'O+',
    'O-',
  ];
}

/// Server-owned profile lifecycle. The client never assigns this.
enum PatientLifecycleStatus {
  active,
  disputed,
  merged,
  restricted,
  archived,
  unknown;

  static PatientLifecycleStatus fromWire(String? value) => switch (value) {
    'active' => PatientLifecycleStatus.active,
    'disputed' => PatientLifecycleStatus.disputed,
    'merged' => PatientLifecycleStatus.merged,
    'restricted' => PatientLifecycleStatus.restricted,
    'archived' => PatientLifecycleStatus.archived,
    _ => PatientLifecycleStatus.unknown,
  };

  String get wire => switch (this) {
    PatientLifecycleStatus.active => 'active',
    PatientLifecycleStatus.disputed => 'disputed',
    PatientLifecycleStatus.merged => 'merged',
    PatientLifecycleStatus.restricted => 'restricted',
    PatientLifecycleStatus.archived => 'archived',
    PatientLifecycleStatus.unknown => 'unknown',
  };

  /// Phase 02 editable surface is only the normal active profile.
  bool get allowsDemographicEdit => this == PatientLifecycleStatus.active;
}

enum PatientOnboardingStatus { profileReady, manualReviewRequired }

/// Compact onboarding result. Own-profile GET is the canonical projection.
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
}

/// Own demographic projection. National ID is never a member.
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
  final PatientLifecycleStatus status;
  final int version;
  final DateTime createdAt;
  final DateTime updatedAt;

  bool get isEditable => status.allowsDemographicEdit;
}

/// Server-owned /me identity. Not a patient profile.
@immutable
class SessionIdentity {
  const SessionIdentity({
    required this.userId,
    required this.accountType,
    required this.status,
    required this.language,
    required this.assuranceLevel,
  });

  final String userId;
  final String accountType;
  final String status;
  final String language;
  final String assuranceLevel;

  bool get isPatientAccount => accountType == 'patient';
}
