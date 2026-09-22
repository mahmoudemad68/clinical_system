import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:test/test.dart';

void main() {
  test('unknown lifecycle status is read-only', () {
    expect(
      PatientLifecycleStatus.fromWire('invented'),
      PatientLifecycleStatus.unknown,
    );
    expect(PatientLifecycleStatus.unknown.allowsDemographicEdit, isFalse);
    expect(PatientLifecycleStatus.active.allowsDemographicEdit, isTrue);
    expect(PatientLifecycleStatus.restricted.allowsDemographicEdit, isFalse);
  });

  test('patient profile has no national-id field', () {
    final profile = PatientProfile(
      patientId: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
      fullName: 'Synthetic Patient',
      gender: 'female',
      dateOfBirth: '1990-01-15',
      heightCm: '165.50',
      weightKg: '62.30',
      maritalStatus: 'single',
      bloodType: 'A+',
      status: PatientLifecycleStatus.active,
      version: 1,
      createdAt: DateTime.utc(2026, 9, 1),
      updatedAt: DateTime.utc(2026, 9, 1),
    );
    expect(profile.isEditable, isTrue);
    expect(profile.toString().toLowerCase(), isNot(contains('national')));
  });

  test('session identity uses server account_type', () {
    const identity = SessionIdentity(
      userId: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c02',
      accountType: 'doctor',
      status: 'active',
      language: 'en',
      assuranceLevel: 'aal1_password',
    );
    expect(identity.isPatientAccount, isFalse);
  });
}
