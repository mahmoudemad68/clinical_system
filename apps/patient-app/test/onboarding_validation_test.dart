import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_patient_app/onboarding/onboarding_draft.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const strings = ClinicStrings(Locale('en'));

  test('rejects out-of-range height instead of clamping', () {
    final draft = OnboardingDraft()
      ..nationalId = '29901011234567'
      ..fullName = 'Ada'
      ..gender = 'female'
      ..heightCm = '400';
    final errors = validateMeasurementsStep(draft, strings);
    expect(errors['height_cm'], isNotNull);
    expect(draft.heightCm, '400');
  });

  test('rejects out-of-range weight instead of clamping', () {
    final draft = OnboardingDraft()
      ..nationalId = '29901011234567'
      ..fullName = 'Ada'
      ..gender = 'female'
      ..weightKg = '0.5';
    final errors = validateMeasurementsStep(draft, strings);
    expect(errors['weight_kg'], isNotNull);
  });

  test('requires identity fields before continuing', () {
    final draft = OnboardingDraft();
    final errors = validateIdentityStep(draft, strings);
    expect(errors['national_id'], isNotNull);
    expect(errors['full_name'], isNotNull);
  });

  test('fingerprint changes when the payload changes', () {
    final draft = OnboardingDraft()
      ..nationalId = '29901011234567'
      ..fullName = 'Ada'
      ..gender = 'female';
    final first = draft.fingerprint();
    draft.fullName = 'Grace';
    expect(draft.fingerprint(), isNot(first));
  });

  test('clearNationalId drops only the identifier', () {
    final draft = OnboardingDraft()
      ..nationalId = '29901011234567'
      ..fullName = 'Ada'
      ..gender = 'female';
    draft.clearNationalId();
    expect(draft.nationalId, isEmpty);
    expect(draft.fullName, 'Ada');
  });
}
