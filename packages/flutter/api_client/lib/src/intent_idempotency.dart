import 'dart:math';

/// In-memory Idempotency-Key retainer for one logical write intent.
///
/// Same fingerprint (including retries/timeouts) reuses the key. A material
/// payload change issues a new key. The store never persists National ID or
/// any other field value — only an integer fingerprint and a random key.
class IntentIdempotencyStore {
  IntentIdempotencyStore({Random? random})
    : _random = random ?? Random.secure();

  final Random _random;
  String? _key;
  int? _fingerprint;

  /// Key currently retained, or null when idle.
  String? get currentKey => _key;

  /// Retain or mint a key for [fingerprint].
  String retain(int fingerprint) {
    if (_key != null && _fingerprint == fingerprint) {
      return _key!;
    }
    _fingerprint = fingerprint;
    _key = _mint();
    return _key!;
  }

  /// Drop the intent after an authoritative or terminal outcome.
  void retire() {
    _key = null;
    _fingerprint = null;
  }

  String _mint() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}
