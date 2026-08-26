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
class TokenStore {
  TokenStore(this._storage);

  static const accessKey = 'auth.access';
  static const refreshKey = 'auth.refresh';

  final CredentialVault _storage;

  Future<void> write({required String access, required String refresh}) async {
    await _storage.write(key: accessKey, value: access);
    await _storage.write(key: refreshKey, value: refresh);
  }

  Future<String?> readAccess() => _storage.read(accessKey);

  Future<String?> readRefresh() => _storage.read(refreshKey);

  Future<void> clear() async {
    await _storage.delete(accessKey);
    await _storage.delete(refreshKey);
  }
}
