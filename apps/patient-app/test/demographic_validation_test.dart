import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_patient_app/onboarding/demographic_validation.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final now = DateTime.utc(2026, 9, 22);

  test('required identity fields', () {
    expect(DemographicValidation.nationalId(''), 'required');
    expect(DemographicValidation.fullName(''), 'required');
    expect(DemographicValidation.gender(null), 'required');
  });

  test('does not clamp out-of-range height or weight', () {
    expect(DemographicValidation.heightCm('10'), 'bounds');
    expect(DemographicValidation.heightCm('400'), 'bounds');
    expect(DemographicValidation.weightKg('0.5'), 'bounds');
    expect(DemographicValidation.weightKg('800'), 'bounds');
    expect(DemographicValidation.heightCm('165.5'), isNull);
    expect(DemographicValidation.parseOptionalNumber('165.5'), 165.5);
  });

  test('date format and engineering bounds', () {
    expect(
      DemographicValidation.dateOfBirth('15-01-1990', nowUtc: now),
      'date',
    );
    expect(
      DemographicValidation.dateOfBirth('1849-12-31', nowUtc: now),
      'date',
    );
    expect(
      DemographicValidation.dateOfBirth('2099-01-01', nowUtc: now),
      'date',
    );
    expect(
      DemographicValidation.dateOfBirth('1990-01-15', nowUtc: now),
      isNull,
    );
    expect(DemographicValidation.dateOfBirth('', nowUtc: now), isNull);
  });

  test('closed enums only', () {
    expect(DemographicValidation.gender('other'), 'enum');
    expect(DemographicValidation.maritalStatus('civil_union'), 'enum');
    expect(DemographicValidation.bloodType('A'), 'enum');
    expect(DemographicValidation.bloodType('A+'), isNull);
    expect(PatientBloodType.tryParse('A+')?.wire, 'A+');
  });
}
