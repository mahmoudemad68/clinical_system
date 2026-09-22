import 'dart:math';

/// In-memory Idempotency-Key for one logical write intent.
///
/// The same payload fingerprint reuses the key across timeouts and retries.
/// A material change of the fingerprint is a new intent and gets a new key.
/// The store never persists; a National ID must not be stored here as its
/// own field. Callers may include protected input in the fingerprint only
/// while the intent is in memory, then [retire] the whole pair.
class IntentIdempotencyStore {
  IntentIdempotencyStore({Random? random})
    : _random = random ?? Random.secure();

  final Random _random;
  String? _fingerprint;
  String? _key;

  /// Key currently retained, if any. Test seam only.
  String? get keyForTest => _key;

  /// Fingerprint currently retained, if any. Test seam only.
  String? get fingerprintForTest => _fingerprint;

  /// Return the retained key for [fingerprint], or mint one.
  String keyFor(String fingerprint) {
    if (_fingerprint == fingerprint && _key != null && _key!.isNotEmpty) {
      return _key!;
    }
    _fingerprint = fingerprint;
    _key = newIdempotencyKey(_random);
    return _key!;
  }

  /// Drop the intent after an authoritative success or a terminal
  /// validation/authorization outcome.
  void retire() {
    _fingerprint = null;
    _key = null;
  }
}

String newIdempotencyKey([Random? random]) {
  final rng = random ?? Random.secure();
  final bytes = List<int>.generate(16, (_) => rng.nextInt(256));
  return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
}
