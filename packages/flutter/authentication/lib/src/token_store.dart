import 'dart:convert';
import 'dart:math';

import 'package:clinic_secure_storage/clinic_secure_storage.dart';

abstract interface class CredentialVault {
  Future<void> write({required String key, required String value});

  Future<String?> read(String key);

  Future<void> delete(String key);
}

final class SecureStorageVault implements CredentialVault {
  SecureStorageVault(this._storage);

  final ClinicSecureStorage _storage;

  @override
  Future<void> write({required String key, required String value}) {
    return _storage.write(key: key, value: value);
  }

  @override
  Future<String?> read(String key) => _storage.read(key);

  @override
  Future<void> delete(String key) => _storage.delete(key);
}

/// Access and refresh material in the platform key store.
///
/// Keys are opaque. Values never go to Drift, analytics, or crash reports.
/// Access and refresh are written as one versioned envelope so a crash cannot
/// leave a split pair.
class TokenStore {
  TokenStore(this._storage);

  static const envelopeKey = 'auth.envelope.v1';
  static const accessKey = 'auth.access';
  static const refreshKey = 'auth.refresh';

  final CredentialVault _storage;

  Future<void> write({required String access, required String refresh}) async {
    if (access.isEmpty || refresh.isEmpty) {
      throw ArgumentError(
        'token envelope requires a complete access and refresh pair',
      );
    }
    final envelope = jsonEncode({
      'version': 1,
      'access': access,
      'refresh': refresh,
    });
    await _storage.write(key: envelopeKey, value: envelope);
    await _storage.delete(accessKey);
    await _storage.delete(refreshKey);
  }

  Future<Map<String, String>?> _readEnvelope() async {
    final raw = await _storage.read(envelopeKey);
    if (raw != null && raw.isNotEmpty) {
      try {
        final decoded = jsonDecode(raw);
        if (decoded is Map<String, dynamic> &&
            decoded['access'] is String &&
            decoded['refresh'] is String &&
            (decoded['access'] as String).isNotEmpty &&
            (decoded['refresh'] as String).isNotEmpty) {
          return {
            'access': decoded['access'] as String,
            'refresh': decoded['refresh'] as String,
          };
        }
      } on FormatException {
        return null;
      }
      return null;
    }

    final access = await _storage.read(accessKey);
    final refresh = await _storage.read(refreshKey);
    if (access != null && refresh != null) {
      return {'access': access, 'refresh': refresh};
    }
    return null;
  }

  Future<String?> readAccess() async => (await _readEnvelope())?['access'];

  Future<String?> readRefresh() async => (await _readEnvelope())?['refresh'];

  Future<void> clear() async {
    await _storage.delete(envelopeKey);
    await _storage.delete(accessKey);
    await _storage.delete(refreshKey);
    final leftover = await _readEnvelope();
    if (leftover != null) {
      throw StateError('credential vault was not emptied');
    }
  }
}

String newRefreshIdempotencyKey() {
  final random = Random.secure();
  final bytes = List<int>.generate(16, (_) => random.nextInt(256));
  return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
}
