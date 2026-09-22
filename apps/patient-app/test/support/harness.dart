import 'dart:convert';
import 'dart:typed_data';

import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_patient_app/main.dart';
import 'package:clinic_patient_app/providers.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:clinic_localization/clinic_localization.dart';
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
  final List<String> bodies = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    final data = options.data;
    bodies.add(data == null ? '' : jsonEncode(data));
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

Map<String, dynamic> mePatient() => {
  'user_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02',
  'account_type': 'patient',
  'status': 'active',
  'language': 'en',
  'assurance_level': 'aal1_password',
  'profile_links': <String>[],
};

Map<String, dynamic> profileWire({
  String name = 'Synthetic Patient',
  int version = 1,
  String status = 'active',
}) {
  return {
    'patient_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
    'full_name': name,
    'gender': 'female',
    'date_of_birth': '1990-01-15',
    'height_cm': '165.50',
    'weight_kg': '62.30',
    'marital_status': 'single',
    'blood_type': 'A+',
    'status': status,
    'version': version,
    'created_at': '2026-09-01T12:00:00Z',
    'updated_at': '2026-09-01T12:00:00Z',
  };
}

List<Override> patientOverrides({
  required MemoryVault vault,
  required ClinicHttpClient client,
}) {
  return [
    credentialVaultProvider.overrideWithValue(vault),
    httpClientProvider.overrideWithValue(client),
  ];
}

Widget materialHost(
  Widget child, {
  Locale locale = ClinicLocales.english,
  double textScale = 1,
}) {
  return MediaQuery(
    data: MediaQueryData(textScaler: TextScaler.linear(textScale)),
    child: MaterialApp(
      locale: locale,
      supportedLocales: ClinicLocales.supported,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: child,
    ),
  );
}

Widget wrap(
  Widget child, {
  List<Override> overrides = const [],
  Locale locale = ClinicLocales.english,
  double textScale = 1,
}) {
  return ProviderScope(
    overrides: [
      localeProvider.overrideWith(() => LocaleController(initial: locale)),
      ...overrides,
    ],
    child: materialHost(child, locale: locale, textScale: textScale),
  );
}

Widget clinicHost({
  List<Override> overrides = const [],
  Locale locale = ClinicLocales.english,
  double textScale = 1,
}) {
  return ProviderScope(
    overrides: [
      localeProvider.overrideWith(() => LocaleController(initial: locale)),
      ...overrides,
    ],
    child: MediaQuery(
      data: MediaQueryData(textScaler: TextScaler.linear(textScale)),
      child: const ClinicApp(),
    ),
  );
}

bool isPath(RequestOptions options, String fragment) {
  return options.path.contains(fragment);
}
