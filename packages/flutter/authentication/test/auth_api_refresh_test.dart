import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'fakes.dart';

Map<String, String> completeTokens({
  String access = 'new-access',
  String refresh = 'new-refresh',
}) {
  return {'access_token': access, 'refresh_token': refresh};
}

void main() {
  late MemoryVault vault;
  late TokenStore store;
  late ClinicHttpClient httpClient;
  late List<RequestOptions> refreshCalls;

  Future<AuthApi> apiWith(
    Future<ResponseBody> Function(RequestOptions options) handler,
  ) async {
    final adapter = ScriptedAdapter(handler);
    refreshCalls = adapter.requests;
    httpClient = testClient(adapter);
    await store.write(access: 'old-access', refresh: 'old-refresh');
    httpClient.setAuthToken('old-access');
    return AuthApi(httpClient, store);
  }

  setUp(() {
    vault = MemoryVault();
    store = TokenStore(vault);
    refreshCalls = [];
  });

  test(
    'A. complete 2xx persists the pair, updates memory, and clears the key',
    () async {
      final api = await apiWith((options) async {
        expect(isRefreshCall(options), isTrue);
        return jsonEnvelope(200, completeTokens());
      });

      expect(await api.refresh(), isTrue);
      expect(await store.readAccess(), 'new-access');
      expect(await store.readRefresh(), 'new-refresh');
      expect(
        httpClient.dio.options.headers['Authorization'],
        'Bearer new-access',
      );
      expect(api.refreshIdempotencyKeyForTest, isNull);
      expect(refreshCalls, hasLength(1));
      expect(headerOf(refreshCalls.single, 'Idempotency-Key'), isNotEmpty);
    },
  );

  test(
    'B. missing access token fails, keeps the envelope, and retains the key',
    () async {
      final api = await apiWith(
        (_) async => jsonEnvelope(200, {'refresh_token': 'new-refresh'}),
      );

      expect(await api.refresh(), isFalse);
      expect(await store.readAccess(), 'old-access');
      expect(await store.readRefresh(), 'old-refresh');
      expect(api.refreshIdempotencyKeyForTest, isNotEmpty);
    },
  );

  test(
    'C. missing refresh token fails, keeps the envelope, and retains the key',
    () async {
      final api = await apiWith(
        (_) async => jsonEnvelope(200, {'access_token': 'new-access'}),
      );

      expect(await api.refresh(), isFalse);
      expect(await store.readAccess(), 'old-access');
      expect(api.refreshIdempotencyKeyForTest, isNotEmpty);
    },
  );

  test('D. both tokens missing fails and retains the key', () async {
    final api = await apiWith(
      (_) async => jsonEnvelope(200, <String, Object?>{}),
    );

    expect(await api.refresh(), isFalse);
    expect(await store.readAccess(), 'old-access');
    expect(api.refreshIdempotencyKeyForTest, isNotEmpty);
  });

  test('E. empty-string tokens fail and replace nothing', () async {
    final api = await apiWith(
      (_) async => jsonEnvelope(200, {
        'access_token': '',
        'refresh_token': 'new-refresh',
      }),
    );

    expect(await api.refresh(), isFalse);
    expect(await store.readAccess(), 'old-access');
    expect(await store.readRefresh(), 'old-refresh');
  });

  test('E. empty refresh token fails and replace nothing', () async {
    final api = await apiWith(
      (_) async => jsonEnvelope(200, {
        'access_token': 'new-access',
        'refresh_token': '',
      }),
    );

    expect(await api.refresh(), isFalse);
    expect(await store.readAccess(), 'old-access');
    expect(await store.readRefresh(), 'old-refresh');
  });

  test(
    'F. malformed 2xx fails, preserves the envelope, and retains the key',
    () async {
      final api = await apiWith(
        (_) async => jsonEnvelope(200, null, raw: 'not-json'),
      );

      expect(await api.refresh(), isFalse);
      expect(await store.readAccess(), 'old-access');
      expect(api.refreshIdempotencyKeyForTest, isNotEmpty);
    },
  );

  test(
    'G. TokenStore.write failure fails, keeps memory/disk, and retains the key',
    () async {
      final failing = FailingWriteVault({
        TokenStore.envelopeKey:
            '{"version":1,"access":"old-access","refresh":"old-refresh"}',
      });
      store = TokenStore(failing);
      final adapter = ScriptedAdapter(
        (_) async => jsonEnvelope(200, completeTokens()),
      );
      final client = testClient(adapter);
      client.setAuthToken('old-access');
      final api = AuthApi(client, store);

      expect(await api.refresh(), isFalse);
      expect(await store.readAccess(), 'old-access');
      expect(await store.readRefresh(), 'old-refresh');
      expect(client.dio.options.headers['Authorization'], 'Bearer old-access');
      expect(api.refreshIdempotencyKeyForTest, isNotEmpty);
    },
  );

  test(
    'H. retry after incomplete 2xx reuses the same Idempotency-Key',
    () async {
      var refreshAttempts = 0;
      final api = await apiWith((options) async {
        refreshAttempts += 1;
        if (refreshAttempts == 1) {
          return jsonEnvelope(200, {'access_token': 'only-access'});
        }
        return jsonEnvelope(200, completeTokens());
      });

      expect(await api.refresh(), isFalse);
      final retained = api.refreshIdempotencyKeyForTest;
      expect(retained, isNotEmpty);

      expect(await api.refresh(), isTrue);
      expect(headerOf(refreshCalls[0], 'Idempotency-Key'), retained);
      expect(headerOf(refreshCalls[1], 'Idempotency-Key'), retained);
      expect(await store.readAccess(), 'new-access');
      expect(api.refreshIdempotencyKeyForTest, isNull);
    },
  );

  test(
    'H. retry after a lost response reuses the same Idempotency-Key',
    () async {
      var refreshAttempts = 0;
      final api = await apiWith((options) async {
        refreshAttempts += 1;
        if (refreshAttempts == 1) {
          throw DioException(
            requestOptions: options,
            type: DioExceptionType.connectionTimeout,
          );
        }
        return jsonEnvelope(200, completeTokens());
      });

      expect(await api.refresh(), isFalse);
      final retained = api.refreshIdempotencyKeyForTest;
      expect(retained, isNotEmpty);

      expect(await api.refresh(), isTrue);
      expect(headerOf(refreshCalls[0], 'Idempotency-Key'), retained);
      expect(headerOf(refreshCalls[1], 'Idempotency-Key'), retained);
      expect(await store.readAccess(), 'new-access');
      expect(api.refreshIdempotencyKeyForTest, isNull);
    },
  );

  test(
    'I. the next independent refresh generates a new Idempotency-Key',
    () async {
      final api = await apiWith(
        (_) async => jsonEnvelope(200, completeTokens()),
      );

      expect(await api.refresh(), isTrue);
      final firstKey = headerOf(refreshCalls[0], 'Idempotency-Key');
      await store.write(
        access: 'newer-access-seed',
        refresh: 'newer-refresh-seed',
      );
      expect(await api.refresh(), isTrue);
      final secondKey = headerOf(refreshCalls[1], 'Idempotency-Key');
      expect(firstKey, isNotEmpty);
      expect(secondKey, isNotEmpty);
      expect(secondKey, isNot(firstKey));
    },
  );

  test('J. transient 5xx keeps the previous envelope and the key', () async {
    final api = await apiWith(
      (_) async => jsonEnvelope(
        503,
        null,
        errors: [
          {'code': 'DEPENDENCY_UNAVAILABLE', 'message': 'busy'},
        ],
      ),
    );

    expect(await api.refresh(), isFalse);
    expect(await store.readAccess(), 'old-access');
    expect(await store.readRefresh(), 'old-refresh');
    expect(api.refreshIdempotencyKeyForTest, isNotEmpty);
  });

  test('K. authoritative invalid-refresh 401 clears the vault', () async {
    final api = await apiWith(
      (_) async => jsonEnvelope(
        401,
        null,
        errors: [
          {'code': 'UNAUTHENTICATED', 'message': 'revoked'},
        ],
      ),
    );

    expect(await api.refresh(), isFalse);
    expect(await store.readAccess(), isNull);
    expect(await store.readRefresh(), isNull);
    expect(api.refreshIdempotencyKeyForTest, isNull);
  });

  test('logout does not report success when the server revoke fails', () async {
    final api = await apiWith(
      (_) async => jsonEnvelope(
        500,
        null,
        errors: [
          {'code': 'INTERNAL_ERROR', 'message': 'no'},
        ],
      ),
    );

    await expectLater(api.logout(), throwsA(isA<ApiFailure>()));
    expect(await store.readAccess(), 'old-access');
    expect(await store.readRefresh(), 'old-refresh');
  });
}
