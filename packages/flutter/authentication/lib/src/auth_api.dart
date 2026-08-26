import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

import 'token_store.dart';

/// Thin mapping over Phase 01 auth endpoints. Tokens are written to [TokenStore]
/// and never returned to UI code as a convenience dump.
class AuthApi {
  AuthApi(this._client, this._tokens);

  final ClinicHttpClient _client;
  final TokenStore _tokens;

  Future<OtpChallenge> register({
    required String name,
    required String phone,
    required String nationalId,
    required String password,
    required String language,
    required String idempotencyKey,
  }) async {
    final data = await _post(
      '/api/v1/auth/registrations',
      {
        'name': name,
        'phone': phone,
        'national_id': nationalId,
        'password': password,
        'language': language,
      },
      idempotencyKey: idempotencyKey,
    );
    return OtpChallenge.fromWire(data);
  }

  Future<AuthOutcome> verifyOtp({
    required String challengeId,
    required String code,
    required String platform,
    required String deviceLabel,
    required String idempotencyKey,
  }) async {
    final data = await _post(
      '/api/v1/auth/otp-verifications',
      {
        'challenge_id': challengeId,
        'code': code,
        'client_class': 'patient_mobile',
        'platform': platform,
        'device_label': deviceLabel,
      },
      idempotencyKey: idempotencyKey,
    );
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

  Future<bool> refresh() async {
    final current = await _tokens.readRefresh();
    if (current == null || current.isEmpty) {
      return false;
    }
    try {
      final data = await _post('/api/v1/auth/token/refresh', {
        'refresh_token': current,
      }, idempotencyKey: 'refresh-${DateTime.now().toUtc().millisecondsSinceEpoch}');
      await _persistIfDevice(data);
      return true;
    } on ApiFailure {
      await _tokens.clear();
      _client.setAuthToken(null);
      return false;
    }
  }

  Future<void> logout() async {
    try {
      await _post('/api/v1/auth/logout', {});
    } on ApiFailure {
      // Local clear still happens; server revoke is best-effort after 401.
    }
    await _tokens.clear();
    _client.setAuthToken(null);
  }

  Future<Map<String, dynamic>> me() async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>('/api/v1/me');
      final body = response.data;
      final data = body?['data'];
      if (data is Map<String, dynamic>) {
        return Map<String, dynamic>.from(data);
      }
      throw const ApiFailure(
        code: ApiErrorCode.internalError,
        message: 'The service returned an unexpected response.',
        statusCode: 200,
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
    if (outcome.accessToken != null && outcome.refreshToken != null) {
      await _tokens.write(
        access: outcome.accessToken!,
        refresh: outcome.refreshToken!,
      );
      _client.setAuthToken(outcome.accessToken);
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
        options: Options(
          headers: {
            if (idempotencyKey != null) 'Idempotency-Key': idempotencyKey,
          },
        ),
      );
      final envelope = response.data;
      final data = envelope?['data'];
      if (data is Map<String, dynamic>) {
        return Map<String, dynamic>.from(data);
      }
      throw const ApiFailure(
        code: ApiErrorCode.internalError,
        message: 'The service returned an unexpected response.',
        statusCode: 200,
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
