import 'dart:async';

import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'fakes.dart';

void main() {
  late MemoryVault vault;
  late TokenStore store;

  setUp(() {
    vault = MemoryVault();
    store = TokenStore(vault);
  });

  test(
    'J. transient refresh failure does not clear the previous envelope',
    () async {
      await store.write(access: 'old-access', refresh: 'old-refresh');
      var refreshCalls = 0;
      final adapter = ScriptedAdapter((options) async {
        if (options.extra['clinicAuthRetried'] == true) {
          fail('must not retry the original request after a failed refresh');
        }
        return jsonEnvelope(
          401,
          null,
          errors: [
            {'code': 'UNAUTHENTICATED', 'message': 'expired'},
          ],
        );
      });
      final client = testClient(adapter);
      client.dio.interceptors.add(
        AuthInterceptor(
          store: store,
          client: client,
          refresh: () async {
            refreshCalls += 1;
            return false;
          },
        ),
      );

      await expectLater(
        client.dio.get<Map<String, dynamic>>('/api/v1/me'),
        throwsA(isA<DioException>()),
      );
      expect(refreshCalls, 1);
      expect(await store.readAccess(), 'old-access');
      expect(await store.readRefresh(), 'old-refresh');
    },
  );

  test(
    'K. authoritative invalid refresh still leaves the vault empty',
    () async {
      await store.write(access: 'old-access', refresh: 'old-refresh');
      final adapter = ScriptedAdapter((options) async {
        if (isRefreshCall(options)) {
          return jsonEnvelope(
            401,
            null,
            errors: [
              {'code': 'UNAUTHENTICATED', 'message': 'revoked'},
            ],
          );
        }
        return jsonEnvelope(
          401,
          null,
          errors: [
            {'code': 'UNAUTHENTICATED', 'message': 'expired'},
          ],
        );
      });
      final client = testClient(adapter);
      final api = AuthApi(client, store);
      client.dio.interceptors.add(
        AuthInterceptor(store: store, client: client, refresh: api.refresh),
      );

      await expectLater(
        client.dio.get<Map<String, dynamic>>('/api/v1/me'),
        throwsA(isA<DioException>()),
      );
      expect(await store.readAccess(), isNull);
      expect(await store.readRefresh(), isNull);
    },
  );

  test(
    'L. concurrent 401s share one refresh and one token replacement',
    () async {
      await store.write(access: 'old-access', refresh: 'old-refresh');
      var refreshCalls = 0;
      var pendingUnauthorized = 0;
      final bothUnauthorized = Completer<void>();
      final adapter = ScriptedAdapter((options) async {
        if (options.extra['clinicAuthRetried'] == true) {
          return jsonEnvelope(200, {'user_id': 'u1'});
        }
        pendingUnauthorized += 1;
        if (pendingUnauthorized >= 2 && !bothUnauthorized.isCompleted) {
          bothUnauthorized.complete();
        }
        await bothUnauthorized.future;
        return jsonEnvelope(
          401,
          null,
          errors: [
            {'code': 'UNAUTHENTICATED', 'message': 'expired'},
          ],
        );
      });
      final client = testClient(adapter);
      client.dio.interceptors.add(
        AuthInterceptor(
          store: store,
          client: client,
          refresh: () async {
            refreshCalls += 1;
            await store.write(access: 'new-access', refresh: 'new-refresh');
            // Yield so the second 401 handler can join the in-flight Completer.
            await Future<void>.delayed(Duration.zero);
            return true;
          },
        ),
      );

      final results = await Future.wait([
        client.dio.get<Map<String, dynamic>>('/api/v1/me'),
        client.dio.get<Map<String, dynamic>>('/api/v1/sessions'),
      ]);
      expect(results, hasLength(2));
      expect(refreshCalls, 1);
      expect(await store.readAccess(), 'new-access');
      expect(await store.readRefresh(), 'new-refresh');
    },
  );

  test('a refresh exception does not wipe the vault', () async {
    await store.write(access: 'old-access', refresh: 'old-refresh');
    final adapter = ScriptedAdapter(
      (_) async => jsonEnvelope(
        401,
        null,
        errors: [
          {'code': 'UNAUTHENTICATED', 'message': 'expired'},
        ],
      ),
    );
    final client = testClient(adapter);
    client.dio.interceptors.add(
      AuthInterceptor(
        store: store,
        client: client,
        refresh: () async {
          throw StateError('persist exploded');
        },
      ),
    );

    await expectLater(
      client.dio.get<Map<String, dynamic>>('/api/v1/me'),
      throwsA(isA<DioException>()),
    );
    expect(await store.readAccess(), 'old-access');
  });
}
