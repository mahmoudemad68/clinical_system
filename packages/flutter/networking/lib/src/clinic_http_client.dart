import 'package:dio/dio.dart';

import 'correlation_interceptor.dart';
import 'failure_interceptor.dart';

/// The single configured HTTP client for a clinic app.
///
/// Constructed once at app startup and injected. Bounds are set here rather
/// than per call so no request can accidentally be unbounded: a request with no
/// timeout on a mobile network hangs until the OS kills it, which the user
/// experiences as the app being broken.
class ClinicHttpClient {
  ClinicHttpClient({
    required String baseUrl,
    Duration connectTimeout = const Duration(seconds: 10),
    Duration receiveTimeout = const Duration(seconds: 20),
    List<Interceptor> extraInterceptors = const [],
    Dio? dio,
  }) : _dio = dio ?? Dio() {
    _dio.options = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: connectTimeout,
      receiveTimeout: receiveTimeout,
      sendTimeout: connectTimeout,
      headers: const {'Accept': 'application/json'},
      responseType: ResponseType.json,
      // Do not throw on non-2xx here; FailureInterceptor normalizes them into
      // ApiFailure so every caller handles one shape.
      validateStatus: (status) => status != null && status < 500,
      // Bound the response body. An unbounded download on a mobile device is
      // both a memory risk and a data-cost problem for the patient.
      maxRedirects: 3,
    );

    _dio.interceptors.addAll([
      CorrelationInterceptor(),
      ...extraInterceptors,
      FailureInterceptor(),
    ]);
  }

  final Dio _dio;

  Dio get dio => _dio;

  /// Set or clear the language sent as `Accept-Language`.
  ///
  /// The server negotiates and returns localized messages, so the client does
  /// not maintain a second copy of the error catalogue.
  void setLocale(String locale) {
    _dio.options.headers['Accept-Language'] = locale;
  }

  /// Attach the device/session token.
  ///
  /// Held in memory only. Persisting it belongs to `clinic_secure_storage`,
  /// which uses the platform key store; a token in a plain preferences file is
  /// readable by anything with filesystem access.
  void setAuthToken(String? token) {
    if (token == null) {
      _dio.options.headers.remove('Authorization');
    } else {
      _dio.options.headers['Authorization'] = 'Bearer $token';
    }
  }

  void close() => _dio.close(force: true);
}
