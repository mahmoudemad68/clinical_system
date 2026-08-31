import 'dart:convert';
import 'dart:typed_data';

import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

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

class FailingWriteVault implements CredentialVault {
  FailingWriteVault(this.values);

  final Map<String, String> values;

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write({required String key, required String value}) async {
    throw StateError('vault write failed');
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
  String raw = '',
}) {
  final body = raw.isNotEmpty
      ? raw
      : jsonEncode({
          'data': data,
          'meta': <String, Object?>{},
          'errors': errors,
          'request_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
        });
  return ResponseBody.fromString(
    body,
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

String? headerOf(RequestOptions options, String name) {
  final value = options.headers[name];
  return value?.toString();
}

bool isRefreshCall(RequestOptions options) {
  return options.path.contains('/auth/token/refresh');
}
