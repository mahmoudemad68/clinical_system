import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'token_store.dart';

/// Thin mapping over Phase 01 auth endpoints. Tokens are written to [TokenStore]
/// and never returned to UI code as a convenience dump.
class AuthApi {
  AuthApi(this._client, this._tokens);

  final ClinicHttpClient _client;
  final TokenStore _tokens;
  String? _refreshIdempotencyKey;

  /// Retained refresh Idempotency-Key. Null when no attempt is in flight.
  @visibleForTesting
  String? get refreshIdempotencyKeyForTest => _refreshIdempotencyKey;

  Future<OtpChallenge> register({
    required String name,
    required String phone,
    required String nationalId,
    required String password,
    required String language,
    required String idempotencyKey,
  }) async {
    final data = await _post('/api/v1/auth/registrations', {
      'name': name,
      'phone': phone,
      'national_id': nationalId,
      'password': password,
      'language': language,
    }, idempotencyKey: idempotencyKey);
    return OtpChallenge.fromWire(data);
  }

  Future<AuthOutcome> verifyOtp({
    required String challengeId,
    required String code,
    required String platform,
    required String deviceLabel,
    required String idempotencyKey,
  }) async {
    final data = await _post('/api/v1/auth/otp-verifications', {
      'challenge_id': challengeId,
      'code': code,
      'client_class': 'patient_mobile',
      'platform': platform,
      'device_label': deviceLabel,
    }, idempotencyKey: idempotencyKey);
    return _persistIfDevice(data);
  }

  Future<AuthOutcome> login({
    required String phone,
    required String password,
    required String platform,
    required String deviceLabel,
  }) async {
    final data = await _post('/api/v1/auth/login', {
      'phone': phone,
      'password': password,
      'client_class': 'patient_mobile',
      'platform': platform,
      'device_label': deviceLabel,
    });
    return _persistIfDevice(data);
  }

  /// Rotates the device session.
  ///
  /// Returns true only after a complete access+refresh pair is durably
  /// persisted. Incomplete 2xx, malformed bodies, and vault write failures
  /// keep the retained Idempotency-Key and the previous envelope.
  Future<bool> refresh() async {
    final current = await _tokens.readRefresh();
    if (current == null || current.isEmpty) {
      return false;
    }
    _refreshIdempotencyKey ??= newRefreshIdempotencyKey();
    try {
      final data = await _post('/api/v1/auth/token/refresh', {
        'refresh_token': current,
      }, idempotencyKey: _refreshIdempotencyKey);
      final pair = completeDeviceTokenPair(data);
      if (pair == null) {
        return false;
      }
      await _tokens.write(access: pair.access, refresh: pair.refresh);
      _client.setAuthToken(pair.access);
      _refreshIdempotencyKey = null;
      return true;
    } on ApiFailure catch (failure) {
      if (isAuthoritativeCredentialRejection(failure)) {
        await _tokens.clear();
        _client.setAuthToken(null);
        _refreshIdempotencyKey = null;
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  Future<void> logout() async {
    await _post('/api/v1/auth/logout', {});
    await _tokens.clear();
    _client.setAuthToken(null);
    _refreshIdempotencyKey = null;
  }

  Future<Map<String, dynamic>> me() async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/me',
      );
      final body = response.data;
      final data = body?['data'];
      if (data is Map<String, dynamic>) {
        return Map<String, dynamic>.from(data);
      }
      throw ApiFailure(
        code: ApiErrorCode.internalError,
        message: 'The service returned an unexpected response.',
        statusCode: response.statusCode ?? 0,
      );
    } on DioException catch (e) {
      final failure = e.error;
      if (failure is ApiFailure) {
        throw failure;
      }
      rethrow;
    }
  }

  Future<AuthOutcome> _persistIfDevice(Map<String, dynamic> data) async {
    final outcome = AuthOutcome.fromWire(data);
    final pair = completeDeviceTokenPair(data);
    if (pair != null) {
      await _tokens.write(access: pair.access, refresh: pair.refresh);
      _client.setAuthToken(pair.access);
    }
    return outcome.withoutSecrets();
  }

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body, {
    String? idempotencyKey,
  }) async {
    try {
      final response = await _client.dio.post<Map<String, dynamic>>(
        path,
        data: body,
        options: Options(headers: {'Idempotency-Key': ?idempotencyKey}),
      );
      final status = response.statusCode ?? 0;
      if (status < 200 || status >= 300) {
        throw apiFailureFromResponse(status, response.data);
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

class OtpChallenge {
  const OtpChallenge({required this.challengeId, required this.status});

  final String challengeId;
  final String status;

  factory OtpChallenge.fromWire(Map<String, dynamic> data) {
    return OtpChallenge(
      challengeId: (data['challenge_id'] as String?) ?? '',
      status: (data['status'] as String?) ?? 'otp_required',
    );
  }
}

class AuthOutcome {
  const AuthOutcome({
    required this.status,
    required this.mfaRequired,
    this.challengeId,
    this.sessionKind,
    this.accessToken,
    this.refreshToken,
  });

  final String status;
  final bool mfaRequired;
  final String? challengeId;
  final String? sessionKind;
  final String? accessToken;
  final String? refreshToken;

  factory AuthOutcome.fromWire(Map<String, dynamic> data) {
    return AuthOutcome(
      status: (data['status'] as String?) ?? '',
      mfaRequired: data['mfa_required'] == true,
      challengeId: data['challenge_id'] as String?,
      sessionKind: data['session_kind'] as String?,
      accessToken: data['access_token'] as String?,
      refreshToken: data['refresh_token'] as String?,
    );
  }

  AuthOutcome withoutSecrets() {
    return AuthOutcome(
      status: status,
      mfaRequired: mfaRequired,
      challengeId: challengeId,
      sessionKind: sessionKind,
    );
  }
}

class DeviceTokenPair {
  const DeviceTokenPair({required this.access, required this.refresh});

  final String access;
  final String refresh;
}

/// Complete non-empty access and refresh tokens, or null.
DeviceTokenPair? completeDeviceTokenPair(Map<String, dynamic> data) {
  final access = data['access_token'];
  final refresh = data['refresh_token'];
  if (access is! String || access.isEmpty) {
    return null;
  }
  if (refresh is! String || refresh.isEmpty) {
    return null;
  }
  return DeviceTokenPair(access: access, refresh: refresh);
}

bool isAuthoritativeCredentialRejection(ApiFailure failure) {
  if (failure.statusCode == 401) {
    return true;
  }
  return failure.statusCode >= 400 &&
      failure.statusCode < 500 &&
      failure.isAuthentication;
}

ApiFailure apiFailureFromResponse(int statusCode, Object? body) {
  if (body is Map<String, dynamic>) {
    final errors = body['errors'];
    final requestId = body['request_id'] as String?;
    if (errors is List && errors.isNotEmpty) {
      final first = errors.first;
      if (first is Map<String, dynamic>) {
        return ApiFailure(
          code: ApiErrorCode.fromWire(first['code'] as String?),
          message: (first['message'] as String?) ?? 'The request failed.',
          statusCode: statusCode,
          field: first['field'] as String?,
          requestId: requestId,
        );
      }
    }
  }
  return ApiFailure(
    code: ApiErrorCode.internalError,
    message: 'The request failed.',
    statusCode: statusCode,
  );
}
