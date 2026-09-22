import 'package:meta/meta.dart';

/// Server-owned identity projection from GET /api/v1/me.
///
/// Account type is never supplied by the client. Tokens are not present.
@immutable
class IdentitySnapshot {
  const IdentitySnapshot({
    required this.userId,
    required this.accountType,
    required this.status,
    required this.language,
    required this.assuranceLevel,
  });

  final String userId;
  final String accountType;
  final String status;
  final String language;
  final String assuranceLevel;

  bool get isPatient => accountType == 'patient';

  factory IdentitySnapshot.fromWire(Map<String, dynamic> data) {
    return IdentitySnapshot(
      userId: (data['user_id'] as String?) ?? '',
      accountType: (data['account_type'] as String?) ?? '',
      status: (data['status'] as String?) ?? '',
      language: (data['language'] as String?) ?? '',
      assuranceLevel: (data['assurance_level'] as String?) ?? '',
    );
  }

  @override
  String toString() =>
      'IdentitySnapshot(accountType: $accountType, status: $status)';

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is IdentitySnapshot &&
          other.userId == userId &&
          other.accountType == accountType &&
          other.status == status &&
          other.language == language &&
          other.assuranceLevel == assuranceLevel;

  @override
  int get hashCode =>
      Object.hash(userId, accountType, status, language, assuranceLevel);
}
