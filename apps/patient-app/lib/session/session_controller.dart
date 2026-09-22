import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app_providers.dart';

class SessionController extends AsyncNotifier<IdentitySnapshot?> {
  @override
  Future<IdentitySnapshot?> build() async {
    final tokens = ref.read(tokenStoreProvider);
    final access = await tokens.readAccess();
    if (access == null || access.isEmpty) {
      return null;
    }
    ref.read(httpClientProvider).setAuthToken(access);
    return _readIdentity();
  }

  Future<void> markAuthenticated() async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(_readIdentity);
  }

  Future<void> signOut() async {
    try {
      await ref.read(authApiProvider).logout();
    } on ApiFailure catch (failure) {
      if (!failure.isAuthentication &&
          failure.code != ApiErrorCode.networkUnavailable) {
        await _clearLocalSession();
        rethrow;
      }
    } catch (_) {
      // Local isolation still happens.
    }
    await _clearLocalSession();
  }

  Future<IdentitySnapshot?> _readIdentity() async {
    try {
      final data = await ref.read(authApiProvider).me();
      return IdentitySnapshot.fromWire(data);
    } on ApiFailure catch (failure) {
      if (isLostSession(failure)) {
        await _clearLocalSession();
        return null;
      }
      rethrow;
    }
  }

  Future<void> _clearLocalSession() async {
    ref.read(httpClientProvider).setAuthToken(null);
    try {
      await ref.read(tokenStoreProvider).clear();
    } catch (_) {}
    state = const AsyncData(null);
  }
}

final sessionProvider =
    AsyncNotifierProvider<SessionController, IdentitySnapshot?>(
      SessionController.new,
    );

bool isLostSession(ApiFailure failure) {
  return failure.isAuthentication || failure.statusCode == 401;
}

bool isSessionFailure(Object error) {
  return error is ApiFailure && isLostSession(error);
}
