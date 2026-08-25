/// HTTP transport for the clinic clients.
///
/// One wrapper owns correlation IDs, idempotency keys, and error
/// normalization. Feature code never constructs its own Dio instance: a second
/// transport path is how one request ships without the interceptors that make
/// failures safe and traceable.
library;

export 'src/clinic_http_client.dart';
export 'src/correlation_interceptor.dart';
export 'src/failure_interceptor.dart';
