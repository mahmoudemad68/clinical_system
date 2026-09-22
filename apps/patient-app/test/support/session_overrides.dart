import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_patient_app/app_providers.dart';
import 'package:clinic_patient_app/profile/own_profile_controller.dart';
import 'package:clinic_patient_app/session/session_controller.dart';
import 'package:flutter_riverpod/misc.dart';

import 'harness.dart';

class SeededPatientSession extends SessionController {
  @override
  Future<IdentitySnapshot?> build() async {
    return IdentitySnapshot.fromWire(identityWire());
  }
}

class AbsentOwnProfile extends OwnProfileController {
  @override
  Future<OwnProfileLookup?> build() async => const OwnProfileAbsent();
}

class ManualReviewOn extends ManualReviewController {
  @override
  bool build() => true;
}

class ReadyOwnProfile extends OwnProfileController {
  ReadyOwnProfile({
    this.name = 'Server Name',
    this.version = 1,
    this.status = 'active',
  });

  final String name;
  final int version;
  final String status;

  @override
  Future<OwnProfileLookup?> build() async {
    return OwnProfileFound(
      PatientProfile.fromWire(
        profileWire(name: name, version: version, status: status),
      ),
    );
  }
}

List<Override> seededPatientOverrides({
  required ClinicHttpClient client,
  required TokenStore tokens,
  OwnProfileController Function()? profile,
  bool manualReview = false,
  bool liveProfile = false,
}) {
  return [
    httpClientProvider.overrideWithValue(client),
    tokenStoreProvider.overrideWithValue(tokens),
    healthProvider.overrideWith((ref) async => testHealth()),
    sessionProvider.overrideWith(SeededPatientSession.new),
    if (!liveProfile)
      ownProfileProvider.overrideWith(profile ?? AbsentOwnProfile.new),
    if (manualReview) manualReviewProvider.overrideWith(ManualReviewOn.new),
  ];
}
