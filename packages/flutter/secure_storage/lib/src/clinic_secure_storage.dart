import 'backup_exclusion.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Narrow wrapper so application code never constructs a `FlutterSecureStorage`
/// with backup-friendly defaults.
class ClinicSecureStorage {
  ClinicSecureStorage({FlutterSecureStorage? storage})
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: clinicAndroidOptions,
            iOptions: clinicIosOptions,
            mOptions: clinicMacOsOptions,
            lOptions: clinicLinuxOptions,
            wOptions: clinicWindowsOptions,
          );

  final FlutterSecureStorage _storage;

  Future<void> write({required String key, required String value}) {
    return _storage.write(key: key, value: value);
  }

  Future<String?> read(String key) => _storage.read(key: key);

  Future<void> delete(String key) => _storage.delete(key: key);
}
