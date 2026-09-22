import 'dart:math';

import 'package:clinic_networking/clinic_networking.dart';
import 'package:test/test.dart';

void main() {
  test('reuses the key for the same fingerprint', () {
    final store = IntentIdempotencyStore(random: Random(1));
    final first = store.keyFor('same-intent');
    final second = store.keyFor('same-intent');
    expect(second, first);
    expect(first, isNotEmpty);
  });

  test('mints a new key when the fingerprint changes', () {
    final store = IntentIdempotencyStore(random: Random(2));
    final first = store.keyFor('intent-a');
    final second = store.keyFor('intent-b');
    expect(second, isNot(first));
  });

  test('does not derive the key from the fingerprint string', () {
    final store = IntentIdempotencyStore(random: Random(3));
    const fingerprint = '29201011234567|Ada Lovelace|female';
    final key = store.keyFor(fingerprint);
    expect(key, isNot(fingerprint));
    expect(key.contains('29201011234567'), isFalse);
    expect(key.contains('Ada'), isFalse);
  });

  test('retire drops the retained pair', () {
    final store = IntentIdempotencyStore(random: Random(4));
    store.keyFor('intent');
    store.retire();
    expect(store.keyForTest, isNull);
    expect(store.fingerprintForTest, isNull);
    final next = store.keyFor('intent');
    expect(next, isNotEmpty);
  });
}
