import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:flutter_test/flutter_test.dart';

class MemoryVault implements CredentialVault {
  final Map<String, String> values = {};

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write({required String key, required String value}) async {
    values[key] = value;
  }
}

class FailingDeleteVault implements CredentialVault {
  FailingDeleteVault(this.seed);

  final Map<String, String> seed;

  @override
  Future<void> delete(String key) async {}

  @override
  Future<String?> read(String key) async => seed[key];

  @override
  Future<void> write({required String key, required String value}) async {
    seed[key] = value;
  }
}

class FailingVault implements CredentialVault {
  @override
  Future<void> delete(String key) async {}

  @override
  Future<String?> read(String key) async => null;

  @override
  Future<void> write({required String key, required String value}) async {
    throw StateError('vault write failed');
  }
}

void main() {
  test('token store writes a single envelope so a crash cannot split the pair', () async {
    final vault = MemoryVault();
    final store = TokenStore(vault);

    await store.write(access: 'access-material', refresh: 'refresh-material');
    expect(vault.values.containsKey(TokenStore.envelopeKey), isTrue);
    expect(await store.readAccess(), isNotEmpty);
    expect(await store.readRefresh(), isNotEmpty);

    await store.clear();
    expect(await store.readAccess(), isNull);
    expect(await store.readRefresh(), isNull);
  });

  test('a vault write failure leaves no split access/refresh pair', () async {
    final vault = FailingVault();
    final store = TokenStore(vault);

    await expectLater(
      store.write(access: 'access-material', refresh: 'refresh-material'),
      throwsA(isA<StateError>()),
    );
    expect(await store.readAccess(), isNull);
    expect(await store.readRefresh(), isNull);
  });

  test('clear fails closed when the vault still holds an envelope', () async {
    final vault = FailingDeleteVault({
      TokenStore.envelopeKey: '{"version":1,"access":"a","refresh":"r"}',
    });
    final store = TokenStore(vault);

    await expectLater(store.clear(), throwsA(isA<StateError>()));
  });

  test('auth outcome strips tokens before UI consumption', () {
    final outcome = AuthOutcome.fromWire({
      'status': 'pending_phone',
      'mfa_required': false,
      'session_kind': 'device',
      'access_token': 'access-material',
      'refresh_token': 'refresh-material',
    }).withoutSecrets();

    expect(outcome.accessToken, isNull);
    expect(outcome.refreshToken, isNull);
    expect(outcome.sessionKind, 'device');
  });
}
