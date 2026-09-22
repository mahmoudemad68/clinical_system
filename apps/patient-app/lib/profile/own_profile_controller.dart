import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app_providers.dart';
import '../session/session_controller.dart';

class OwnProfileController extends AsyncNotifier<OwnProfileLookup?> {
  @override
  Future<OwnProfileLookup?> build() async {
    final identity = await ref.watch(sessionProvider.future);
    if (identity == null || !identity.isPatient) {
      return null;
    }
    return ref.read(patientApiProvider).getOwnProfile();
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }
}

final ownProfileProvider =
    AsyncNotifierProvider<OwnProfileController, OwnProfileLookup?>(
      OwnProfileController.new,
    );

class ManualReviewController extends Notifier<bool> {
  @override
  bool build() => false;

  void markRequired() => state = true;

  void clear() => state = false;
}

final manualReviewProvider = NotifierProvider<ManualReviewController, bool>(
  ManualReviewController.new,
);
