import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:sqlite3/sqlite3.dart';

/// Known plaintext written through the encrypted store during the G-06-01 spike.
///
/// Deliberately not clinical-looking. A passing spike proves this string is
/// absent from the database file bytes after a write.
const syntheticSpikeCanary = 'SYNTHETIC_SPIKE_CANARY_v1';

class KeystoreUnavailable implements Exception {}

class KeyMaterialMissing implements Exception {}

class WrongDatabaseKey implements Exception {}

Uint8List newDatabaseKey() {
  final random = Random.secure();
  return Uint8List.fromList(List<int>.generate(32, (_) => random.nextInt(256)));
}

String _hexKey(Uint8List key) {
  return key.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
}

void _applyCipher(Database db, Uint8List key) {
  db.execute("PRAGMA cipher = 'sqlcipher'");
  db.execute('PRAGMA legacy = 4');
  db.execute("PRAGMA key = \"x'${_hexKey(key)}'\"");
}

/// Encrypted SQLite file for the G-06-01 compatibility spike.
///
/// Intent-named get/put only. Drift comes later, on this same connection style.
class EncryptedSpikeStore {
  EncryptedSpikeStore._(this._db, this.databaseFile);

  final Database _db;
  final File databaseFile;

  static EncryptedSpikeStore open({
    required Directory directory,
    required String namespace,
    required Uint8List key,
    required bool createIfMissing,
    required bool keystoreAvailable,
  }) {
    if (!keystoreAvailable) {
      throw KeystoreUnavailable();
    }
    if (key.length != 32) {
      throw ArgumentError('Database key must be 32 bytes.');
    }

    directory.createSync(recursive: true);
    final dbFile = File('${directory.path}/$namespace.sqlite');

    if (!dbFile.existsSync() && !createIfMissing) {
      throw KeyMaterialMissing();
    }

    final db = sqlite3.open(dbFile.path);
    _applyCipher(db, key);

    try {
      db.select('SELECT count(*) FROM sqlite_master');
    } on SqliteException {
      db.close();
      throw WrongDatabaseKey();
    }

    db.execute(
      'CREATE TABLE IF NOT EXISTS spike_kv (k TEXT PRIMARY KEY NOT NULL, v TEXT NOT NULL)',
    );

    return EncryptedSpikeStore._(db, dbFile);
  }

  void put(String key, String value) {
    _db.execute('INSERT OR REPLACE INTO spike_kv (k, v) VALUES (?, ?)', [
      key,
      value,
    ]);
  }

  String? get(String key) {
    final rows = _db.select('SELECT v FROM spike_kv WHERE k = ?', [key]);
    if (rows.isEmpty) {
      return null;
    }
    return rows.first['v'] as String?;
  }

  void rotateKey(Uint8List next) {
    if (next.length != 32) {
      throw ArgumentError('Database key must be 32 bytes.');
    }
    _db.execute("PRAGMA rekey = \"x'${_hexKey(next)}'\"");
  }

  void close() {
    _db.close();
  }
}
