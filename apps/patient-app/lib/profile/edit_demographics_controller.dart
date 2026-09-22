import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../onboarding/onboarding_draft.dart';
import '../providers.dart';
import '../session/patient_session.dart';

class EditDemographicsViewState {
  const EditDemographicsViewState({
    this.profile,
    this.fullName = '',
    this.gender,
    this.dateOfBirth,
    this.heightCm = '',
    this.weightKg = '',
    this.maritalStatus,
    this.bloodType,
    this.errors = const FieldErrors({}),
    this.busy = false,
    this.conflict = false,
    this.failure,
    this.saved = false,
  });

  final PatientProfile? profile;
  final String fullName;
  final String? gender;
  final DateTime? dateOfBirth;
  final String heightCm;
  final String weightKg;
  final String? maritalStatus;
  final String? bloodType;
  final FieldErrors errors;
  final bool busy;
  final bool conflict;
  final ApiFailure? failure;
  final bool saved;

  bool get loaded => profile != null;

  EditDemographicsViewState copyWith({
    PatientProfile? profile,
    String? fullName,
    String? gender,
    DateTime? dateOfBirth,
    bool clearDateOfBirth = false,
    String? heightCm,
    String? weightKg,
    String? maritalStatus,
    bool clearMaritalStatus = false,
    String? bloodType,
    bool clearBloodType = false,
    FieldErrors? errors,
    bool? busy,
    bool? conflict,
    ApiFailure? failure,
    bool clearFailure = false,
    bool? saved,
  }) {
    return EditDemographicsViewState(
      profile: profile ?? this.profile,
      fullName: fullName ?? this.fullName,
      gender: gender ?? this.gender,
      dateOfBirth: clearDateOfBirth
          ? dateOfBirth
          : (dateOfBirth ?? this.dateOfBirth),
      heightCm: heightCm ?? this.heightCm,
      weightKg: weightKg ?? this.weightKg,
      maritalStatus: clearMaritalStatus
          ? maritalStatus
          : (maritalStatus ?? this.maritalStatus),
      bloodType: clearBloodType ? bloodType : (bloodType ?? this.bloodType),
      errors: errors ?? this.errors,
      busy: busy ?? this.busy,
      conflict: conflict ?? this.conflict,
      failure: clearFailure ? null : (failure ?? this.failure),
      saved: saved ?? this.saved,
    );
  }

  factory EditDemographicsViewState.fromProfile(PatientProfile profile) {
    return EditDemographicsViewState(
      profile: profile,
      fullName: profile.fullName,
      gender: profile.gender,
      dateOfBirth: _parseDate(profile.dateOfBirth),
      heightCm: profile.heightCm ?? '',
      weightKg: profile.weightKg ?? '',
      maritalStatus: profile.maritalStatus,
      bloodType: profile.bloodType,
    );
  }
}

DateTime? _parseDate(String? raw) {
  if (raw == null || raw.isEmpty) {
    return null;
  }
  return DateTime.tryParse(raw);
}

class EditDemographicsController extends Notifier<EditDemographicsViewState> {
  @override
  EditDemographicsViewState build() {
    ref.listen(patientSessionProvider, (previous, next) {
      if (next is PatientRouteUnauthenticated) {
        state = const EditDemographicsViewState();
      }
    });
    return const EditDemographicsViewState();
  }

  void load(PatientProfile profile) {
    state = EditDemographicsViewState.fromProfile(profile);
  }

  void reset() {
    state = const EditDemographicsViewState();
  }

  void setFullName(String value) {
    state = state.copyWith(fullName: value, saved: false, clearFailure: true);
  }

  void setGender(String? value) {
    state = state.copyWith(gender: value, saved: false, clearFailure: true);
  }

  void setDateOfBirth(DateTime? value) {
    state = state.copyWith(
      dateOfBirth: value,
      clearDateOfBirth: true,
      saved: false,
      clearFailure: true,
    );
  }

  void setHeight(String value) {
    state = state.copyWith(heightCm: value, saved: false, clearFailure: true);
  }

  void setWeight(String value) {
    state = state.copyWith(weightKg: value, saved: false, clearFailure: true);
  }

  void setMaritalStatus(String? value) {
    state = state.copyWith(
      maritalStatus: value,
      clearMaritalStatus: true,
      saved: false,
      clearFailure: true,
    );
  }

  void setBloodType(String? value) {
    state = state.copyWith(
      bloodType: value,
      clearBloodType: true,
      saved: false,
      clearFailure: true,
    );
  }

  Future<bool> save(ClinicStrings strings) async {
    final profile = state.profile;
    if (profile == null || state.conflict) {
      return false;
    }
    final errors = validateDemographicPatch(
      fullName: state.fullName,
      gender: state.gender,
      dateOfBirth: state.dateOfBirth,
      heightCm: state.heightCm,
      weightKg: state.weightKg,
      maritalStatus: state.maritalStatus,
      bloodType: state.bloodType,
      strings: strings,
    );
    if (!errors.isEmpty) {
      state = state.copyWith(errors: errors, saved: false);
      return false;
    }
    state = state.copyWith(
      busy: true,
      errors: const FieldErrors({}),
      clearFailure: true,
      saved: false,
    );
    try {
      final updated = await ref
          .read(patientApiProvider)
          .updateDemographics(
            version: profile.version,
            fullName: state.fullName.trim(),
            gender: state.gender,
            dateOfBirth: state.dateOfBirth == null
                ? null
                : isoDate(state.dateOfBirth!),
            heightCm: parseOptionalNumber(state.heightCm),
            weightKg: parseOptionalNumber(state.weightKg),
            maritalStatus: state.maritalStatus,
            bloodType: state.bloodType,
          );
      await ref.read(patientSessionProvider.notifier).replaceProfile(updated);
      state = EditDemographicsViewState.fromProfile(updated)
          .copyWith(saved: true);
      return true;
    } on ApiFailure catch (failure) {
      state = state.copyWith(
        busy: false,
        failure: failure,
        conflict: failure.code == ApiErrorCode.versionConflict,
        saved: false,
      );
      return false;
    }
  }

  Future<void> refresh() async {
    state = state.copyWith(busy: true);
    try {
      final latest = await ref.read(patientApiProvider).getOwnProfile();
      await ref.read(patientSessionProvider.notifier).replaceProfile(latest);
      state = EditDemographicsViewState.fromProfile(latest);
    } on ApiFailure catch (failure) {
      state = state.copyWith(busy: false, failure: failure);
    }
  }
}

final editDemographicsProvider =
    NotifierProvider<EditDemographicsController, EditDemographicsViewState>(
      EditDemographicsController.new,
    );
