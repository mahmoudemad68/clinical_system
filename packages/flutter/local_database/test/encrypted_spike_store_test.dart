import 'dart:io';
import 'dart:typed_data';

import 'package:clinic_local_database/clinic_local_database.dart';
import 'package:flutter_test/flutter_test.dart';

bool containsContiguous(List<int> haystack, List<int> needle) {
  if (needle.isEmpty || haystack.length < needle.length) {
    return false;
  }
  for (var i = 0; i <= haystack.length - needle.length; i++) {
    var found = true;
    for (var j = 0; j < needle.length; j++) {
      if (haystack[i + j] != needle[j]) {
        found = false;
        break;
      }
    }
    if (found) {
      return true;
    }
  }
  return false;
}

void main() {
  late Directory scratch;

  setUp(() {
    scratch = Directory.systemTemp.createTempSync('clinic-enc-dart-');
  });

  tearDown(() {
    if (scratch.existsSync()) {
      scratch.deleteSync(recursive: true);
    }
  });

  test('refuses to persist when the keystore is unavailable', () {
    expect(
      () => EncryptedSpikeStore.open(
        directory: scratch,
        namespace: 'patient.encrypted.v1',
        key: newDatabaseKey(),
        createIfMissing: true,
        keystoreAvailable: false,
      ),
      throwsA(isA<KeystoreUnavailable>()),
    );
  });

  test('hides a known canary from the database file bytes', () {
    final control = File('${scratch.path}/plaintext-control.txt');
    control.writeAsStringSync(syntheticSpikeCanary);
    expect(
      containsContiguous(control.readAsBytesSync(), syntheticSpikeCanary.codeUnits),
      isTrue,
    );

    final key = newDatabaseKey();
    final store = EncryptedSpikeStore.open(
      directory: scratch,
      namespace: 'spike',
      key: key,
      createIfMissing: true,
      keystoreAvailable: true,
    );

    store.put('canary', syntheticSpikeCanary);
    expect(store.get('canary'), syntheticSpikeCanary);

    final bytes = store.databaseFile.readAsBytesSync();
    expect(
      containsContiguous(bytes, syntheticSpikeCanary.codeUnits),
      isFalse,
    );

    store.close();
  });

  test('preserves existing rows across key rotation', () {
    var key = newDatabaseKey();
    var store = EncryptedSpikeStore.open(
      directory: scratch,
      namespace: 'spike',
      key: key,
      createIfMissing: true,
      keystoreAvailable: true,
    );

    store.put('draft', syntheticSpikeCanary);
    final next = newDatabaseKey();
    store.rotateKey(next);
    store.close();

    store = EncryptedSpikeStore.open(
      directory: scratch,
      namespace: 'spike',
      key: next,
      createIfMissing: false,
      keystoreAvailable: true,
    );

    expect(store.get('draft'), syntheticSpikeCanary);
    expect(
      containsContiguous(
        store.databaseFile.readAsBytesSync(),
        syntheticSpikeCanary.codeUnits,
      ),
      isFalse,
    );
    store.close();
  });

  test('fails closed on a wrong key rather than returning empty rows', () {
    final key = newDatabaseKey();
    final store = EncryptedSpikeStore.open(
      directory: scratch,
      namespace: 'spike',
      key: key,
      createIfMissing: true,
      keystoreAvailable: true,
    );
    store.put('draft', syntheticSpikeCanary);
    store.close();

    expect(
      () => EncryptedSpikeStore.open(
        directory: scratch,
        namespace: 'spike',
        key: Uint8List.fromList(List<int>.filled(32, 7)),
        createIfMissing: true,
        keystoreAvailable: true,
      ),
      throwsA(isA<WrongDatabaseKey>()),
    );
  });

  test('workspace lockfile does not adopt EOL sqlcipher_flutter_libs', () {
    final lock = File.fromUri(
      Directory.current.uri.resolve('../../../pubspec.lock'),
    );
    expect(lock.existsSync(), isTrue);
    expect(lock.readAsStringSync(), isNot(contains('sqlcipher_flutter_libs')));
  });
}
