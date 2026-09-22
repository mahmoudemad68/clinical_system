import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:test/test.dart';

import 'synthetic_national_id.dart';

void main() {
  test('fromWire ignores national_id and does not expose it', () {
    final profile = PatientProfile.fromWire({
      'patient_id': 'abc',
      'full_name': 'Ada',
      'gender': 'female',
      'date_of_birth': null,
      'height_cm': '160.00',
      'weight_kg': null,
      'marital_status': null,
      'blood_type': null,
      'status': 'active',
      'version': 1,
      'created_at': '2026-09-01T00:00:00Z',
      'updated_at': '2026-09-01T00:00:00Z',
      'national_id': kSyntheticNationalId,
    });
    expect(profile.fullName, 'Ada');
    expect(profile.status, PatientProfileStatus.active);
    expect(profile.canEditDemographics, isTrue);
    expect(profile.toString(), isNot(contains(kSyntheticNationalId)));
  });

  test('unknown status is not editable', () {
    final profile = PatientProfile.fromWire({
      'patient_id': 'abc',
      'full_name': 'Ada',
      'gender': 'female',
      'date_of_birth': null,
      'height_cm': null,
      'weight_kg': null,
      'marital_status': null,
      'blood_type': null,
      'status': 'future_state',
      'version': 3,
      'created_at': '2026-09-01T00:00:00Z',
      'updated_at': '2026-09-01T00:00:00Z',
    });
    expect(profile.status, PatientProfileStatus.unknown);
    expect(profile.canEditDemographics, isFalse);
  });

  test('manual_review_required compact result omits identifiers', () {
    final result = PatientOnboardingResult.fromWire({
      'status': 'manual_review_required',
      'patient_id': 'should-ignore',
    });
    expect(result.isManualReviewRequired, isTrue);
    expect(result.patientId, isNull);
  });

  test('onboarding request toString does not echo National ID', () {
    const request = PatientOnboardingRequest(
      nationalId: kSyntheticNationalId,
      fullName: 'Ada',
      gender: 'female',
    );
    expect(request.toString(), isNot(contains(kSyntheticNationalId)));
    expect(request.toWire()['national_id'], kSyntheticNationalId);
  });
}
