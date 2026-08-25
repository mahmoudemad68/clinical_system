/// Normalized failures and retry classification for the clinic clients.
///
/// Implements the client half of the API failure-handling contract
/// (plan.md section 152): 401 goes to auth handling, 422 to form validation,
/// 409 to business conflict, 429 to rate limiting, and 5xx is retried only when
/// the operation is safe or carries an idempotency key.
library;

export 'src/api_failure.dart';
export 'src/retry_policy.dart';
