import 'dart:async';

import 'package:clinic_networking/clinic_networking.dart';
import 'package:dio/dio.dart';

import 'token_store.dart';

/// Adds the in-memory bearer token and coordinates a single refresh.
///
/// Non-idempotent requests are not retried unless the original Idempotency-Key
/// header is still present.
///
/// A failed refresh does not clear the vault. Only [AuthApi.refresh] may clear
/// credentials, and only when the refresh endpoint authoritatively rejects
/// them. Transient, protocol, and persistence failures keep the previous
/// envelope as the source of truth.
class AuthInterceptor extends Interceptor {
  AuthInterceptor({
    required TokenStore store,
    required ClinicHttpClient client,
    required Future<bool> Function() refresh,
  }) : this._(store, client, refresh);

  AuthInterceptor._(this._store, this._client, this._refresh);

  final TokenStore _store;
  final ClinicHttpClient _client;
  final Future<bool> Function() _refresh;
  Completer<bool>? _inFlight;

  static const _retriedExtra = 'clinicAuthRetried';

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
  Future<void> onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) async {
    if (response.statusCode != 401) {
      handler.next(response);
      return;
    }
    if (_isRefreshRequest(response.requestOptions)) {
      handler.next(response);
      return;
    }
    await _recoverUnauthorized(
      response.requestOptions,
      original: response,
      onResolved: handler.resolve,
      onGiveUp: handler.reject,
    );
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

    await _recoverUnauthorized(
      err.requestOptions,
      original: err.response,
      onResolved: handler.resolve,
      onGiveUp: handler.next,
    );
  }

  Future<void> _recoverUnauthorized(
    RequestOptions failed, {
    required Response<dynamic>? original,
    required void Function(Response<dynamic> response) onResolved,
    required void Function(DioException err) onGiveUp,
  }) async {
    if (_isRefreshRequest(failed) || failed.extra[_retriedExtra] == true) {
      onGiveUp(_unauthorized(failed, original));
      return;
    }

    final method = failed.method.toUpperCase();
    final canRetry =
        method == 'GET' || failed.headers['Idempotency-Key'] is String;

    if (!canRetry) {
      onGiveUp(_unauthorized(failed, original));
      return;
    }

    final refreshed = await _refreshOnce();
    if (!refreshed) {
      onGiveUp(_unauthorized(failed, original));
      return;
    }

    try {
      final token = await _store.readAccess();
      failed.extra[_retriedExtra] = true;
      if (token != null && token.isNotEmpty) {
        failed.headers['Authorization'] = 'Bearer $token';
      } else {
        failed.headers.remove('Authorization');
      }
      final response = await _client.dio.fetch<dynamic>(failed);
      onResolved(response);
    } on DioException catch (retryError) {
      onGiveUp(retryError);
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

  bool _isRefreshRequest(RequestOptions options) {
    return options.path.contains('/auth/token/refresh');
  }

  DioException _unauthorized(
    RequestOptions options,
    Response<dynamic>? response,
  ) {
    return DioException(
      requestOptions: options,
      response: response,
      type: DioExceptionType.badResponse,
    );
  }
}
