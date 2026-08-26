import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Android: AES-GCM with RSA-OAEP wrap, and never copy the key into a backup.
const clinicAndroidOptions = AndroidOptions(
  resetOnError: true,
  migrateWithBackup: false,
);

/// iOS: device-only Keychain item. `this_device` excludes iCloud Keychain and
/// encrypted device backups; `synchronizable: false` is the same intent twice.
const clinicIosOptions = IOSOptions(
  accessibility: KeychainAccessibility.first_unlock_this_device,
  synchronizable: false,
);

/// macOS uses the same Keychain accessibility contract as iOS.
const clinicMacOsOptions = MacOsOptions(
  accessibility: KeychainAccessibility.first_unlock_this_device,
  synchronizable: false,
);

const clinicLinuxOptions = LinuxOptions();

const clinicWindowsOptions = WindowsOptions();
