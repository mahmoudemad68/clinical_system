import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../session/session_controller.dart';
import 'own_profile_controller.dart';

enum PatientSurface {
  auth,
  resolving,
  unsupported,
  onboarding,
  manualReview,
  profile,
  loadError,
}

final patientSurfaceProvider = Provider<PatientSurface>((ref) {
  final session = ref.watch(sessionProvider);
  return session.when(
    loading: () => PatientSurface.resolving,
    error: (error, _) {
      if (isSessionFailure(error)) {
        return PatientSurface.auth;
      }
      return PatientSurface.loadError;
    },
    data: (identity) {
      if (identity == null) {
        return PatientSurface.auth;
      }
      if (!identity.isPatient) {
        return PatientSurface.unsupported;
      }
      final review = ref.watch(manualReviewProvider);
      final profile = ref.watch(ownProfileProvider);
      final found = profile.asData?.value;
      if (found is OwnProfileFound) {
        return PatientSurface.profile;
      }
      if (review) {
        return PatientSurface.manualReview;
      }
      return profile.when(
        loading: () => PatientSurface.resolving,
        error: (error, _) {
          if (isSessionFailure(error)) {
            return PatientSurface.auth;
          }
          return PatientSurface.loadError;
        },
        data: (lookup) {
          if (lookup is OwnProfileAbsent) {
            return PatientSurface.onboarding;
          }
          return PatientSurface.resolving;
        },
      );
    },
  );
});

PatientProfile? currentProfileOf(OwnProfileLookup? lookup) {
  if (lookup is OwnProfileFound) {
    return lookup.profile;
  }
  return null;
}
