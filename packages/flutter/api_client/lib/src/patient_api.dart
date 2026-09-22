import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

import 'generated/openapi_contract.dart';

/// Lookup of the authenticated caller's own patient profile.
sealed class OwnProfileLookup {
  const OwnProfileLookup();
}

final class OwnProfileFound extends OwnProfileLookup {
  const OwnProfileFound(this.profile);

  final PatientProfile profile;
}

/// No attached own profile. Onboarding is required. Not another actor's 404.
final class OwnProfileAbsent extends OwnProfileLookup {
  const OwnProfileAbsent();
}

/// Thin mapping over Phase 02 patient own-profile endpoints.
///
/// There is no GET-by-patient-id method. UI code must not construct these URLs.
class PatientApi {
  const PatientApi(this._client);

  final ClinicHttpClient _client;

  /// POST /api/v1/patients/onboarding
  Future<PatientOnboardingResult> onboard({
    required PatientOnboardingRequest request,
    required String idempotencyKey,
  }) async {
    final data = await _send(
      'POST',
      OpenApiPaths.onboardPatientProfile,
      body: request.toWire(),
      idempotencyKey: idempotencyKey,
      ok: const {200, 201},
    );
    try {
      return PatientOnboardingResult.fromWire(data);
    } on FormatException {
      throw const ApiFailure(
        code: ApiErrorCode.internalError,
        message: 'The service returned an unexpected response.',
        statusCode: 200,
      );
    }
  }

  /// GET /api/v1/patients/me/profile
  ///
  /// 200 → found. 404/NOT_FOUND → absent (onboarding or non-enumerating deny).
  Future<OwnProfileLookup> getOwnProfile() async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        OpenApiPaths.getOwnPatientProfile,
      );
      final status = response.statusCode ?? 0;
      if (status == 200) {
        final data = _requireData(response.data, status);
        return OwnProfileFound(PatientProfile.fromWire(data));
      }
      if (_isNotFound(status, response.data)) {
        return const OwnProfileAbsent();
      }
      throw _failureFrom(status, response.data);
    } on DioException catch (e) {
      throw _fromTransport(e);
    }
  }

  /// PATCH /api/v1/patients/me/demographics
  Future<PatientProfile> updateDemographics(
    PatientDemographicsPatch patch,
  ) async {
    final data = await _send(
      'PATCH',
      OpenApiPaths.updateOwnPatientDemographics,
      body: patch.toWire(),
      ok: const {200},
    );
    return PatientProfile.fromWire(data);
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? idempotencyKey,
    required Set<int> ok,
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
      if (!ok.contains(status)) {
        throw _failureFrom(status, response.data);
      }
      return _requireData(response.data, status);
    } on DioException catch (e) {
      throw _fromTransport(e);
    }
  }

  Map<String, dynamic> _requireData(
    Map<String, dynamic>? envelope,
    int status,
  ) {
    final data = envelope?['data'];
    if (data is Map<String, dynamic>) {
      return Map<String, dynamic>.from(data);
    }
    throw ApiFailure(
      code: ApiErrorCode.internalError,
      message: 'The service returned an unexpected response.',
      statusCode: status,
    );
  }

  bool _isNotFound(int status, Object? body) {
    if (status == 404) {
      return true;
    }
    if (body is Map<String, dynamic>) {
      final errors = body['errors'];
      if (errors is List && errors.isNotEmpty) {
        final first = errors.first;
        if (first is Map<String, dynamic> &&
            ApiErrorCode.fromWire(first['code'] as String?) ==
                ApiErrorCode.notFound) {
          return true;
        }
      }
    }
    return false;
  }

  ApiFailure _failureFrom(int statusCode, Object? body) {
    if (body is Map<String, dynamic>) {
      final errors = body['errors'];
      final requestId = body['request_id'] as String?;
      if (errors is List && errors.isNotEmpty) {
        final first = errors.first;
        if (first is Map<String, dynamic>) {
          return ApiFailure(
            code: ApiErrorCode.fromWire(first['code'] as String?),
            message: _safeMessage(first['message'] as String?),
            statusCode: statusCode,
            field: first['field'] as String?,
            requestId: requestId,
          );
        }
      }
    }
    return ApiFailure(
      code: statusCode == 409
          ? ApiErrorCode.versionConflict
          : ApiErrorCode.internalError,
      message: 'The request failed.',
      statusCode: statusCode,
    );
  }

  String _safeMessage(String? message) {
    if (message == null || message.trim().isEmpty) {
      return 'The request failed.';
    }
    return message;
  }

  Never _fromTransport(DioException e) {
    final failure = e.error;
    if (failure is ApiFailure) {
      throw failure;
    }
    final response = e.response;
    if (response != null) {
      throw _failureFrom(response.statusCode ?? 0, response.data);
    }
    throw const ApiFailure(
      code: ApiErrorCode.networkUnavailable,
      message: 'The service could not be reached.',
      statusCode: 0,
    );
  }
}
