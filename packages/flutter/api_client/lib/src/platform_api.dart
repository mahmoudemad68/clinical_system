import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

/// Platform health and version endpoints.
class PlatformApi {
  const PlatformApi(this._client);

  final ClinicHttpClient _client;

  /// GET /api/v1/health
  ///
  /// Throws [ApiFailure] on any failure; the transport normalizes every error
  /// shape before it reaches here.
  Future<PlatformHealth> health() async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/health',
      );
      final body = response.data;

      if (body == null) {
        throw const ApiFailure(
          code: ApiErrorCode.internalError,
          message: 'The service returned an empty response.',
          statusCode: 0,
        );
      }

      return _mapHealth(body);
    } on DioException catch (e) {
      // FailureInterceptor put an ApiFailure here. Rethrow it so callers never
      // see a transport type.
      final failure = e.error;
      if (failure is ApiFailure) {
        throw failure;
      }
      rethrow;
    }
  }

  /// Map the wire envelope into the client model.
  ///
  /// Defensive about shape rather than trusting the server blindly: a client
  /// that crashes on an unexpected payload is worse than one that degrades,
  /// especially on a desktop app a clinic cannot update quickly.
  PlatformHealth _mapHealth(Map<String, dynamic> body) {
    final data = body['data'];

    if (data is! Map<String, dynamic>) {
      throw const ApiFailure(
        code: ApiErrorCode.internalError,
        message: 'The service returned an unexpected response.',
        statusCode: 200,
      );
    }

    final components = data['components'];
    final componentMap = components is Map<String, dynamic>
        ? components
        : const <String, dynamic>{};

    return PlatformHealth(
      status: ComponentStatus.fromWire(data['status'] as String?),
      message: (data['message'] as String?) ?? '',
      core: ComponentStatus.fromWire(componentMap['core'] as String?),
      realtime: ComponentStatus.fromWire(componentMap['realtime'] as String?),
      ai: ComponentStatus.fromWire(componentMap['ai'] as String?),
      version: (data['version'] as String?) ?? 'unknown',
      // The server always sends UTC. Parsing to UTC keeps the invariant
      // explicit rather than relying on the device time zone.
      serverTime:
          DateTime.tryParse((data['server_time'] as String?) ?? '')?.toUtc() ??
          DateTime.now().toUtc(),
    );
  }
}
