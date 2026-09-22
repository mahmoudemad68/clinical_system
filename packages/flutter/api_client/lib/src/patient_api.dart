import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

import 'generated/openapi_contract.dart';

/// Phase 02 patient own-profile adapter.
///
/// Paths come from generated OpenAPI constants. There is no get-by-id method
/// and no public patient lookup.
class PatientApi {
  PatientApi(this._client, {IntentIdempotencyStore? onboardingKeys})
    : _onboardingKeys = onboardingKeys ?? IntentIdempotencyStore();

  final ClinicHttpClient _client;
  final IntentIdempotencyStore _onboardingKeys;

  /// Test seam: current onboarding Idempotency-Key, if any.
  String? get onboardingKeyForTest => _onboardingKeys.keyForTest;

  Future<PatientOnboardingResult> onboard({
    required String nationalId,
    required String fullName,
    required String gender,
    String? dateOfBirth,
    double? heightCm,
    double? weightKg,
    String? maritalStatus,
    String? bloodType,
  }) async {
    final body = <String, dynamic>{
      'national_id': nationalId,
      'full_name': fullName,
      'gender': gender,
    };
    _putOptional(body, 'date_of_birth', dateOfBirth);
    _putOptional(body, 'height_cm', heightCm);
    _putOptional(body, 'weight_kg', weightKg);
    _putOptional(body, 'marital_status', maritalStatus);
    _putOptional(body, 'blood_type', bloodType);

    final fingerprint = onboardingFingerprint(
      nationalId: nationalId,
      fullName: fullName,
      gender: gender,
      dateOfBirth: dateOfBirth,
      heightCm: heightCm,
      weightKg: weightKg,
      maritalStatus: maritalStatus,
      bloodType: bloodType,
    );
    final key = _onboardingKeys.keyFor(fingerprint);

    try {
      final data = await _send(
        method: OpenApiMethods.onboardPatientProfile,
        path: OpenApiPaths.onboardPatientProfile,
        body: body,
        idempotencyKey: key,
      );
      final result = mapOnboardingResult(data);
      _onboardingKeys.retire();
      return result;
    } on ApiFailure catch (failure) {
      final safe = failure.redacting(nationalId);
      if (_isTerminalOnboardingFailure(safe)) {
        _onboardingKeys.retire();
      }
      throw safe;
    }
  }

  Future<PatientProfile> getOwnProfile() async {
    final data = await _send(
      method: OpenApiMethods.getOwnPatientProfile,
      path: OpenApiPaths.getOwnPatientProfile,
    );
    return mapPatientProfile(data);
  }

  Future<PatientProfile> updateDemographics({
    required int version,
    String? fullName,
    String? gender,
    String? dateOfBirth,
    bool clearDateOfBirth = false,
    double? heightCm,
    bool clearHeight = false,
    double? weightKg,
    bool clearWeight = false,
    String? maritalStatus,
    bool clearMaritalStatus = false,
    String? bloodType,
    bool clearBloodType = false,
  }) async {
    final body = <String, dynamic>{'version': version};
    if (fullName != null) {
      body['full_name'] = fullName;
    }
    if (gender != null) {
      body['gender'] = gender;
    }
    if (clearDateOfBirth) {
      body['date_of_birth'] = null;
    } else if (dateOfBirth != null) {
      body['date_of_birth'] = dateOfBirth;
    }
    if (clearHeight) {
      body['height_cm'] = null;
    } else if (heightCm != null) {
      body['height_cm'] = heightCm;
    }
    if (clearWeight) {
      body['weight_kg'] = null;
    } else if (weightKg != null) {
      body['weight_kg'] = weightKg;
    }
    if (clearMaritalStatus) {
      body['marital_status'] = null;
    } else if (maritalStatus != null) {
      body['marital_status'] = maritalStatus;
    }
    if (clearBloodType) {
      body['blood_type'] = null;
    } else if (bloodType != null) {
      body['blood_type'] = bloodType;
    }

    final data = await _send(
      method: OpenApiMethods.updateOwnPatientDemographics,
      path: OpenApiPaths.updateOwnPatientDemographics,
      body: body,
    );
    return mapPatientProfile(data);
  }

  void clearOnboardingIntent() => _onboardingKeys.retire();

  Future<Map<String, dynamic>> _send({
    required String method,
    required String path,
    Map<String, dynamic>? body,
    String? idempotencyKey,
  }) async {
    try {
      final response = await _client.dio.request<Map<String, dynamic>>(
        path,
        data: body,
        options: Options(
          method: method,
          headers: {'Idempotency-Key': ?idempotencyKey},
        ),
      );
      final status = response.statusCode ?? 0;
      if (status < 200 || status >= 300) {
        throw ApiFailure.fromEnvelope(status, response.data);
      }
      final envelope = response.data;
      final data = envelope?['data'];
      if (data is Map<String, dynamic>) {
        return Map<String, dynamic>.from(data);
      }
      throw ApiFailure(
        code: ApiErrorCode.internalError,
        message: 'The service returned an unexpected response.',
        statusCode: status,
      );
    } on DioException catch (e) {
      final failure = e.error;
      if (failure is ApiFailure) {
        throw failure;
      }
      rethrow;
    }
  }
}

bool _isTerminalOnboardingFailure(ApiFailure failure) {
  if (failure.isValidation) {
    return true;
  }
  if (failure.isAuthentication) {
    return true;
  }
  return failure.code == ApiErrorCode.notFound ||
      failure.code == ApiErrorCode.permissionDenied;
}

void _putOptional(Map<String, dynamic> body, String key, Object? value) {
  if (value == null) {
    return;
  }
  if (value is String && value.isEmpty) {
    return;
  }
  body[key] = value;
}

String onboardingFingerprint({
  required String nationalId,
  required String fullName,
  required String gender,
  String? dateOfBirth,
  double? heightCm,
  double? weightKg,
  String? maritalStatus,
  String? bloodType,
}) {
  return [
    nationalId,
    fullName,
    gender,
    dateOfBirth ?? '',
    heightCm?.toString() ?? '',
    weightKg?.toString() ?? '',
    maritalStatus ?? '',
    bloodType ?? '',
  ].join('\u{1e}');
}

PatientOnboardingResult mapOnboardingResult(Map<String, dynamic> data) {
  final status = data['status'] as String?;
  if (status == 'manual_review_required') {
    return const PatientOnboardingResult(
      status: PatientOnboardingStatus.manualReviewRequired,
    );
  }
  if (status != 'profile_ready') {
    throw const ApiFailure(
      code: ApiErrorCode.internalError,
      message: 'The service returned an unexpected response.',
      statusCode: 200,
    );
  }
  final version = data['version'];
  return PatientOnboardingResult(
    status: PatientOnboardingStatus.profileReady,
    patientId: data['patient_id'] as String?,
    version: version is int ? version : int.tryParse('$version'),
  );
}

PatientProfile mapPatientProfile(Map<String, dynamic> data) {
  final patientId = data['patient_id'];
  final fullName = data['full_name'];
  final gender = data['gender'];
  final version = data['version'];
  if (patientId is! String ||
      patientId.isEmpty ||
      fullName is! String ||
      gender is! String) {
    throw const ApiFailure(
      code: ApiErrorCode.internalError,
      message: 'The service returned an unexpected response.',
      statusCode: 200,
    );
  }
  final parsedVersion = version is int ? version : int.tryParse('$version');
  if (parsedVersion == null || parsedVersion < 1) {
    throw const ApiFailure(
      code: ApiErrorCode.internalError,
      message: 'The service returned an unexpected response.',
      statusCode: 200,
    );
  }

  return PatientProfile(
    patientId: patientId,
    fullName: fullName,
    gender: gender,
    dateOfBirth: _optionalString(data['date_of_birth']),
    heightCm: _decimalString(data['height_cm']),
    weightKg: _decimalString(data['weight_kg']),
    maritalStatus: _optionalString(data['marital_status']),
    bloodType: _optionalString(data['blood_type']),
    status: PatientLifecycleStatus.fromWire(data['status'] as String?),
    version: parsedVersion,
    createdAt: _instant(data['created_at']),
    updatedAt: _instant(data['updated_at']),
  );
}

SessionIdentity mapSessionIdentity(Map<String, dynamic> data) {
  final userId = data['user_id'];
  final accountType = data['account_type'];
  if (userId is! String || userId.isEmpty || accountType is! String) {
    throw const ApiFailure(
      code: ApiErrorCode.internalError,
      message: 'The service returned an unexpected response.',
      statusCode: 200,
    );
  }
  return SessionIdentity(
    userId: userId,
    accountType: accountType,
    status: (data['status'] as String?) ?? '',
    language: (data['language'] as String?) ?? 'en',
    assuranceLevel: (data['assurance_level'] as String?) ?? '',
  );
}

String? _optionalString(Object? value) {
  if (value is String && value.isNotEmpty) {
    return value;
  }
  return null;
}

String? _decimalString(Object? value) {
  if (value is String && value.isNotEmpty) {
    return value;
  }
  if (value is num) {
    return value.toString();
  }
  return null;
}

DateTime _instant(Object? value) {
  if (value is String) {
    final parsed = DateTime.tryParse(value);
    if (parsed != null) {
      return parsed.toUtc();
    }
  }
  return DateTime.fromMillisecondsSinceEpoch(0, isUtc: true);
}
