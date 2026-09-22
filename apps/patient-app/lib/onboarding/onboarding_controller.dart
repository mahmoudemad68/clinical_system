import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app_providers.dart';
import '../profile/own_profile_controller.dart';

enum OnboardingStep { identity, demographics, measurements, review }

class OnboardingDraft {
  const OnboardingDraft({
    this.fullName = '',
    this.gender = '',
    this.dateOfBirth = '',
    this.maritalStatus = '',
    this.heightCm = '',
    this.weightKg = '',
    this.bloodType = '',
    this.step = OnboardingStep.identity,
  });

  final String fullName;
  final String gender;
  final String dateOfBirth;
  final String maritalStatus;
  final String heightCm;
  final String weightKg;
  final String bloodType;
  final OnboardingStep step;

  OnboardingDraft copyWith({
    String? fullName,
    String? gender,
    String? dateOfBirth,
    String? maritalStatus,
    String? heightCm,
    String? weightKg,
    String? bloodType,
    OnboardingStep? step,
  }) {
    return OnboardingDraft(
      fullName: fullName ?? this.fullName,
      gender: gender ?? this.gender,
      dateOfBirth: dateOfBirth ?? this.dateOfBirth,
      maritalStatus: maritalStatus ?? this.maritalStatus,
      heightCm: heightCm ?? this.heightCm,
      weightKg: weightKg ?? this.weightKg,
      bloodType: bloodType ?? this.bloodType,
      step: step ?? this.step,
    );
  }
}

class OnboardingDraftController extends Notifier<OnboardingDraft> {
  @override
  OnboardingDraft build() => const OnboardingDraft();

  void update(OnboardingDraft Function(OnboardingDraft current) change) {
    state = change(state);
  }

  void goTo(OnboardingStep step) {
    state = state.copyWith(step: step);
  }

  void reset() {
    state = const OnboardingDraft();
    ref.read(onboardingSubmitProvider.notifier).reset();
  }
}

final onboardingDraftProvider =
    NotifierProvider<OnboardingDraftController, OnboardingDraft>(
      OnboardingDraftController.new,
    );

class OnboardingSubmitState {
  const OnboardingSubmitState({
    this.busy = false,
    this.error,
    this.field,
    this.requestId,
  });

  final bool busy;
  final String? error;
  final String? field;
  final String? requestId;
}

class OnboardingSubmitController extends Notifier<OnboardingSubmitState> {
  final IntentIdempotencyStore _intents = IntentIdempotencyStore();

  @override
  OnboardingSubmitState build() => const OnboardingSubmitState();

  String? get retainedKey => _intents.currentKey;

  void reset() {
    _intents.retire();
    state = const OnboardingSubmitState();
  }

  Future<PatientOnboardingStatus?> submit({required String nationalId}) async {
    final draft = ref.read(onboardingDraftProvider);
    final request = PatientOnboardingRequest(
      nationalId: nationalId.trim(),
      fullName: draft.fullName.trim(),
      gender: draft.gender,
      dateOfBirth: draft.dateOfBirth.trim().isEmpty
          ? null
          : draft.dateOfBirth.trim(),
      heightCm: _number(draft.heightCm),
      weightKg: _number(draft.weightKg),
      maritalStatus: draft.maritalStatus.isEmpty ? null : draft.maritalStatus,
      bloodType: draft.bloodType.isEmpty ? null : draft.bloodType,
    );
    final key = _intents.retain(request.intentFingerprint);
    state = const OnboardingSubmitState(busy: true);
    try {
      final result = await ref
          .read(patientApiProvider)
          .onboard(request: request, idempotencyKey: key);
      _intents.retire();
      if (result.isManualReviewRequired) {
        ref.read(manualReviewProvider.notifier).markRequired();
        state = const OnboardingSubmitState();
        return PatientOnboardingStatus.manualReviewRequired;
      }
      await ref.read(ownProfileProvider.notifier).refresh();
      state = const OnboardingSubmitState();
      return PatientOnboardingStatus.profileReady;
    } on ApiFailure catch (failure) {
      if (_shouldRetire(failure)) {
        _intents.retire();
      }
      state = OnboardingSubmitState(
        error: failure.message,
        field: failure.field,
        requestId: failure.requestId,
      );
      return null;
    } catch (_) {
      state = const OnboardingSubmitState(error: 'failed');
      return null;
    }
  }

  bool _shouldRetire(ApiFailure failure) {
    return failure.isValidation ||
        failure.isAuthentication ||
        failure.code == ApiErrorCode.notFound ||
        failure.code == ApiErrorCode.permissionDenied;
  }

  double? _number(String raw) {
    if (raw.trim().isEmpty) {
      return null;
    }
    return double.tryParse(raw.trim());
  }
}

final onboardingSubmitProvider =
    NotifierProvider<OnboardingSubmitController, OnboardingSubmitState>(
      OnboardingSubmitController.new,
    );
