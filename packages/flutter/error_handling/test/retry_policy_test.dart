import 'dart:math';

import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:test/test.dart';

void main() {
  const policy = ClientRetryPolicy();

  ApiFailure failure(ApiErrorCode code, {int status = 0}) =>
      ApiFailure(code: code, message: 'x', statusCode: status);

  group('shouldRetry', () {
    test('retries a network failure only when the request is idempotent', () {
      // A blind retry of a non-idempotent write is how a patient gets two
      // appointments or a customer is charged twice (plan.md section 152).
      final f = failure(ApiErrorCode.networkUnavailable);

      expect(
        policy.shouldRetry(failure: f, attempt: 0, isIdempotent: true),
        isTrue,
      );
      expect(
        policy.shouldRetry(failure: f, attempt: 0, isIdempotent: false),
        isFalse,
      );
    });

    test('retries a 5xx only when the request is idempotent', () {
      final f = failure(ApiErrorCode.internalError, status: 500);

      expect(
        policy.shouldRetry(failure: f, attempt: 0, isIdempotent: true),
        isTrue,
      );
      expect(
        policy.shouldRetry(failure: f, attempt: 0, isIdempotent: false),
        isFalse,
      );
    });

    test('always retries throttling and an in-progress duplicate', () {
      // 429 explicitly invites a later retry, and an in-progress duplicate
      // should be polled rather than restarted.
      for (final code in [
        ApiErrorCode.rateLimited,
        ApiErrorCode.idempotencyInProgress,
      ]) {
        expect(
          policy.shouldRetry(
            failure: failure(code, status: 429),
            attempt: 0,
            isIdempotent: false,
          ),
          isTrue,
          reason: '$code should be retryable',
        );
      }
    });

    test('never retries a decision the server already made', () {
      // Retrying an authorization denial or a validation failure adds load and
      // never succeeds.
      for (final code in [
        ApiErrorCode.permissionDenied,
        ApiErrorCode.validationFailed,
        ApiErrorCode.unauthenticated,
        ApiErrorCode.notFound,
        ApiErrorCode.idempotencyKeyReused,
      ]) {
        expect(
          policy.shouldRetry(
            failure: failure(code, status: 403),
            attempt: 0,
            isIdempotent: true,
          ),
          isFalse,
          reason: '$code must not be retried',
        );
      }
    });

    test('stops at the attempt ceiling', () {
      final f = failure(ApiErrorCode.networkUnavailable);

      expect(
        policy.shouldRetry(failure: f, attempt: 2, isIdempotent: true),
        isTrue,
      );
      expect(
        policy.shouldRetry(failure: f, attempt: 3, isIdempotent: true),
        isFalse,
      );
    });
  });

  group('delayFor', () {
    test('is bounded by maxDelay', () {
      const bounded = ClientRetryPolicy(maxDelay: Duration(seconds: 2));

      for (var attempt = 0; attempt < 20; attempt++) {
        expect(
          bounded.delayFor(attempt).inMilliseconds,
          lessThanOrEqualTo(2000),
          reason: 'attempt $attempt exceeded the cap',
        );
      }
    });

    test('applies full jitter rather than a fixed backoff', () {
      // Without jitter every client that failed during an outage retries in the
      // same instant and knocks the recovering dependency straight back over.
      final delays = {
        for (var i = 0; i < 40; i++)
          policy.delayFor(5, random: Random(i)).inMilliseconds,
      };

      expect(delays.length, greaterThan(1), reason: 'delays should vary');
      expect(delays.reduce(min), lessThan(delays.reduce(max)));
    });
  });

  group('ApiFailure classification', () {
    test('groups codes the UI branches on', () {
      expect(failure(ApiErrorCode.tokenExpired).isAuthentication, isTrue);
      expect(failure(ApiErrorCode.validationFailed).isValidation, isTrue);
      expect(failure(ApiErrorCode.versionConflict).isConflict, isTrue);
      expect(failure(ApiErrorCode.notFound).isConflict, isFalse);
    });
  });
}
