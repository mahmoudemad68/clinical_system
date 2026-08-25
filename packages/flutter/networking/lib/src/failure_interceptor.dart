import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:dio/dio.dart';

/// Converts every transport error into an [ApiFailure].
///
/// Nothing above this layer should ever see a [DioException]: leaking the
/// transport type into feature code makes the UI depend on the HTTP library and
/// tempts callers to read raw response bodies rather than the normalized shape.
class FailureInterceptor extends Interceptor {
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    handler.reject(
      DioException(
        requestOptions: err.requestOptions,
        response: err.response,
        type: err.type,
        error: _toFailure(err),
      ),
    );
  }

  ApiFailure _toFailure(DioException err) {
    final response = err.response;

    if (response == null) {
      // No response at all: DNS, TLS, timeout, or an offline device.
      return const ApiFailure(
        code: ApiErrorCode.networkUnavailable,
        message: 'The service could not be reached.',
        statusCode: 0,
      );
    }

    final body = response.data;

    if (body is Map<String, dynamic>) {
      final errors = body['errors'];
      final requestId = body['request_id'] as String?;

      if (errors is List && errors.isNotEmpty) {
        final first = errors.first;

        if (first is Map<String, dynamic>) {
          return ApiFailure(
            code: ApiErrorCode.fromWire(first['code'] as String?),
            // The server's message is already safe and localized.
            message: (first['message'] as String?) ?? 'The request failed.',
            statusCode: response.statusCode ?? 0,
            field: first['field'] as String?,
            requestId: requestId,
          );
        }
      }
    }

    return ApiFailure(
      code: ApiErrorCode.internalError,
      message: 'The request failed.',
      statusCode: response.statusCode ?? 0,
    );
  }
}
