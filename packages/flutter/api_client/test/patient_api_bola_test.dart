import 'dart:io';

import 'package:test/test.dart';

void main() {
  test('PatientApi has no GET-by-patient-id helper', () {
    final source = File('lib/src/patient_api.dart').readAsStringSync();
    expect(source, contains('OpenApiPaths.getOwnPatientProfile'));
    expect(source, contains('/api/v1/patients/me/profile'));
    expect(source, isNot(contains('getById')));
    expect(source, isNot(contains('getPatient')));
    expect(source, isNot(contains('/patients/\${')));
    expect(source, isNot(contains('/patients/{')));
  });
}
