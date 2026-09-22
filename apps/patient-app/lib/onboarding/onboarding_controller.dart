import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../providers.dart';
import 'onboarding_draft.dart';

class OnboardingViewState {
  const OnboardingViewState({
    required this.draft,
    this.errors = const FieldErrors({}),
    this.busy = false,
    this.failure,
  });

  final OnboardingDraft draft;
  final FieldErrors errors;
  final bool busy;
  final ApiFailure? failure;

  OnboardingViewState copyWith({
    OnboardingDraft? draft,
    FieldErrors? errors,
    bool? busy,
    ApiFailure? failure,
    bool clearFailure = false,
  }) {
    return OnboardingViewState(
      draft: draft ?? this.draft,
      errors: errors ?? this.errors,
      busy: busy ?? this.busy,
      failure: clearFailure ? null : (failure ?? this.failure),
    );
  }
}

class OnboardingController extends Notifier<OnboardingViewState> {
  @override
  OnboardingViewState build() => OnboardingViewState(draft: OnboardingDraft());

  void update(void Function(OnboardingDraft draft) change) {
    change(state.draft);
    state = state.copyWith(draft: state.draft, clearFailure: true);
  }

  void clearNationalId() {
    state.draft.clearNationalId();
    state = state.copyWith(draft: state.draft);
  }

  void reset() {
    state.draft.reset();
    ref.read(patientApiProvider).clearOnboardingIntent();
    state = OnboardingViewState(draft: state.draft);
  }

  bool continueFrom(int step, ClinicStrings strings) {
    final errors = switch (step) {
      0 => validateIdentityStep(state.draft, strings),
      1 => validateDemographicsStep(state.draft, strings),
      2 => validateMeasurementsStep(state.draft, strings),
      _ => const FieldErrors({}),
    };
    if (!errors.isEmpty) {
      state = state.copyWith(errors: errors);
      return false;
    }
    state.draft.step = (step + 1).clamp(0, 3);
    state = state.copyWith(
      draft: state.draft,
      errors: const FieldErrors({}),
      clearFailure: true,
    );
    return true;
  }

  void back() {
    if (state.draft.step == 0) {
      return;
    }
    state.draft.step -= 1;
    state = state.copyWith(draft: state.draft);
  }

  Future<PatientOnboardingResult?> submit(ClinicStrings strings) async {
    final errors = validateOnboarding(state.draft, strings);
    if (!errors.isEmpty) {
      state = state.copyWith(errors: errors);
      return null;
    }
    state = state.copyWith(busy: true, clearFailure: true);
    final draft = state.draft;
    try {
      final nationalId = draft.nationalId.trim();
      final result = await ref
          .read(patientApiProvider)
          .onboard(
            nationalId: nationalId,
            fullName: draft.fullName.trim(),
            gender: draft.gender!,
            dateOfBirth: draft.dateOfBirth == null
                ? null
                : isoDate(draft.dateOfBirth!),
            heightCm: parseOptionalNumber(draft.heightCm),
            weightKg: parseOptionalNumber(draft.weightKg),
            maritalStatus: draft.maritalStatus,
            bloodType: draft.bloodType,
          );
      draft.clearNationalId();
      state = state.copyWith(draft: draft, busy: false);
      return result;
    } on ApiFailure catch (failure) {
      final nationalId = draft.nationalId;
      final safe = failure.redacting(nationalId);
      if (safe.isValidation ||
          safe.isAuthentication ||
          safe.code == ApiErrorCode.notFound) {
        draft.clearNationalId();
      }
      state = state.copyWith(
        draft: draft,
        busy: false,
        failure: safe,
        errors: safe.field == null
            ? const FieldErrors({})
            : FieldErrors({safe.field!: safe.message}),
      );
      return null;
    }
  }
}

final onboardingProvider =
    NotifierProvider<OnboardingController, OnboardingViewState>(
      OnboardingController.new,
    );
