import 'package:meta/meta.dart';

/// Stable machine codes the server may return.
///
/// Mirrors the `ErrorCode` enum in `packages/contracts/openapi/openapi.yaml`.
/// Clients branch on the code, never on the message: the message is localized
/// and may be reworded at any time without that being a breaking change.
enum ApiErrorCode {
  malformedRequest,
  unsupportedMediaType,
  requestTooLarge,
  unauthenticated,
  tokenExpired,
  permissionDenied,
  notFound,
  stateConflict,
  versionConflict,
  idempotencyKeyReused,
  idempotencyInProgress,
  validationFailed,
  cursorInvalid,
  unsupportedSchemaVersion,
  rateLimited,
  dependencyUnavailable,
  internalError,

  /// No response reached us at all. Not a server code; synthesised locally so
  /// callers do not have to distinguish "no response" from "error response".
  networkUnavailable;

  static ApiErrorCode fromWire(String? value) => switch (value) {
    'MALFORMED_REQUEST' => ApiErrorCode.malformedRequest,
    'UNSUPPORTED_MEDIA_TYPE' => ApiErrorCode.unsupportedMediaType,
    'REQUEST_TOO_LARGE' => ApiErrorCode.requestTooLarge,
    'UNAUTHENTICATED' => ApiErrorCode.unauthenticated,
    'TOKEN_EXPIRED' => ApiErrorCode.tokenExpired,
    'PERMISSION_DENIED' => ApiErrorCode.permissionDenied,
    'NOT_FOUND' => ApiErrorCode.notFound,
    'STATE_CONFLICT' => ApiErrorCode.stateConflict,
    'VERSION_CONFLICT' => ApiErrorCode.versionConflict,
    'IDEMPOTENCY_KEY_REUSED' => ApiErrorCode.idempotencyKeyReused,
    'IDEMPOTENCY_IN_PROGRESS' => ApiErrorCode.idempotencyInProgress,
    'VALIDATION_FAILED' => ApiErrorCode.validationFailed,
    'CURSOR_INVALID' => ApiErrorCode.cursorInvalid,
    'UNSUPPORTED_SCHEMA_VERSION' => ApiErrorCode.unsupportedSchemaVersion,
    'RATE_LIMITED' => ApiErrorCode.rateLimited,
    'DEPENDENCY_UNAVAILABLE' => ApiErrorCode.dependencyUnavailable,
    _ => ApiErrorCode.internalError,
  };
}

/// One normalized failure.
///
/// [requestId] is the correlation identifier. It is the only handle support
/// needs to find the full trace, so it is surfaced to the user rather than
/// hidden in a log the user cannot reach.
@immutable
class ApiFailure implements Exception {
  const ApiFailure({
    required this.code,
    required this.message,
    required this.statusCode,
    this.field,
    this.requestId,
  });

  final ApiErrorCode code;

  /// Safe, localized message from the server. Never a stack trace or internal
  /// detail: the server does not send those.
  final String message;

  /// 0 when no response arrived.
  final int statusCode;

  /// Field path for a validation failure, for binding to a form control.
  final String? field;

  final String? requestId;

  bool get isAuthentication =>
      code == ApiErrorCode.unauthenticated || code == ApiErrorCode.tokenExpired;

  bool get isValidation => code == ApiErrorCode.validationFailed;

  bool get isConflict =>
      code == ApiErrorCode.stateConflict ||
      code == ApiErrorCode.versionConflict ||
      code == ApiErrorCode.idempotencyKeyReused ||
      code == ApiErrorCode.idempotencyInProgress;

  @override
  String toString() => 'ApiFailure($code, status: $statusCode)';
}
