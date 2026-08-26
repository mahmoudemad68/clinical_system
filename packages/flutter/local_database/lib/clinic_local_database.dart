/// Encrypted SQLite local persistence using sqlite3 v3 SQLite3MultipleCiphers hooks.
///
/// G-06-01 spike only. The table is a synthetic key/value canary store. No
/// clinical draft, outbox, or cache row is written. Phase 05 will put Drift on
/// the same encrypted connection.
library;

export 'src/encrypted_spike_store.dart';
