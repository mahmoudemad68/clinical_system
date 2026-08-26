import 'dart:async';

import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

import 'token_store.dart';

/// Adds the in-memory bearer token and coordinates a single refresh.
///
/// Non-idempotent requests are not retried unless the original Idempotency-Key
/// header is still present.
class AuthInterceptor extends Interceptor {
  AuthInterceptor({
    required TokenStore store,
    required ClinicHttpClient client,
    required Future<bool> Function() refresh,
  }) : _store = store,
       _client = client,
       _refresh = refresh;

  final TokenStore _store;
  final ClinicHttpClient _client;
  final Future<bool> Function() _refresh;
  Completer<bool>? _inFlight;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await _store.readAccess();
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
      _client.setAuthToken(token);
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    if (err.response?.statusCode != 401) {
      handler.next(err);
      return;
    }

    if (err.requestOptions.path.contains('/auth/token/refresh')) {
      handler.next(err);
      return;
    }

    final method = err.requestOptions.method.toUpperCase();
    final canRetry =
        method == 'GET' ||
        err.requestOptions.headers['Idempotency-Key'] is String;

    if (!canRetry) {
      await _store.clear();
      _client.setAuthToken(null);
      handler.next(err);
      return;
    }

    final refreshed = await _refreshOnce();
    if (!refreshed) {
      await _store.clear();
      _client.setAuthToken(null);
      handler.next(err);
      return;
    }

    try {
      final token = await _store.readAccess();
      final opts = err.requestOptions;
      if (token != null) {
        opts.headers['Authorization'] = 'Bearer $token';
      }
      final response = await _client.dio.fetch<dynamic>(opts);
      handler.resolve(response);
    } on DioException catch (retryError) {
      handler.next(retryError);
    }
  }

  Future<bool> _refreshOnce() {
    final existing = _inFlight;
    if (existing != null) {
      return existing.future;
    }
    final completer = Completer<bool>();
    _inFlight = completer;
    _refresh()
        .then(completer.complete)
        .catchError((Object _) {
          completer.complete(false);
        })
        .whenComplete(() {
          _inFlight = null;
        });
    return completer.future;
  }
}
