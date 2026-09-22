import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:test/test.dart';

import 'synthetic_national_id.dart';

void main() {
  test('same fingerprint reuses the key; material change mints a new one', () {
    final store = IntentIdempotencyStore();
    final first = store.retain(11);
    expect(store.retain(11), first);
    final second = store.retain(12);
    expect(second, isNot(first));
    expect(store.currentKey, second);
  });

  test('retire drops the in-flight key', () {
    final store = IntentIdempotencyStore();
    store.retain(4);
    store.retire();
    expect(store.currentKey, isNull);
    expect(store.retain(4), isNotNull);
  });

  test('key is not derived from a National ID string', () {
    final store = IntentIdempotencyStore();
    const nid = kSyntheticNationalId;
    final key = store.retain(Object.hash(nid, 'Synthetic Patient'));
    expect(key, isNot(contains(nid)));
    expect(key, isNot(nid));
    expect(store.currentKey, isNot(contains(nid)));
  });
}
