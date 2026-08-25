import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

/// Attaches a correlation identifier to every outbound request.
///
/// The server accepts a client-supplied `X-Request-Id` only when it is a
/// well-formed UUIDv7, so this generates v7 rather than v4. A v4 would be
/// silently discarded and replaced server-side, which still works but loses the
/// ability to correlate a client-side log line with a server trace.
class CorrelationInterceptor extends Interceptor {
  CorrelationInterceptor({Uuid? uuid}) : _uuid = uuid ?? const Uuid();

  final Uuid _uuid;

  static const String headerName = 'X-Request-Id';

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers.putIfAbsent(headerName, () => _uuid.v7());
    handler.next(options);
  }
}
