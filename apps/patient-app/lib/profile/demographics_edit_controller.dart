import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_error_handling/clinic_error_handling.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app_providers.dart';
import 'own_profile_controller.dart';

class DemographicsEditState {
  const DemographicsEditState({
    this.busy = false,
    this.conflict = false,
    this.error,
    this.field,
    this.requestId,
  });

  final bool busy;
  final bool conflict;
  final String? error;
  final String? field;
  final String? requestId;
}

class DemographicsEditController extends Notifier<DemographicsEditState> {
  @override
  DemographicsEditState build() => const DemographicsEditState();

  void reset() => state = const DemographicsEditState();

  Future<PatientProfile?> save(PatientDemographicsPatch patch) async {
    state = const DemographicsEditState(busy: true);
    try {
      final profile = await ref
          .read(patientApiProvider)
          .updateDemographics(patch);
      await ref.read(ownProfileProvider.notifier).refresh();
      state = const DemographicsEditState();
      return profile;
    } on ApiFailure catch (failure) {
      if (failure.code == ApiErrorCode.versionConflict) {
        state = DemographicsEditState(
          conflict: true,
          error: failure.message,
          requestId: failure.requestId,
        );
        return null;
      }
      state = DemographicsEditState(
        error: failure.message,
        field: failure.field,
        requestId: failure.requestId,
      );
      return null;
    }
  }
}

final demographicsEditProvider =
    NotifierProvider<DemographicsEditController, DemographicsEditState>(
      DemographicsEditController.new,
    );
