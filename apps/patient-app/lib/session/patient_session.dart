import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../onboarding/onboarding_controller.dart';
import '../providers.dart';

sealed class PatientRouteState {
  const PatientRouteState();
}

class PatientRouteBooting extends PatientRouteState {
  const PatientRouteBooting();
}

class PatientRouteUnauthenticated extends PatientRouteState {
  const PatientRouteUnauthenticated({this.message});

  final String? message;
}

class PatientRouteResolving extends PatientRouteState {
  const PatientRouteResolving();
}

class PatientRouteWrongAccount extends PatientRouteState {
  const PatientRouteWrongAccount(this.accountType);

  final String accountType;
}

class PatientRouteOnboarding extends PatientRouteState {
  const PatientRouteOnboarding();
}

class PatientRouteManualReview extends PatientRouteState {
  const PatientRouteManualReview({this.requestId});

  final String? requestId;
}

class PatientRouteProfile extends PatientRouteState {
  const PatientRouteProfile(this.profile);

  final PatientProfile profile;
}

class PatientRouteFailure extends PatientRouteState {
  const PatientRouteFailure(this.failure);

  final ApiFailure failure;
}

class PatientSession extends Notifier<PatientRouteState> {
  int _generation = 0;
  bool _manualReviewHold = false;

  @override
  PatientRouteState build() => const PatientRouteBooting();

  Future<void> restore() async {
    final tokens = ref.read(tokenStoreProvider);
    final access = await tokens.readAccess();
    if (access == null || access.isEmpty) {
      state = const PatientRouteUnauthenticated();
      return;
    }
    ref.read(httpClientProvider).setAuthToken(access);
    await resolve();
  }

  Future<void> onAuthenticated() async {
    _manualReviewHold = false;
    await resolve();
  }

  Future<void> resolve() async {
    final gen = ++_generation;
    state = const PatientRouteResolving();
    try {
      final raw = await ref.read(authApiProvider).me();
      if (gen != _generation) {
        return;
      }
      final identity = mapSessionIdentity(raw);
      if (!identity.isPatientAccount) {
        _manualReviewHold = false;
        state = PatientRouteWrongAccount(identity.accountType);
        return;
      }
      await _loadOwnProfile(gen);
    } on ApiFailure catch (failure) {
      if (gen != _generation) {
        return;
      }
      await _onIdentityFailure(failure);
    }
  }

  Future<void> refresh() => resolve();

  Future<void> applyOnboarding(PatientOnboardingResult result) async {
    final gen = ++_generation;
    if (result.status == PatientOnboardingStatus.manualReviewRequired) {
      _manualReviewHold = true;
      ref.read(onboardingProvider.notifier).clearNationalId();
      state = const PatientRouteManualReview();
      return;
    }
    _manualReviewHold = false;
    state = const PatientRouteResolving();
    await _loadOwnProfile(gen);
  }

  Future<void> replaceProfile(PatientProfile profile) async {
    _manualReviewHold = false;
    state = PatientRouteProfile(profile);
  }

  Future<void> signOut() async {
    _generation++;
    _manualReviewHold = false;
    ref.read(onboardingProvider.notifier).reset();
    try {
      await ref.read(authApiProvider).logout();
    } catch (_) {
      await ref.read(tokenStoreProvider).clear();
      ref.read(httpClientProvider).setAuthToken(null);
    }
    state = const PatientRouteUnauthenticated();
  }

  Future<void> _loadOwnProfile(int gen) async {
    try {
      final profile = await ref.read(patientApiProvider).getOwnProfile();
      if (gen != _generation) {
        return;
      }
      _manualReviewHold = false;
      state = PatientRouteProfile(profile);
    } on ApiFailure catch (failure) {
      if (gen != _generation) {
        return;
      }
      if (failure.isAuthentication) {
        await _clearLocalSession();
        state = const PatientRouteUnauthenticated();
        return;
      }
      if (failure.code == ApiErrorCode.notFound) {
        if (_manualReviewHold) {
          state = PatientRouteManualReview(requestId: failure.requestId);
          return;
        }
        state = const PatientRouteOnboarding();
        return;
      }
      state = PatientRouteFailure(failure);
    }
  }

  Future<void> _onIdentityFailure(ApiFailure failure) async {
    if (failure.isAuthentication || failure.code == ApiErrorCode.notFound) {
      await _clearLocalSession();
      state = const PatientRouteUnauthenticated();
      return;
    }
    state = PatientRouteFailure(failure);
  }

  Future<void> _clearLocalSession() async {
    _manualReviewHold = false;
    ref.read(onboardingProvider.notifier).reset();
    await ref.read(tokenStoreProvider).clear();
    ref.read(httpClientProvider).setAuthToken(null);
  }
}

final patientSessionProvider =
    NotifierProvider<PatientSession, PatientRouteState>(PatientSession.new);
