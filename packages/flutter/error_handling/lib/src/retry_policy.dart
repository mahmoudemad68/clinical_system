import 'dart:math';

import 'api_failure.dart';

/// Decides whether a failed request may be retried automatically.
///
/// The rule that matters (plan.md section 152): not every failure is retried.
/// A POS sale or a booking is replayed only when it carries an idempotency key,
/// because a blind retry of a non-idempotent write is how a patient gets two
/// appointments or a customer gets charged twice.
class ClientRetryPolicy {
  const ClientRetryPolicy({
    this.maxAttempts = 3,
    this.baseDelay = const Duration(milliseconds: 300),
    this.maxDelay = const Duration(seconds: 10),
  });

  final int maxAttempts;
  final Duration baseDelay;
  final Duration maxDelay;

  /// May this failure be retried?
  ///
  /// [isIdempotent] must be true only when the request is a safe method or
  /// carries an `Idempotency-Key`. Defaulting it to true would make every
  /// mutation silently retryable, so callers must state it.
  bool shouldRetry({
    required ApiFailure failure,
    required int attempt,
    required bool isIdempotent,
  }) {
    if (attempt >= maxAttempts) {
      return false;
    }

    return switch (failure.code) {
      // No response: the request may never have reached the server. Safe to
      // repeat only when repeating is harmless.
      ApiErrorCode.networkUnavailable => isIdempotent,

      // Server or dependency failure. Same reasoning.
      ApiErrorCode.internalError || ApiErrorCode.dependencyUnavailable =>
        isIdempotent && failure.statusCode >= 500,

      // Throttling explicitly invites a later retry.
      ApiErrorCode.rateLimited => true,

      // A duplicate is already being processed; poll rather than start another.
      ApiErrorCode.idempotencyInProgress => true,

      // Everything else is a decision the server already made. Retrying an
      // authorization denial or a validation failure adds load and never
      // succeeds.
      _ => false,
    };
  }

  /// Delay before the next attempt: exponential, capped, with full jitter.
  ///
  /// Jitter matters most when a dependency recovers: without it every client
  /// that failed during the outage retries in the same instant and knocks it
  /// straight back over.
  Duration delayFor(int attempt, {Random? random}) {
    final rng = random ?? Random();
    final exponential = baseDelay.inMilliseconds * pow(2, attempt).toInt();
    final ceiling = min(exponential, maxDelay.inMilliseconds);

    return Duration(milliseconds: rng.nextInt(max(1, ceiling + 1)));
  }
}
