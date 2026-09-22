import 'package:clinic_common_models/clinic_common_models.dart';

/// Client-side usability checks. The server remains authoritative.
class DemographicValidation {
  const DemographicValidation._();

  static String? fullName(String value) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) {
      return 'required';
    }
    if (trimmed.length > PatientDemographicLimits.fullNameMaxLength) {
      return 'full_name';
    }
    return null;
  }

  static String? nationalId(String value) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) {
      return 'required';
    }
    if (trimmed.length > PatientDemographicLimits.nationalIdMaxLength) {
      return 'national_id';
    }
    return null;
  }

  static String? gender(String? value) {
    if (value == null || value.isEmpty) {
      return 'required';
    }
    if (PatientGender.tryParse(value) == null) {
      return 'enum';
    }
    return null;
  }

  static String? dateOfBirth(String? value, {required DateTime nowUtc}) {
    if (value == null || value.trim().isEmpty) {
      return null;
    }
    final raw = value.trim();
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(raw);
    if (match == null) {
      return 'date';
    }
    final parsed = DateTime.tryParse(raw);
    if (parsed == null) {
      return 'date';
    }
    final min = DateTime.parse(PatientDemographicLimits.dateOfBirthMin);
    final today = DateTime.utc(nowUtc.year, nowUtc.month, nowUtc.day);
    final day = DateTime.utc(parsed.year, parsed.month, parsed.day);
    if (day.isBefore(min) || day.isAfter(today)) {
      return 'date';
    }
    return null;
  }

  static String? heightCm(String? value) {
    return _optionalNumber(
      value,
      min: PatientDemographicLimits.heightCmMin,
      max: PatientDemographicLimits.heightCmMax,
    );
  }

  static String? weightKg(String? value) {
    return _optionalNumber(
      value,
      min: PatientDemographicLimits.weightKgMin,
      max: PatientDemographicLimits.weightKgMax,
    );
  }

  static String? maritalStatus(String? value) {
    if (value == null || value.isEmpty) {
      return null;
    }
    if (PatientMaritalStatus.tryParse(value) == null) {
      return 'enum';
    }
    return null;
  }

  static String? bloodType(String? value) {
    if (value == null || value.isEmpty) {
      return null;
    }
    if (PatientBloodType.tryParse(value) == null) {
      return 'enum';
    }
    return null;
  }

  static String? _optionalNumber(
    String? value, {
    required double min,
    required double max,
  }) {
    if (value == null || value.trim().isEmpty) {
      return null;
    }
    final parsed = double.tryParse(value.trim());
    if (parsed == null) {
      return 'number';
    }
    if (parsed <= 0 || parsed < min || parsed > max) {
      return 'bounds';
    }
    return null;
  }

  static double? parseOptionalNumber(String? value) {
    if (value == null || value.trim().isEmpty) {
      return null;
    }
    return double.tryParse(value.trim());
  }
}

String localizeValidation(String? code, ClinicValidationCopy copy) {
  return switch (code) {
    null => '',
    'required' => copy.required,
    'national_id' => copy.nationalId,
    'full_name' => copy.fullName,
    'date' => copy.date,
    'bounds' || 'number' => copy.bounds,
    'enum' => copy.enumeration,
    _ => copy.required,
  };
}

class ClinicValidationCopy {
  const ClinicValidationCopy({
    required this.required,
    required this.nationalId,
    required this.fullName,
    required this.date,
    required this.bounds,
    required this.enumeration,
  });

  final String required;
  final String nationalId;
  final String fullName;
  final String date;
  final String bounds;
  final String enumeration;
}
