import 'dart:convert';
import 'dart:typed_data';

import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_patient_app/app_providers.dart';
import 'package:clinic_patient_app/main.dart';
import 'package:clinic_patient_app/shell/locale_controller.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
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

class ScriptedAdapter implements HttpClientAdapter {
  ScriptedAdapter(this._handler);

  final Future<ResponseBody> Function(RequestOptions options) _handler;
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return _handler(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody jsonEnvelope(
  int status,
  Object? data, {
  List<Map<String, String>> errors = const [],
}) {
  return ResponseBody.fromString(
    jsonEncode({
      'data': data,
      'meta': <String, Object?>{},
      'errors': errors,
      'request_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
    }),
    status,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    },
  );
}

ClinicHttpClient testClient(ScriptedAdapter adapter) {
  final dio = Dio();
  dio.httpClientAdapter = adapter;
  return ClinicHttpClient(baseUrl: 'https://api.example.com', dio: dio);
}

Map<String, dynamic> healthWire() {
  return {
    'status': 'operational',
    'message': 'ok',
    'components': {
      'core': 'operational',
      'realtime': 'operational',
      'ai': 'operational',
    },
    'version': '0.1.0',
    'server_time': '2026-09-01T00:00:00Z',
  };
}

Map<String, dynamic> identityWire({
  String userId = 'user-a',
  String accountType = 'patient',
}) {
  return {
    'user_id': userId,
    'account_type': accountType,
    'status': 'active',
    'language': 'en',
    'assurance_level': 'aal1_password',
    'profile_links': <String>[],
  };
}

Map<String, dynamic> profileWire({
  String name = 'Synthetic Patient',
  int version = 1,
  String status = 'active',
}) {
  return {
    'patient_id': '0199a5c8-aaaa-7c3a-9b41-2f6d0c5e7c01',
    'full_name': name,
    'gender': 'female',
    'date_of_birth': '1990-01-15',
    'height_cm': '165.50',
    'weight_kg': '62.30',
    'marital_status': 'single',
    'blood_type': 'A+',
    'status': status,
    'version': version,
    'created_at': '2026-09-01T00:00:00.000000Z',
    'updated_at': '2026-09-01T00:00:00.000000Z',
  };
}

Widget patientApp({
  required ClinicHttpClient client,
  required TokenStore tokens,
  List<Override> extraOverrides = const [],
  Locale? locale,
}) {
  return ProviderScope(
    overrides: [
      httpClientProvider.overrideWithValue(client),
      tokenStoreProvider.overrideWithValue(tokens),
      healthProvider.overrideWith((ref) async => testHealth()),
      if (locale != null)
        localeProvider.overrideWith(() => _FixedLocale(locale)),
      ...extraOverrides,
    ],
    child: const ClinicApp(),
  );
}

class _FixedLocale extends LocaleController {
  _FixedLocale(this._locale);
  final Locale _locale;
  @override
  Locale build() => _locale;
}

PlatformHealth testHealth() {
  return PlatformHealth(
    status: ComponentStatus.operational,
    message: 'ok',
    core: ComponentStatus.operational,
    realtime: ComponentStatus.operational,
    ai: ComponentStatus.operational,
    version: '0.1.0',
    serverTime: DateTime.utc(2026, 9, 1),
  );
}

Future<void> selectDropdown(WidgetTester tester, Key key, String label) async {
  await tester.tap(find.byKey(key));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
  await tester.tap(find.text(label).last);
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
}

/// Bounded pump that does not wait for infinite progress indicators.
Future<void> pumpUntilFound(
  WidgetTester tester,
  Finder finder, {
  int maxPumps = 80,
  bool realAsync = false,
}) async {
  for (var i = 0; i < maxPumps; i++) {
    if (realAsync) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 50)),
      );
    }
    await tester.pump(const Duration(milliseconds: 1));
    if (finder.evaluate().isNotEmpty) {
      return;
    }
  }
  fail('Timed out waiting for $finder');
}

Future<void> pumpUntilGone(
  WidgetTester tester,
  Finder finder, {
  int maxPumps = 80,
  bool realAsync = false,
}) async {
  for (var i = 0; i < maxPumps; i++) {
    if (realAsync) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 50)),
      );
    }
    await tester.pump(const Duration(milliseconds: 1));
    if (finder.evaluate().isEmpty) {
      return;
    }
  }
  fail('Timed out waiting for $finder to disappear');
}
