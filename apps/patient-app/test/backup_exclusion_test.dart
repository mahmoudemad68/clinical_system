import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Android application backup is disabled', () {
    final manifest = File('android/app/src/main/AndroidManifest.xml');
    expect(manifest.existsSync(), isTrue);
    expect(manifest.readAsStringSync(), contains('android:allowBackup="false"'));
  });
}
