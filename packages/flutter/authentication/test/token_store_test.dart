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

void main() {
  test('token store writes and clears without putting material in test names', () async {
    final store = TokenStore(MemoryVault());

    await store.write(access: 'access-material', refresh: 'refresh-material');
    expect(await store.readAccess(), isNotEmpty);
    expect(await store.readRefresh(), isNotEmpty);

    await store.clear();
    expect(await store.readAccess(), isNull);
    expect(await store.readRefresh(), isNull);
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
