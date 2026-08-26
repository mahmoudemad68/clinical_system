import 'package:clinic_secure_storage/clinic_secure_storage.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Android options refuse backup migration of wrapped keys', () {
    final mapped = clinicAndroidOptions.toMap();
    expect(mapped['migrateWithBackup'], 'false');
    expect(mapped['resetOnError'], 'true');
  });

  test('iOS options keep the database key off iCloud Keychain and backups', () {
    expect(clinicIosOptions.synchronizable, isFalse);
    expect(
      clinicIosOptions.accessibility,
      KeychainAccessibility.first_unlock_this_device,
    );
  });

  test('macOS options match the iOS device-only contract', () {
    expect(clinicMacOsOptions.synchronizable, isFalse);
    expect(
      clinicMacOsOptions.accessibility,
      KeychainAccessibility.first_unlock_this_device,
    );
  });
}
