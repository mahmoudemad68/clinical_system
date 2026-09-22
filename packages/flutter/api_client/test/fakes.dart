import 'dart:convert';
import 'dart:typed_data';

import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

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

Future<String> readBody(RequestOptions options) async {
  final data = options.data;
  if (data is Map) {
    return jsonEncode(data);
  }
  if (data is String) {
    return data;
  }
  return jsonEncode(data);
}
